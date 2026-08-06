<?php

namespace Modules\Placement\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Placement\Enums\PlacementContainerStatus;
use Modules\Placement\Enums\PlacementParticipantStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W5-T6/T7 — lifecycle `status_penempatan` per participant: Selesai Kontrak
 * (langsung), Mengundurkan Diri (approval rutin), Dikeluarkan (approval +
 * step-up, alasan dua lapis). Terminal → markAvailable + cek arsip setelah
 * batch (sinkron, in-transaction).
 */
final class PlacementParticipationService
{
    private const TARGET_TYPE = 'placement_container';

    private const PARTICIPANT_TYPE = 'placement_participants';

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly CandidateAvailabilityService $availability,
        private readonly StepUpService $stepUp,
    ) {}

    /**
     * Jalur 1 — langsung efektif, tanpa approval dan tanpa catatan.
     *
     * @param  array{version?: int|string}  $options
     */
    public function completeContract(User $actor, int $participantId, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $participantId, $expectedVersion): object {
            $row = $this->workingParticipant($participantId, $expectedVersion);

            $candidateVersion = $this->availability->currentVersion((int) $row->candidate_id);
            $this->availability->assertInUse((int) $row->candidate_id, $candidateVersion);
            $this->availability->markAvailable((int) $row->candidate_id, $candidateVersion);

            $this->transition($row, PlacementParticipantStatus::CONTRACT_COMPLETED, $expectedVersion, null, $actor);

            return $this->afterTerminal($row, $actor);
        });
    }

    /**
     * Jalur 2 — request Mengundurkan Diri; participant tetap Bekerja sampai
     * disetujui.
     *
     * @param  array{version?: int|string}  $options
     */
    public function requestResign(User $actor, int $participantId, string $reasonMaker, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);
        $reasonMaker = $this->requiredReason($reasonMaker);

        return DB::transaction(function () use ($actor, $participantId, $expectedVersion, $reasonMaker): object {
            $row = $this->workingParticipant($participantId, $expectedVersion);

            $this->pending->submit(
                type: PendingType::PLACEMENT_RESIGN,
                targetType: self::PARTICIPANT_TYPE,
                targetId: $participantId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::RESIGN_REQUESTED,
                reasonMaker: $reasonMaker,
                payload: ['snapshot' => $this->snapshot($row)],
                auditDetail: [
                    'placement_container_id' => (int) $row->placement_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_before' => $row->status_penempatan,
                    'version' => $expectedVersion,
                ],
            );

            $this->notifyReviewers(ActionType::RESIGN_REQUESTED, [
                'placement_container_id' => (int) $row->placement_container_id,
                'participant_id' => $participantId,
            ]);

            return $row;
        });
    }

    /**
     * Approval rutin (tanpa step-up) — terminal.
     *
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     */
    public function approveResign(
        User $actor,
        int $pendingRequestId,
        string|array|null $noteChecker = null,
        array $options = [],
    ): object {
        [$noteChecker, $options] = $this->decisionArguments($noteChecker, $options);
        $this->authorizeReview($actor);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->pendingRequest($pendingRequestId, PendingType::PLACEMENT_RESIGN);
            $snapshot = $this->requestSnapshot($request, PendingType::PLACEMENT_RESIGN);
            $row = $this->participantBySnapshot($snapshot, $options);

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::RESIGN_APPROVED,
                note: $noteChecker,
                auditDetail: [
                    'placement_container_id' => (int) $row->placement_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_after' => PlacementParticipantStatus::WITHDRAWN->value,
                    'version' => (int) $snapshot['version'],
                ],
            );

            $this->releaseCandidate((int) $row->candidate_id);
            $this->transition($row, PlacementParticipantStatus::WITHDRAWN, (int) $snapshot['version'], $snapshot['reason_maker'], $actor);
            $this->notifyMaker((int) $request->requested_by, ActionType::RESIGN_APPROVED, $row);

            return $this->afterTerminal($row, $actor);
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function rejectResign(
        User $actor,
        int $pendingRequestId,
        string $noteChecker,
        array $options = [],
    ): object {
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->pendingRequest($pendingRequestId, PendingType::PLACEMENT_RESIGN);
            $snapshot = $this->requestSnapshot($request, PendingType::PLACEMENT_RESIGN);
            $this->assertOptionalVersion($snapshot, $options);

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $noteChecker,
                auditAction: ActionType::RESIGN_REJECTED,
                auditDetail: [
                    'placement_container_id' => (int) $snapshot['placement_container_id'],
                    'candidate_id' => (int) $snapshot['candidate_id'],
                    'version' => (int) $snapshot['version'],
                ],
            );

            $row = DB::table(self::PARTICIPANT_TYPE)->where('id', (int) $snapshot['participant_id'])->first();
            if ($row !== null) {
                $this->notifyMaker((int) $request->requested_by, ActionType::RESIGN_REJECTED, $row);

                return $row;
            }

            return $request->refresh();
        });
    }

    /**
     * Jalur 3 — request Dikeluarkan; participant tetap Bekerja sampai
     * disetujui dengan step-up.
     *
     * @param  array{version?: int|string}  $options
     */
    public function requestExpel(User $actor, int $participantId, string $reasonMaker, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);
        $reasonMaker = $this->requiredReason($reasonMaker);

        return DB::transaction(function () use ($actor, $participantId, $expectedVersion, $reasonMaker): object {
            $row = $this->workingParticipant($participantId, $expectedVersion);

            $this->pending->submit(
                type: PendingType::PLACEMENT_EXPEL,
                targetType: self::PARTICIPANT_TYPE,
                targetId: $participantId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::PLACEMENT_EXPEL_REQUESTED,
                reasonMaker: $reasonMaker,
                payload: ['snapshot' => $this->snapshot($row)],
                auditDetail: [
                    'placement_container_id' => (int) $row->placement_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_before' => $row->status_penempatan,
                    'version' => $expectedVersion,
                ],
            );

            $this->notifyReviewers(ActionType::PLACEMENT_EXPEL_REQUESTED, [
                'placement_container_id' => (int) $row->placement_container_id,
                'participant_id' => $participantId,
            ]);

            return $row;
        });
    }

    /**
     * Approval + step-up wajib; catatan checker = lapis kedua (justifikasi).
     *
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     */
    public function approveExpel(
        User $actor,
        int $pendingRequestId,
        string|array|null $noteChecker = null,
        array $options = [],
    ): object {
        [$noteChecker, $options] = $this->decisionArguments($noteChecker, $options);
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->pendingRequest($pendingRequestId, PendingType::PLACEMENT_EXPEL);
            $snapshot = $this->requestSnapshot($request, PendingType::PLACEMENT_EXPEL);
            $row = $this->participantBySnapshot($snapshot, $options);

            $this->stepUp->require(
                StepUpAction::APPROVE_CANDIDATE_EXPEL,
                self::PARTICIPANT_TYPE,
                (int) $row->id,
            );

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::PLACEMENT_EXPEL_APPROVED,
                note: $noteChecker,
                auditDetail: [
                    'placement_container_id' => (int) $row->placement_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_after' => PlacementParticipantStatus::EXPELLED->value,
                    'version' => (int) $snapshot['version'],
                ],
            );

            $this->releaseCandidate((int) $row->candidate_id);
            $this->transition($row, PlacementParticipantStatus::EXPELLED, (int) $snapshot['version'], $snapshot['reason_maker'], $actor);
            $this->notifyMaker((int) $request->requested_by, ActionType::PLACEMENT_EXPEL_APPROVED, $row);

            return $this->afterTerminal($row, $actor);
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function rejectExpel(
        User $actor,
        int $pendingRequestId,
        string $noteChecker,
        array $options = [],
    ): object {
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->pendingRequest($pendingRequestId, PendingType::PLACEMENT_EXPEL);
            $snapshot = $this->requestSnapshot($request, PendingType::PLACEMENT_EXPEL);
            $this->assertOptionalVersion($snapshot, $options);

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $noteChecker,
                auditAction: ActionType::PLACEMENT_EXPEL_REJECTED,
                auditDetail: [
                    'placement_container_id' => (int) $snapshot['placement_container_id'],
                    'candidate_id' => (int) $snapshot['candidate_id'],
                    'version' => (int) $snapshot['version'],
                ],
            );

            $row = DB::table(self::PARTICIPANT_TYPE)->where('id', (int) $snapshot['participant_id'])->first();
            if ($row !== null) {
                $this->notifyMaker((int) $request->requested_by, ActionType::PLACEMENT_EXPEL_REJECTED, $row);

                return $row;
            }

            return $request->refresh();
        });
    }

    /**
     * Terminal reached → release candidate, then archive the container when
     * the last Bekerja row is gone (checked after the whole transition).
     */
    private function afterTerminal(object $row, User $actor): object
    {
        $this->maybeArchiveContainer((int) $row->placement_container_id);

        return DB::table(self::PARTICIPANT_TYPE)->where('id', (int) $row->id)->firstOrFail();
    }

    /**
     * Sinkron auto-archive (MODULE_PLACEMENT §13): Aktif → Arsip ketika tidak
     * ada lagi participant Bekerja dan kontainer pernah punya kandidat.
     * Idempoten via guard transisi (hanya dari Aktif).
     */
    public function maybeArchiveContainer(int $containerId): bool
    {
        $working = DB::table(self::PARTICIPANT_TYPE)
            ->where('placement_container_id', $containerId)
            ->where('status_penempatan', PlacementParticipantStatus::WORKING->value)
            ->exists();

        if ($working) {
            return false;
        }

        $hasParticipants = DB::table(self::PARTICIPANT_TYPE)
            ->where('placement_container_id', $containerId)
            ->exists();

        if (! $hasParticipants) {
            return false;
        }

        $affected = DB::table('placement_container')
            ->where('id', $containerId)
            ->where('status', PlacementContainerStatus::ACTIVE->value)
            ->update([
                'status' => PlacementContainerStatus::ARCHIVED->value,
                'archived_at' => now(),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return false;
        }

        $this->audit->record(
            actionType: ActionType::CONTAINER_ARCHIVED,
            entityType: self::TARGET_TYPE,
            entityId: $containerId,
            detail: ['status_after' => PlacementContainerStatus::ARCHIVED->value],
        );

        return true;
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function workingParticipant(int $participantId, int $expectedVersion): object
    {
        $row = DB::table(self::PARTICIPANT_TYPE)->where('id', $participantId)->first();
        if ($row === null) {
            throw new NotFoundHttpException('PLACEMENT_PARTICIPANT_NOT_FOUND');
        }

        if ($row->status_penempatan !== PlacementParticipantStatus::WORKING->value) {
            $this->fail('status_penempatan', 'PLACEMENT_NOT_WORKING');
        }

        if ((int) $row->version !== $expectedVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        $container = DB::table('placement_container')->where('id', (int) $row->placement_container_id)->first();
        if ($container === null || $container->status !== PlacementContainerStatus::ACTIVE->value) {
            $this->fail('container', 'PC_NOT_ACTIVE');
        }

        return $row;
    }

    private function pendingRequest(int $pendingRequestId, PendingType $type): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== $type
            || $request->target_type !== self::PARTICIPANT_TYPE
        ) {
            throw new ConflictHttpException('PLACEMENT_PENDING_INVALID');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function snapshot(object $row): array
    {
        return [
            'participant_id' => (int) $row->id,
            'placement_container_id' => (int) $row->placement_container_id,
            'candidate_id' => (int) $row->candidate_id,
            'status_penempatan' => $row->status_penempatan,
            'version' => (int) $row->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSnapshot(PendingRequest $request, PendingType $type): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || (int) ($snapshot['participant_id'] ?? 0) !== (int) $request->target_id
            || ($snapshot['status_penempatan'] ?? null) !== PlacementParticipantStatus::WORKING->value
            || ! isset($snapshot['placement_container_id'], $snapshot['candidate_id'], $snapshot['version'])
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        $snapshot['reason_maker'] = $request->reason_maker;

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{version?: int|string}  $options
     */
    private function participantBySnapshot(array $snapshot, array $options): object
    {
        $row = DB::table(self::PARTICIPANT_TYPE)->where('id', (int) $snapshot['participant_id'])->first();
        if ($row === null) {
            throw new NotFoundHttpException('PLACEMENT_PARTICIPANT_NOT_FOUND');
        }

        $container = DB::table('placement_container')->where('id', (int) $snapshot['placement_container_id'])->first();
        if ($container === null || $container->status !== PlacementContainerStatus::ACTIVE->value) {
            $this->fail('container', 'PC_NOT_ACTIVE');
        }

        if ($row->status_penempatan !== $snapshot['status_penempatan']
            || (int) $row->version !== (int) $snapshot['version']
            || (int) $row->candidate_id !== (int) $snapshot['candidate_id']
            || (int) $row->placement_container_id !== (int) $snapshot['placement_container_id']
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (array_key_exists('version', $options)
            && $this->requireVersion($options) !== (int) $snapshot['version']
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array{version?: int|string}  $options
     */
    private function assertOptionalVersion(array $snapshot, array $options): void
    {
        if (array_key_exists('version', $options)
            && $this->requireVersion($options) !== (int) $snapshot['version']
        ) {
            throw new ConflictHttpException('CONFLICT');
        }
    }

    private function releaseCandidate(int $candidateId): void
    {
        $candidateVersion = $this->availability->currentVersion($candidateId);
        $this->availability->assertInUse($candidateId, $candidateVersion);
        $this->availability->markAvailable($candidateId, $candidateVersion);
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function transition(
        object $row,
        PlacementParticipantStatus $terminal,
        int $expectedVersion,
        ?string $catatan,
        User $actor,
    ): void {
        $affected = DB::table(self::PARTICIPANT_TYPE)
            ->where('id', (int) $row->id)
            ->where('version', $expectedVersion)
            ->where('status_penempatan', PlacementParticipantStatus::WORKING->value)
            ->update([
                'status_penempatan' => $terminal->value,
                'tanggal_status_final' => now()->toDateString(),
                'catatan_alasan' => $catatan,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new ConflictHttpException('CONFLICT');
        }

        $this->audit->record(
            actionType: $terminal === PlacementParticipantStatus::CONTRACT_COMPLETED
                ? ActionType::PLACEMENT_STATUS_CHANGED
                : ($terminal === PlacementParticipantStatus::WITHDRAWN
                    ? ActionType::RESIGN_APPROVED
                    : ActionType::PLACEMENT_EXPEL_APPROVED),
            entityType: self::PARTICIPANT_TYPE,
            entityId: (int) $row->id,
            detail: [
                'placement_container_id' => (int) $row->placement_container_id,
                'candidate_id' => (int) $row->candidate_id,
                'status_before' => PlacementParticipantStatus::WORKING->value,
                'status_after' => $terminal->value,
                'version' => $expectedVersion,
            ],
            actorId: $actor->getKey(),
        );
    }

    /**
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function decisionArguments(string|array|null $noteChecker, array $options): array
    {
        if (is_array($noteChecker)) {
            $options = array_merge($noteChecker, $options);
            $noteChecker = $options['note_checker'] ?? null;

            if (! is_string($noteChecker)) {
                $noteChecker = null;
            }
        }

        return [$noteChecker, $options];
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason_maker', 'PLACEMENT_REASON_REQUIRED');
        }

        return $reason;
    }

    private function requiredCheckerNote(?string $note): string
    {
        $note = is_string($note) ? trim($note) : '';

        if ($note === '') {
            $this->fail('note_checker', 'APV_NOTE');
        }

        return $note;
    }

    private function authorizeExecute(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('PLACEMENT_INACTIVE');
        }

        Gate::forUser($actor)->authorize('placement.execute');
    }

    private function authorizeReview(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('PLACEMENT_INACTIVE');
        }

        Gate::forUser($actor)->authorize('placement.review');
    }

    private function assertAuthenticatedActor(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('PLACEMENT_ACTOR_MISMATCH');
        }
    }

    private function requireVersion(array $payload): int
    {
        $version = $payload['version'] ?? null;

        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            $this->fail('version', 'PC_VERSION_REQUIRED');
        }

        return (int) $version;
    }

    /** @param array<string, mixed> $payload */
    private function notifyReviewers(ActionType $action, array $payload): void
    {
        $ids = User::query()
            ->where('status_akun', 'Aktif')
            ->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo('placement.review'))
            ->modelKeys();

        if ($ids === []) {
            return;
        }

        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function notifyMaker(int $makerId, ActionType $action, object $row): void
    {
        $payload = [
            'placement_container_id' => (int) $row->placement_container_id,
            'participant_id' => (int) $row->id,
        ];

        $this->notifications->notifyInApp([$makerId], $action->value, $payload);
        $this->notifications->queueEmailAfterCommit([$makerId], $action->value, $payload);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
