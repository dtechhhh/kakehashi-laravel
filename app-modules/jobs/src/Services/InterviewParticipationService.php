<?php

namespace Modules\Jobs\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W4-T3/T4/T5 — pull Candidates, apply natural transitions, and expel flow.
 *
 * Candidate availability is mutated only through CandidateAvailabilityService;
 * this service owns the participation row and audit in the same transaction.
 */
final class InterviewParticipationService
{
    public function __construct(
        private readonly CandidateAvailabilityService $availability,
        private readonly AuditLogger $audit,
        private readonly PendingRequestService $pending,
        private readonly StepUpService $stepUp,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<int, int|string>  $candidateIds
     * @return list<object>
     */
    public function pull(User $actor, int $containerId, array $candidateIds): array
    {
        $this->authorize($actor);
        $ids = $this->candidateIds($candidateIds);

        return DB::transaction(function () use ($actor, $containerId, $ids): array {
            $container = DB::table('interview_container')
                ->where('id', $containerId)
                ->lockForUpdate()
                ->first();

            if ($container === null) {
                throw new NotFoundHttpException('IC_NOT_FOUND');
            }

            if ($container->status !== InterviewContainerStatus::ACTIVE->value) {
                $this->fail('container', 'IC_NOT_ACTIVE');
            }

            $pulled = [];
            foreach ($ids as $candidateId) {
                $version = $this->availability->lockForBulkPull($candidateId);

                if (DB::table('participation')
                    ->where('candidate_id', $candidateId)
                    ->whereIn('status_wawancara', InterviewParticipationStatus::activeValues())
                    ->exists()
                ) {
                    $this->fail('candidate', 'CANDIDATE_ALREADY_IN_INTERVIEW');
                }

                $this->availability->markInUse($candidateId, $version);

                $participationId = DB::table('participation')->insertGetId([
                    'interview_container_id' => $containerId,
                    'candidate_id' => $candidateId,
                    'status_wawancara' => InterviewParticipationStatus::WAITING->value,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->record(
                    actionType: ActionType::CANDIDATE_PULLED,
                    entityType: 'participation',
                    entityId: (int) $participationId,
                    detail: [
                        'interview_container_id' => $containerId,
                        'candidate_id' => $candidateId,
                        'status_wawancara' => InterviewParticipationStatus::WAITING->value,
                    ],
                    actorId: $actor->getKey(),
                );

                $pulled[] = DB::table('participation')->where('id', $participationId)->first();
            }

            return $pulled;
        });
    }

    /**
     * Apply a natural participation transition. `Terkirim` is owned by the
     * Placement batch and is deliberately not accepted here.
     *
     * @param  array{version?: int|string, catatan?: string|null}  $options
     */
    public function updateStatus(
        User $actor,
        int $participationId,
        string|InterviewParticipationStatus $nextStatus,
        array $options = [],
    ): object {
        $this->authorize($actor);
        $next = $this->status($nextStatus);
        $expectedVersion = $this->requireVersion($options);
        $note = $this->note($options);

        return DB::transaction(function () use ($actor, $participationId, $next, $expectedVersion, $note): object {
            $original = DB::table('participation')->where('id', $participationId)->first();
            if ($original === null) {
                throw new NotFoundHttpException('PARTICIPATION_NOT_FOUND');
            }

            $container = DB::table('interview_container')
                ->where('id', $original->interview_container_id)
                ->first();

            if ($container === null) {
                throw new NotFoundHttpException('IC_NOT_FOUND');
            }

            if ($container->status !== InterviewContainerStatus::ACTIVE->value) {
                $this->fail('container', 'IC_NOT_ACTIVE');
            }

            $participation = DB::table('participation')->where('id', $participationId)->first();
            if ($participation === null) {
                throw new NotFoundHttpException('PARTICIPATION_NOT_FOUND');
            }

            if ((int) $participation->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $current = InterviewParticipationStatus::tryFrom((string) $participation->status_wawancara);
            if ($current === null || ! $this->isNaturalTransition($current, $next)) {
                $this->fail('status_wawancara', 'PARTICIPATION_INVALID_TRANSITION');
            }

            $candidateVersion = null;
            if ($next->isTerminal()) {
                $candidateVersion = $this->availability->currentVersion((int) $participation->candidate_id);
                $this->availability->assertInUse((int) $participation->candidate_id, $candidateVersion);
                // Keep Candidate → participation lock order shared with Placement.
                $this->availability->markAvailable((int) $participation->candidate_id, $candidateVersion);
            }

            $affected = DB::table('participation')
                ->where('id', $participationId)
                ->where('version', $expectedVersion)
                ->where('status_wawancara', $current->value)
                ->whereExists(function ($query): void {
                    $query->select(DB::raw('1'))
                        ->from('interview_container')
                        ->whereColumn('interview_container.id', 'participation.interview_container_id')
                        ->where('interview_container.status', InterviewContainerStatus::ACTIVE->value);
                })
                ->update([
                    'status_wawancara' => $next->value,
                    'catatan' => $note ?? $participation->catatan,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->audit->record(
                actionType: ActionType::PARTICIPATION_STATUS_CHANGED,
                entityType: 'participation',
                entityId: $participationId,
                detail: [
                    'interview_container_id' => (int) $participation->interview_container_id,
                    'candidate_id' => (int) $participation->candidate_id,
                    'status_before' => $current->value,
                    'status_after' => $next->value,
                    'version' => $expectedVersion,
                ],
                actorId: $actor->getKey(),
            );

            return DB::table('participation')->where('id', $participationId)->first();
        });
    }

    /**
     * Request expel for an active participation. The participation remains
     * unchanged until a Job Manager approves the pending request.
     *
     * @param  array{version?: int|string}  $options
     */
    public function requestExpel(
        User $actor,
        int $participationId,
        string $reasonMaker,
        array $options = [],
    ): object {
        $this->authorize($actor);
        $expectedVersion = $this->requireVersion($options);
        $reasonMaker = $this->requiredReason($reasonMaker);

        return DB::transaction(function () use ($actor, $participationId, $expectedVersion, $reasonMaker): object {
            $row = DB::table('participation')->where('id', $participationId)->first();
            if ($row === null) {
                throw new NotFoundHttpException('PARTICIPATION_NOT_FOUND');
            }

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $container = DB::table('interview_container')
                ->where('id', $row->interview_container_id)
                ->first();
            if ($container === null) {
                throw new NotFoundHttpException('IC_NOT_FOUND');
            }

            if ($container->status !== InterviewContainerStatus::ACTIVE->value) {
                $this->fail('container', 'IC_NOT_ACTIVE');
            }

            $current = InterviewParticipationStatus::tryFrom((string) $row->status_wawancara);
            if ($current === null || ! $current->isActive()) {
                $this->fail('status_wawancara', 'PARTICIPATION_INVALID_TRANSITION');
            }

            $request = $this->pending->submit(
                type: PendingType::IC_EXPEL,
                targetType: 'participation',
                targetId: $participationId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::EXPEL_REQUESTED,
                reasonMaker: $reasonMaker,
                payload: [
                    'snapshot' => [
                        'participation_id' => $participationId,
                        'interview_container_id' => (int) $row->interview_container_id,
                        'candidate_id' => (int) $row->candidate_id,
                        'status_wawancara' => $current->value,
                        'version' => $expectedVersion,
                    ],
                ],
                auditDetail: [
                    'interview_container_id' => (int) $row->interview_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_before' => $current->value,
                    'version' => $expectedVersion,
                ],
            );

            $this->notifyJobManagers(ActionType::EXPEL_REQUESTED, [
                'participation_id' => $participationId,
                'interview_container_id' => (int) $row->interview_container_id,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return DB::table('participation')->where('id', $participationId)->firstOrFail();
        });
    }

    /**
     * Approve an expel request with scoped step-up. The optional array form is
     * accepted for callers that pass `note_checker` and `version` together.
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
            $request = $this->expelPendingRequest($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$row, $current, $expectedVersion] = $this->expelContext($request, $options);

            $this->stepUp->require(
                StepUpAction::APPROVE_CANDIDATE_EXPEL,
                'participation',
                (int) $row->id,
            );

            // Decide the pending row first: its lock/conditional update
            // serializes concurrent Checker decisions. Any later failure rolls
            // the decision and audit back with the business mutation.
            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::EXPEL_APPROVED,
                note: $noteChecker,
                auditDetail: [
                    'interview_container_id' => (int) $row->interview_container_id,
                    'candidate_id' => (int) $row->candidate_id,
                    'status_before' => $current->value,
                    'status_after' => InterviewParticipationStatus::EXPELLED->value,
                    'version' => $expectedVersion,
                ],
            );

            $candidateVersion = $this->availability->currentVersion((int) $row->candidate_id);
            $this->availability->assertInUse((int) $row->candidate_id, $candidateVersion);
            // Candidate → participation ordering is shared with Placement.
            $this->availability->markAvailable((int) $row->candidate_id, $candidateVersion);

            $affected = DB::table('participation')
                ->where('id', $row->id)
                ->where('version', $expectedVersion)
                ->where('status_wawancara', $current->value)
                ->whereExists(function ($query): void {
                    $query->select(DB::raw('1'))
                        ->from('interview_container')
                        ->whereColumn('interview_container.id', 'participation.interview_container_id')
                        ->where('interview_container.status', InterviewContainerStatus::ACTIVE->value);
                })
                ->update([
                    'status_wawancara' => InterviewParticipationStatus::EXPELLED->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyMaker((int) $request->requested_by, ActionType::EXPEL_APPROVED, [
                'participation_id' => (int) $row->id,
                'interview_container_id' => (int) $row->interview_container_id,
                'pending_request_id' => $pendingRequestId,
            ]);

            return DB::table('participation')->where('id', $row->id)->firstOrFail();
        });
    }

    /**
     * Reject an expel request. Rejection does not need step-up and leaves the
     * participation and Candidate availability untouched.
     *
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
            $request = $this->expelPendingRequest($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$snapshot, $current, $expectedVersion] = $this->expelSnapshot($request);

            if (array_key_exists('version', $options)
                && $this->requireVersion($options) !== $expectedVersion
            ) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $noteChecker,
                auditAction: ActionType::EXPEL_REJECTED,
                auditDetail: [
                    'interview_container_id' => (int) $snapshot['interview_container_id'],
                    'candidate_id' => (int) $snapshot['candidate_id'],
                    'status_before' => $current->value,
                    'version' => $expectedVersion,
                ],
            );

            $this->notifyMaker((int) $request->requested_by, ActionType::EXPEL_REJECTED, [
                'participation_id' => (int) $snapshot['participation_id'],
                'interview_container_id' => (int) $snapshot['interview_container_id'],
                'pending_request_id' => $pendingRequestId,
            ]);

            return DB::table('participation')->where('id', $snapshot['participation_id'])->first()
                ?? $request;
        });
    }

    private function authorize(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('JOBS_ACTOR_MISMATCH');
        }

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }

        Gate::forUser($actor)->authorize('jobs.execute');
    }

    private function authorizeReview(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('JOBS_ACTOR_MISMATCH');
        }

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }

        Gate::forUser($actor)->authorize('jobs.review');
    }

    /**
     * @param  array<int, int|string>  $candidateIds
     * @return list<int>
     */
    private function candidateIds(array $candidateIds): array
    {
        if ($candidateIds === []) {
            $this->fail('candidate_ids', 'CANDIDATE_IDS_REQUIRED');
        }

        $normalized = [];
        foreach ($candidateIds as $candidateId) {
            if (
                (! is_int($candidateId) && ! (is_string($candidateId) && ctype_digit($candidateId)))
                || (int) $candidateId < 1
            ) {
                $this->fail('candidate_ids', 'CANDIDATE_IDS_INVALID');
            }

            $normalized[] = (int) $candidateId;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            $this->fail('candidate_ids', 'CANDIDATE_IDS_DUPLICATE');
        }

        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function status(string|InterviewParticipationStatus $status): InterviewParticipationStatus
    {
        if ($status instanceof InterviewParticipationStatus) {
            return $status;
        }

        $resolved = InterviewParticipationStatus::tryFrom($status);
        if ($resolved === null) {
            $this->fail('status_wawancara', 'PARTICIPATION_STATUS_INVALID');
        }

        return $resolved;
    }

    /** @param array{version?: int|string, catatan?: string|null} $options */
    private function requireVersion(array $options): int
    {
        $version = $options['version'] ?? null;
        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            $this->fail('version', 'PARTICIPATION_VERSION_REQUIRED');
        }

        return (int) $version;
    }

    /** @param array{version?: int|string, catatan?: string|null} $options */
    private function note(array $options): ?string
    {
        if (! array_key_exists('catatan', $options)) {
            return null;
        }

        if ($options['catatan'] !== null && ! is_string($options['catatan'])) {
            $this->fail('catatan', 'PARTICIPATION_NOTE_INVALID');
        }

        return $options['catatan'];
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
            $this->fail('reason_maker', 'EXPEL_REASON_REQUIRED');
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

    /**
     * @param  array{version?: int|string}  $options
     * @return array{0: object, 1: InterviewParticipationStatus, 2: int}
     */
    private function expelContext(PendingRequest $request, array $options): array
    {
        [$snapshot, $current, $snapshotVersion] = $this->expelSnapshot($request);

        $row = DB::table('participation')->where('id', $request->target_id)->first();
        if ($row === null) {
            throw new NotFoundHttpException('PARTICIPATION_NOT_FOUND');
        }

        $container = DB::table('interview_container')
            ->where('id', $row->interview_container_id)
            ->first();
        if ($container === null) {
            throw new NotFoundHttpException('IC_NOT_FOUND');
        }

        if ($container->status !== InterviewContainerStatus::ACTIVE->value) {
            $this->fail('container', 'IC_NOT_ACTIVE');
        }

        if ((string) $row->status_wawancara !== $current->value) {
            throw new ConflictHttpException('CONFLICT');
        }

        if ((int) $snapshot['interview_container_id'] !== (int) $row->interview_container_id
            || (int) $snapshot['candidate_id'] !== (int) $row->candidate_id
            || (int) $row->version !== $snapshotVersion
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (array_key_exists('version', $options)) {
            $optionVersion = $this->requireVersion($options);
            if ($optionVersion !== $snapshotVersion) {
                throw new ConflictHttpException('CONFLICT');
            }
        }

        return [$row, $current, $snapshotVersion];
    }

    /**
     * Read only the immutable request snapshot. Rejection uses this helper so
     * an obsolete command can still be terminally decided and not strand an
     * active pending row.
     *
     * @return array{0: array<string, mixed>, 1: InterviewParticipationStatus, 2: int}
     */
    private function expelSnapshot(PendingRequest $request): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || (int) ($snapshot['participation_id'] ?? 0) !== (int) $request->target_id
            || ! isset($snapshot['interview_container_id'], $snapshot['candidate_id'], $snapshot['status_wawancara'], $snapshot['version'])
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        $current = InterviewParticipationStatus::tryFrom((string) $snapshot['status_wawancara']);
        if ($current === null || ! $current->isActive()) {
            throw new ConflictHttpException('CONFLICT');
        }

        $snapshotVersion = $snapshot['version'];
        if (! is_int($snapshotVersion) && ! (is_string($snapshotVersion) && ctype_digit($snapshotVersion))) {
            throw new ConflictHttpException('CONFLICT');
        }

        return [$snapshot, $current, (int) $snapshotVersion];
    }

    private function expelPendingRequest(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== PendingType::IC_EXPEL
            || $request->target_type !== 'participation'
        ) {
            throw new ConflictHttpException('IC_EXPEL_PENDING_INVALID');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    /** @param array<string, mixed> $payload */
    private function notifyJobManagers(ActionType $action, array $payload): void
    {
        $ids = User::query()
            ->where('status_akun', 'Aktif')
            ->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo('jobs.review'))
            ->modelKeys();

        if ($ids === []) {
            return;
        }

        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function notifyMaker(int $makerId, ActionType $action, array $payload): void
    {
        $this->notifications->notifyInApp([$makerId], $action->value, $payload);
        $this->notifications->queueEmailAfterCommit([$makerId], $action->value, $payload);
    }

    private function isNaturalTransition(
        InterviewParticipationStatus $current,
        InterviewParticipationStatus $next,
    ): bool {
        $forward = match ($current) {
            InterviewParticipationStatus::WAITING => InterviewParticipationStatus::PASSED,
            InterviewParticipationStatus::PASSED => InterviewParticipationStatus::DOCUMENT_PROCESS,
            InterviewParticipationStatus::DOCUMENT_PROCESS => InterviewParticipationStatus::READY_FOR_PLACEMENT,
            default => null,
        };

        return $forward === $next
            || ($current->isActive() && in_array($next, [
                InterviewParticipationStatus::FAILED,
                InterviewParticipationStatus::WITHDRAWN,
            ], true));
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
