<?php

namespace Modules\Candidates\Public;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * W3-T6 — public availability facade for Jobs / Placement.
 *
 * Cross-module callers must not UPDATE candidate.status_ketersediaan directly.
 * Optimistic lock via version; anonymization revalidated inside the write transaction.
 */
final class CandidateAvailabilityService
{
    public function isAvailableAndApproved(int $candidateId): bool
    {
        $row = DB::table('candidate')->where('id', $candidateId)->first();
        if ($row === null) {
            return false;
        }

        return $this->isMainOperationalAvailable($row);
    }

    /** Return the current optimistic-lock version for a public-service caller. */
    public function currentVersion(int $candidateId): int
    {
        $row = DB::table('candidate')->where('id', $candidateId)->first(['id', 'version']);
        if ($row === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        return (int) $row->version;
    }

    /**
     * W4-T3 bulk pull only. The caller must keep its transaction open while
     * using the returned version; the row lock prevents a second pull from
     * validating the same candidate concurrently.
     *
     * @throws ConflictHttpException 409 when eligibility is lost while waiting
     * @throws ValidationException 422 when the candidate is not pull-eligible
     */
    public function lockForBulkPull(int $candidateId): int
    {
        $before = DB::table('candidate')->where('id', $candidateId)->first();
        if ($before === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        $wasEligible = $this->isMainOperationalAvailable($before);
        $row = DB::table('candidate')
            ->where('id', $candidateId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            if ($wasEligible) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        if ($wasEligible && ! $this->isMainOperationalAvailable($row)) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (! $this->isMainOperationalAvailable($row)) {
            $this->fail('candidate', 'CANDIDATE_NOT_AVAILABLE');
        }

        return (int) $row->version;
    }

    /**
     * Pull wawancara / Force-Majeur only: Tersedia + Disetujui → Sedang Dipakai.
     *
     * @throws ConflictHttpException 409 stale version
     * @throws ValidationException 422 guard/state
     */
    public function markInUse(int $candidateId, int $version): void
    {
        DB::transaction(function () use ($candidateId, $version): void {
            $row = $this->loadForMutation($candidateId);
            $this->assertVersion($row, $version);
            $this->assertNotAnonymizedOrDeleted($row);
            $this->assertMainCandidate($row);

            if (
                (string) $row->status_approval !== CandidateApprovalStatus::Disetujui->value
                || (string) $row->status_ketersediaan !== CandidateAvailability::Tersedia->value
            ) {
                $this->fail('status_ketersediaan', 'CANDIDATE_NOT_AVAILABLE');
            }

            $affected = DB::table('candidate')
                ->where('id', $candidateId)
                ->where('version', $version)
                ->where('status_approval', CandidateApprovalStatus::Disetujui->value)
                ->where('status_ketersediaan', CandidateAvailability::Tersedia->value)
                ->whereNull('parent_candidate_id')
                ->whereNull('deleted_at')
                ->whereNull('pii_anonymized_at')
                ->update([
                    'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }
        });
    }

    /**
     * Placement normal transfer: assert Sedang Dipakai without mutating availability.
     *
     * @throws ConflictHttpException 409 stale version
     * @throws ValidationException 422 guard/state
     */
    public function assertInUse(int $candidateId, int $version): void
    {
        $row = $this->loadForMutation($candidateId);
        $this->assertVersion($row, $version);
        $this->assertNotAnonymizedOrDeleted($row);
        $this->assertMainCandidate($row);

        if (
            (string) $row->status_approval !== CandidateApprovalStatus::Disetujui->value
            || (string) $row->status_ketersediaan !== CandidateAvailability::SedangDipakai->value
        ) {
            $this->fail('status_ketersediaan', 'CANDIDATE_NOT_IN_USE');
        }
    }

    /**
     * Release operational hold: Sedang Dipakai → Tersedia.
     * Already Tersedia + matching version + valid main → no-op success (API_CONTRACTS §3.1).
     *
     * @throws ConflictHttpException 409 stale version
     * @throws ValidationException 422 guard/state
     */
    public function markAvailable(int $candidateId, int $version): void
    {
        DB::transaction(function () use ($candidateId, $version): void {
            $row = $this->loadForMutation($candidateId);
            $this->assertVersion($row, $version);
            $this->assertNotAnonymizedOrDeleted($row);
            $this->assertMainCandidate($row);

            if ((string) $row->status_approval !== CandidateApprovalStatus::Disetujui->value) {
                $this->fail('status_approval', 'CANDIDATE_NOT_APPROVED');
            }

            // Idempotent when already free: version must still match (stale → 409 above).
            if ((string) $row->status_ketersediaan === CandidateAvailability::Tersedia->value) {
                return;
            }

            if ((string) $row->status_ketersediaan !== CandidateAvailability::SedangDipakai->value) {
                $this->fail('status_ketersediaan', 'CANDIDATE_NOT_IN_USE');
            }

            $affected = DB::table('candidate')
                ->where('id', $candidateId)
                ->where('version', $version)
                ->where('status_approval', CandidateApprovalStatus::Disetujui->value)
                ->where('status_ketersediaan', CandidateAvailability::SedangDipakai->value)
                ->whereNull('parent_candidate_id')
                ->whereNull('deleted_at')
                ->whereNull('pii_anonymized_at')
                ->update([
                    'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                    'version' => $version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }
        });
    }

    private function loadForMutation(int $candidateId): object
    {
        $row = DB::table('candidate')->where('id', $candidateId)->first();
        if ($row === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        return $row;
    }

    private function assertVersion(object $row, int $version): void
    {
        if ($version !== (int) $row->version) {
            throw new ConflictHttpException('CONFLICT');
        }
    }

    private function assertNotAnonymizedOrDeleted(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_ANONYMIZED');
        }
    }

    private function assertMainCandidate(object $row): void
    {
        if ($row->parent_candidate_id !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_MAIN');
        }
    }

    private function isMainOperationalAvailable(object $row): bool
    {
        return $row->parent_candidate_id === null
            && (string) $row->status_approval === CandidateApprovalStatus::Disetujui->value
            && (string) $row->status_ketersediaan === CandidateAvailability::Tersedia->value
            && $row->pii_anonymized_at === null
            && $row->deleted_at === null
            && $row->nomor_induk !== null;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
