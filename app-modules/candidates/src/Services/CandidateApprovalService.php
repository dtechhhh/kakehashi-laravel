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
 * W3-T4/T5/FIX1 — decide CANDIDATE_NEW and CANDIDATE_REVISION via pending foundation.
 *
 * Maker cannot self-approve. Approver only approve/reject — no edit.
 * Revision merge is atomic; main stays Disetujui; NIK/availability preserved.
 * parent_version from pending payload is enforced (no live-version fallback).
 */
final class CandidateApprovalService
{
    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly NotificationService $notifications,
        private readonly CandidateRevisionService $revisions,
    ) {}

    /**
     * @param  array{version?: int|string}  $options  target candidate/revision version
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

            $this->assertCandidatePending($request);

            if ($request->type === PendingType::CANDIDATE_REVISION) {
                return $this->decideRevision(
                    $actor,
                    $request,
                    $approved,
                    $note,
                    $expectedVersion,
                );
            }

            return $this->decideNew(
                $actor,
                $request,
                $approved,
                $note,
                $expectedVersion,
            );
        });
    }

    private function decideNew(
        User $actor,
        PendingRequest $request,
        bool $approved,
        ?string $note,
        int $expectedVersion,
    ): object {
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
            $this->pending->approve(
                requestId: (int) $request->getKey(),
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
            $trimmedNote = trim((string) $note);
            $auditDetail['candidate_id'] = $candidateId;
            $auditDetail['reason'] = $trimmedNote;

            $decided = $this->pending->reject(
                requestId: (int) $request->getKey(),
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
            'pending_request_id' => (int) $request->getKey(),
            'pending_type' => PendingType::CANDIDATE_NEW->value,
            'status_approval' => $newStatus,
        ]);

        return $fresh;
    }

    private function decideRevision(
        User $actor,
        PendingRequest $request,
        bool $approved,
        ?string $note,
        int $expectedVersion,
    ): object {
        $revisionId = (int) $request->target_id;
        $revision = DB::table('candidate')->where('id', $revisionId)->first();
        if ($revision === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        $this->assertApprovableRevision($revision);

        if ($expectedVersion !== (int) $revision->version) {
            throw new ConflictHttpException('CONFLICT');
        }

        [$parentId, $parentVersion] = $this->requireRevisionPayload($request);

        if ($parentId !== (int) $revision->parent_candidate_id) {
            throw new ConflictHttpException('CONFLICT');
        }

        $main = DB::table('candidate')->where('id', $parentId)->first();
        if ($main === null) {
            $this->fail('candidate', 'CANDIDATE_MAIN_NOT_FOUND');
        }

        if ($main->pii_anonymized_at !== null || $main->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_APPROVABLE');
        }

        if ($main->parent_candidate_id !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_MAIN');
        }

        if ($main->status_approval !== CandidateApprovalStatus::Disetujui->value) {
            $this->fail('status_approval', 'CANDIDATE_MAIN_NOT_APPROVED');
        }

        if ($main->nomor_induk === null || trim((string) $main->nomor_induk) === '') {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_REQUIRED');
        }

        // Stale parent: 409 before any pending/main/revision/child/audit/notification mutation.
        if ((int) $main->version !== $parentVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        $checkerId = (int) $actor->getKey();
        $mainNik = (string) $main->nomor_induk;
        $mainAvailability = (string) $main->status_ketersediaan;
        $mainApprovedBy = $main->approved_by;
        $mainCreatedBy = $main->created_by;

        $auditDetail = [
            'status_before' => (string) $revision->status_approval,
            'version' => $expectedVersion,
            'revision_id' => $revisionId,
            'parent_candidate_id' => $parentId,
            'parent_version' => $parentVersion,
            'nomor_induk' => $mainNik,
        ];

        if ($approved) {
            $this->pending->approve(
                requestId: (int) $request->getKey(),
                checkerId: $checkerId,
                auditAction: ActionType::CANDIDATE_APPROVED,
                auditDetail: $auditDetail,
            );

            $mutable = $this->revisions->mutableSnapshot($revision);

            // Enforce payload parent_version only — no fallback to a live re-read.
            $mainAffected = DB::table('candidate')
                ->where('id', $parentId)
                ->where('version', $parentVersion)
                ->where('status_approval', CandidateApprovalStatus::Disetujui->value)
                ->where('nomor_induk', $mainNik)
                ->where('status_ketersediaan', $mainAvailability)
                ->whereNull('parent_candidate_id')
                ->whereNull('deleted_at')
                ->whereNull('pii_anonymized_at')
                ->update([
                    ...$mutable,
                    'nomor_induk' => $mainNik,
                    'status_ketersediaan' => $mainAvailability,
                    'status_approval' => CandidateApprovalStatus::Disetujui->value,
                    'approved_by' => $mainApprovedBy,
                    'created_by' => $mainCreatedBy,
                    'parent_candidate_id' => null,
                    'version' => $parentVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($mainAffected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->revisions->replaceChildrenFrom($revisionId, $parentId);

            $revAffected = DB::table('candidate')
                ->where('id', $revisionId)
                ->where('version', $expectedVersion)
                ->where('status_approval', CandidateApprovalStatus::MenungguTinjauanRevisi->value)
                ->where('parent_candidate_id', $parentId)
                ->whereNull('nomor_induk')
                ->update([
                    'status_approval' => CandidateApprovalStatus::Diterapkan->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($revAffected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $freshMain = DB::table('candidate')->where('id', $parentId)->first();
            $freshRev = DB::table('candidate')->where('id', $revisionId)->first();
            if ($freshMain === null || $freshRev === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            if ($freshMain->status_approval !== CandidateApprovalStatus::Disetujui->value
                || (string) $freshMain->nomor_induk !== $mainNik
                || (string) $freshMain->status_ketersediaan !== $mainAvailability
                || $freshRev->status_approval !== CandidateApprovalStatus::Diterapkan->value
            ) {
                throw new \RuntimeException('Revision merge invariant violated.');
            }

            // After main + children + revision updated — failure here rolls back all.
            $this->notifyMaker((int) $request->requested_by, ActionType::CANDIDATE_APPROVED, [
                'candidate_id' => $parentId,
                'revision_id' => $revisionId,
                'pending_request_id' => (int) $request->getKey(),
                'pending_type' => PendingType::CANDIDATE_REVISION->value,
                'status_approval' => CandidateApprovalStatus::Disetujui->value,
            ]);

            return $freshMain;
        }

        $trimmedNote = trim((string) $note);
        $auditDetail['candidate_id'] = $parentId;
        $auditDetail['reason'] = $trimmedNote;

        $decided = $this->pending->reject(
            requestId: (int) $request->getKey(),
            checkerId: $checkerId,
            note: $trimmedNote,
            auditAction: ActionType::CANDIDATE_REJECTED,
            auditDetail: $auditDetail,
        );

        $revAffected = DB::table('candidate')
            ->where('id', $revisionId)
            ->where('version', $expectedVersion)
            ->where('status_approval', CandidateApprovalStatus::MenungguTinjauanRevisi->value)
            ->where('parent_candidate_id', $parentId)
            ->whereNull('nomor_induk')
            ->update([
                'status_approval' => CandidateApprovalStatus::Ditolak->value,
                'catatan_penolakan_terakhir' => $decided->note_checker,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

        if ($revAffected !== 1) {
            throw new ConflictHttpException('CONFLICT');
        }

        $freshMain = DB::table('candidate')->where('id', $parentId)->first();
        $freshRev = DB::table('candidate')->where('id', $revisionId)->first();
        if ($freshMain === null || $freshRev === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        if ((string) $freshMain->nomor_induk !== $mainNik
            || $freshMain->status_approval !== CandidateApprovalStatus::Disetujui->value
            || (int) $freshMain->version !== $parentVersion
            || $freshRev->status_approval !== CandidateApprovalStatus::Ditolak->value
        ) {
            throw new \RuntimeException('Revision reject invariant violated: main must stay unchanged.');
        }

        $this->notifyMaker((int) $request->requested_by, ActionType::CANDIDATE_REJECTED, [
            'candidate_id' => $parentId,
            'revision_id' => $revisionId,
            'pending_request_id' => (int) $request->getKey(),
            'pending_type' => PendingType::CANDIDATE_REVISION->value,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
        ]);

        return $freshRev;
    }

    /**
     * @return array{0: int, 1: int} parent_candidate_id, parent_version
     */
    private function requireRevisionPayload(PendingRequest $request): array
    {
        $payload = $request->payload;
        if (! is_array($payload)) {
            $this->fail('payload', 'CANDIDATE_REVISION_PAYLOAD');
        }

        $parentId = $payload['parent_candidate_id'] ?? null;
        $parentVersion = $payload['parent_version'] ?? null;

        if (! $this->isNonNegativeInt($parentId)) {
            $this->fail('payload.parent_candidate_id', 'CANDIDATE_REVISION_PAYLOAD');
        }
        if (! $this->isNonNegativeInt($parentVersion)) {
            $this->fail('payload.parent_version', 'CANDIDATE_REVISION_PAYLOAD');
        }

        $fingerprint = $payload['aggregate_fingerprint'] ?? null;
        if (! is_string($fingerprint) || strlen($fingerprint) !== 64 || ! ctype_xdigit($fingerprint)) {
            $this->fail('payload.aggregate_fingerprint', 'CANDIDATE_REVISION_PAYLOAD');
        }

        return [(int) $parentId, (int) $parentVersion];
    }

    private function isNonNegativeInt(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        return is_string($value) && $value !== '' && ctype_digit($value);
    }

    private function authorizeReview(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.review');
    }

    private function assertCandidatePending(PendingRequest $request): void
    {
        if ($request->target_type !== 'candidate') {
            $this->fail('type', 'CANDIDATE_PENDING_TYPE');
        }

        if ($request->type !== PendingType::CANDIDATE_NEW
            && $request->type !== PendingType::CANDIDATE_REVISION
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

    private function assertApprovableRevision(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_APPROVABLE');
        }

        if ($row->parent_candidate_id === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_REVISION');
        }

        if ($row->status_approval !== CandidateApprovalStatus::MenungguTinjauanRevisi->value) {
            $this->fail('status_approval', 'CANDIDATE_NOT_WAITING');
        }

        if ($row->nomor_induk !== null) {
            $this->fail('nomor_induk', 'CANDIDATE_NIK_ALREADY_SET');
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
