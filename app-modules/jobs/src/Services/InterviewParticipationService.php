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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W4-T3 — pull approved, available Candidates into an active container.
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

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
