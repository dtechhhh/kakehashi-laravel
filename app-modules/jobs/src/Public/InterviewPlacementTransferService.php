<?php

namespace Modules\Jobs\Public;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * API_CONTRACTS §3.4 — source-participation contract owned by Jobs.
 *
 * Placement may not touch the participation table directly; it reads the
 * source snapshot and marks `Siap Dikirim → Terkirim` through this service
 * inside the atomic batch transaction.
 */
final class InterviewPlacementTransferService
{
    /**
     * Validate that the source participation is still the Candidate's active
     * `Siap Dikirim` row and return the immutable snapshot for the batch
     * payload. When `$lock` is true the row is FOR UPDATE locked for the
     * whole approve transaction (BR-CON-03, lock order container → source).
     *
     * @throws ValidationException 422 guard/ownership/state
     */
    public function assertReadyForPlacement(int $participationId, int $candidateId, bool $lock = false): object
    {
        $query = DB::table('participation')->where('id', $participationId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        if ($row === null) {
            $this->fail('source_participation_id', 'SOURCE_PARTICIPATION_NOT_FOUND');
        }

        if ((int) $row->candidate_id !== $candidateId) {
            $this->fail('candidate_id', 'SOURCE_OWNERSHIP_MISMATCH');
        }

        if ($row->frozen_at !== null) {
            $this->fail('source_participation_id', 'SOURCE_NOT_ACTIVE');
        }

        if ((string) $row->status_wawancara !== InterviewParticipationStatus::READY_FOR_PLACEMENT->value) {
            $this->fail('source_participation_id', 'SOURCE_NOT_READY');
        }

        $container = DB::table('interview_container')
            ->where('id', (int) $row->interview_container_id)
            ->first();

        if ($container === null || $container->status !== InterviewContainerStatus::ACTIVE->value) {
            $this->fail('source_participation_id', 'SOURCE_CONTAINER_NOT_ACTIVE');
        }

        return $row;
    }

    /**
     * Flip the source participation to `Terkirim`. Only the Placement batch
     * approval calls this; `Terkirim` is terminal (GAP-5, no manual action).
     *
     * @throws ConflictHttpException 409 stale version/state
     */
    public function markSentForPlacement(int $participationId, int $candidateId, int $version): void
    {
        $affected = DB::table('participation')
            ->where('id', $participationId)
            ->where('candidate_id', $candidateId)
            ->where('version', $version)
            ->where('status_wawancara', InterviewParticipationStatus::READY_FOR_PLACEMENT->value)
            ->whereNull('frozen_at')
            ->whereExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('interview_container')
                    ->whereColumn('interview_container.id', 'participation.interview_container_id')
                    ->where('interview_container.status', InterviewContainerStatus::ACTIVE->value);
            })
            ->update([
                'status_wawancara' => InterviewParticipationStatus::SENT->value,
                'version' => $version + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new ConflictHttpException('CONFLICT');
        }
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
