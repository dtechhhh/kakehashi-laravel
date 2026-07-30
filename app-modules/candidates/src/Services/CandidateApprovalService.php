<?php

namespace Modules\Candidates\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W3-T4 — decide CANDIDATE_NEW via pending foundation; Maker cannot self-approve.
 *
 * Revision merge (CANDIDATE_REVISION) is W3-T5. Approver only approve/reject — no edit.
 */
final class CandidateApprovalService
{
    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{version?: int|string}  $options
     */
    public function approve(User $actor, int $pendingRequestId, array $options = []): object
    {
        return $this->decide($actor, $pendingRequestId, approved: true, note: null, options: $options);
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function reject(User $actor, int $pendingRequestId, string $note, array $options = []): object
    {
        return $this->decide($actor, $pendingRequestId, approved: false, note: $note, options: $options);
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function decide(
        User $actor,
        int $pendingRequestId,
        bool $approved,
        ?string $note,
        array $options,
    ): object {
        $this->authorizeReview($actor);

        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use (
            $actor,
            $pendingRequestId,
            $approved,
            $note,
            $expectedVersion,
        ): object {
            $request = PendingRequest::query()->find($pendingRequestId);
            if ($request === null) {
                throw new NotFoundHttpException('CANDIDATE_PENDING_NOT_FOUND');
            }

            $this->assertCandidateNewPending($request);

            $candidateId = (int) $request->target_id;
            $row = DB::table('candidate')->where('id', $candidateId)->first();
            if ($row === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            $this->assertApprovableMain($row);

            if ($expectedVersion !== (int) $row->version) {
                throw new ConflictHttpException('CONFLICT');
            }

            $checkerId = (int) $actor->getKey();
            $auditDetail = [
                'status_before' => (string) $row->status_approval,
                'version' => $expectedVersion,
                'nomor_induk' => $row->nomor_induk,
            ];

            if ($approved) {
                $decided = $this->pending->approve(
                    requestId: $pendingRequestId,
                    checkerId: $checkerId,
                    auditAction: ActionType::CANDIDATE_APPROVED,
                    auditDetail: $auditDetail,
                );
                $newStatus = CandidateApprovalStatus::Disetujui->value;
                $update = [
                    'status_approval' => $newStatus,
                    'approved_by' => $checkerId,
                    'catatan_penolakan_terakhir' => null,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ];
                $action = ActionType::CANDIDATE_APPROVED;
            } else {
                // Domain audit fields for CANDIDATE_REJECTED (PRD Lamp. A); trim matches foundation note_checker.
                $trimmedNote = trim((string) $note);
                $auditDetail['candidate_id'] = $candidateId;
                $auditDetail['reason'] = $trimmedNote;

                $decided = $this->pending->reject(
                    requestId: $pendingRequestId,
                    checkerId: $checkerId,
                    note: $trimmedNote,
                    auditAction: ActionType::CANDIDATE_REJECTED,
                    auditDetail: $auditDetail,
                );
                $newStatus = CandidateApprovalStatus::Ditolak->value;
                $update = [
                    'status_approval' => $newStatus,
                    'catatan_penolakan_terakhir' => $decided->note_checker,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ];
                $action = ActionType::CANDIDATE_REJECTED;
            }

            $affected = DB::table('candidate')
                ->where('id', $candidateId)
                ->where('version', $expectedVersion)
                ->where('status_approval', CandidateApprovalStatus::MenungguTinjauanBaru->value)
                ->whereNull('deleted_at')
                ->whereNull('pii_anonymized_at')
                ->update($update);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $fresh = DB::table('candidate')->where('id', $candidateId)->first();
            if ($fresh === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            if ($fresh->status_approval !== $newStatus) {
                throw new \RuntimeException('Approval invariant violated: candidate status not updated.');
            }

            if ($approved
                && ((int) $fresh->approved_by !== $checkerId
                    || (int) $fresh->approved_by === (int) $fresh->created_by)
            ) {
                throw new \RuntimeException('Approval invariant violated: approved_by SoD.');
            }

            $this->notifyMaker((int) $request->requested_by, $action, [
                'candidate_id' => $candidateId,
                'pending_request_id' => $pendingRequestId,
                'pending_type' => PendingType::CANDIDATE_NEW->value,
                'status_approval' => $newStatus,
            ]);

            return $fresh;
        });
    }

    private function authorizeReview(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.review');
    }

    private function assertCandidateNewPending(PendingRequest $request): void
    {
        if ($request->type === PendingType::CANDIDATE_REVISION) {
            $this->fail('type', 'CANDIDATE_REVISION_OUT_OF_SCOPE');
        }

        if ($request->type !== PendingType::CANDIDATE_NEW
            || $request->target_type !== 'candidate'
        ) {
            $this->fail('type', 'CANDIDATE_PENDING_TYPE');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }
    }

    private function assertApprovableMain(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_APPROVABLE');
        }

        if ($row->parent_candidate_id !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_MAIN');
        }

        if ($row->status_approval !== CandidateApprovalStatus::MenungguTinjauanBaru->value) {
            $this->fail('status_approval', 'CANDIDATE_NOT_WAITING');
        }

        if ($row->nomor_induk === null || trim((string) $row->nomor_induk) === '') {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_REQUIRED');
        }
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function requireVersion(array $options): int
    {
        $expectedVersion = $options['version'] ?? null;
        if (! is_int($expectedVersion) && ! (is_string($expectedVersion) && ctype_digit((string) $expectedVersion))) {
            $this->fail('version', 'CANDIDATE_VERSION_REQUIRED');
        }

        return (int) $expectedVersion;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyMaker(int $userId, ActionType $action, array $payload): void
    {
        $this->notifications->notifyInApp([$userId], $action->value, $payload);
        $this->notifications->queueEmailAfterCommit([$userId], $action->value, $payload);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
