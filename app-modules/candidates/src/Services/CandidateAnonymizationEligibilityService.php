<?php

namespace Modules\Candidates\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;

/**
 * W3-T8 — transactional eligibility skeleton; the PII tombstone remains Wave 7.
 *
 * Cross-module facts are required callbacks so Candidates never reads Jobs or
 * Placement tables directly. Probes and the eligible action run while the
 * main Candidate and its existing revisions are locked in one transaction.
 */
final class CandidateAnonymizationEligibilityService
{
    /**
     * @param  Closure(int): bool  $hasActiveParticipation
     * @param  Closure(int): bool  $hasWorkingPlacement
     * @param  Closure(int): bool  $hasOpenPending
     * @param  Closure(object): mixed  $eligibleAction
     */
    public function run(
        int $candidateId,
        Closure $hasActiveParticipation,
        Closure $hasWorkingPlacement,
        Closure $hasOpenPending,
        Closure $eligibleAction,
    ): mixed {
        return DB::transaction(function () use (
            $candidateId,
            $hasActiveParticipation,
            $hasWorkingPlacement,
            $hasOpenPending,
            $eligibleAction,
        ): mixed {
            $candidate = DB::table('candidate')
                ->where('id', $candidateId)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                $this->fail('CANDIDATE_NOT_FOUND');
            }

            if (
                $candidate->parent_candidate_id !== null
                || $candidate->deleted_at !== null
                || $candidate->pii_anonymized_at !== null
            ) {
                $this->fail('PII_FROZEN');
            }

            $revisions = DB::table('candidate')
                ->where('parent_candidate_id', $candidateId)
                ->select(['id', 'status_approval'])
                ->lockForUpdate()
                ->get();

            $hasActiveRevision = $revisions->contains(
                fn (object $revision): bool => in_array(
                    (string) $revision->status_approval,
                    [
                        CandidateApprovalStatus::Draft->value,
                        CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                    ],
                    true,
                ),
            );

            if (
                (string) $candidate->status_ketersediaan !== CandidateAvailability::Tersedia->value
                || $hasActiveParticipation($candidateId)
                || $hasWorkingPlacement($candidateId)
                || $hasOpenPending($candidateId)
                || $hasActiveRevision
            ) {
                $this->fail('PII_ACTIVE');
            }

            return $eligibleAction($candidate);
        });
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['candidate' => $code]);
    }
}
