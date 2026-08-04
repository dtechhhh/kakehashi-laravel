<?php

namespace Modules\Jobs\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W4-T3/T4 — pull Candidates and apply natural participation transitions.
 *
 * Candidate availability is mutated only through CandidateAvailabilityService;
 * this service owns the participation row and audit in the same transaction.
 */
final class InterviewParticipationService
{
    public function __construct(
        private readonly CandidateAvailabilityService $availability,
        private readonly AuditLogger $audit,
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
