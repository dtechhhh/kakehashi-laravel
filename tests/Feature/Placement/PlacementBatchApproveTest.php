<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Placement\Services\PlacementBatchService;
use Shared\Approval\PendingStatus;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementBatchApproveTest extends TestCase
{
    use PlacementFixture;
    use RefreshDatabase;

    private PlacementBatchService $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlacementUsers();
        $this->seedPlacementReferences();
        $this->batch = app(PlacementBatchService::class);
    }

    public function test_valid_batch_transfers_ownership_atomically_without_available_window(): void
    {
        $container = $this->activePlacementContainer();
        $first = $this->readyCandidate();
        $second = $this->readyCandidate();
        $pendingId = $this->submitBatch($container, [
            ['candidate' => $first, 'start' => '2026-09-01', 'months' => 12],
            ['candidate' => $second, 'start' => '2026-01-15', 'months' => 3],
        ]);

        $this->actingAs($this->checker);
        $participants = $this->batch->approveBatch($this->checker, $pendingId, ['version' => 0]);

        $this->assertCount(2, $participants);
        $byCandidate = collect($participants)->keyBy('candidate_id');

        $this->assertSame('Bekerja', $byCandidate[$first['candidate_id']]->status_penempatan);
        $this->assertSame($first['participation_id'], (int) $byCandidate[$first['candidate_id']]->source_participation_id);
        $this->assertSame('2027-08-31', $byCandidate[$first['candidate_id']]->tanggal_berakhir_kontrak);
        $this->assertSame('2026-04-14', $byCandidate[$second['candidate_id']]->tanggal_berakhir_kontrak);

        $this->assertDatabaseHas('participation', [
            'id' => $first['participation_id'],
            'status_wawancara' => 'Terkirim',
            'version' => 1,
        ]);
        // No Tersedia window: availability stays Sedang Dipakai, version untouched
        // (markInUse would have bumped it).
        $this->assertDatabaseHas('candidate', [
            'id' => $first['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
            'checker_id' => $this->checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_log')
            ->where('action_type', ActionType::BATCH_SENT->value)
            ->where('entity_type', 'placement_container')
            ->count());
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif',
            'version' => 0,
        ]);
    }

    public function test_one_invalid_row_rolls_back_the_whole_batch(): void
    {
        $container = $this->activePlacementContainer();
        $good = $this->readyCandidate();
        $stale = $this->readyCandidate();
        $pendingId = $this->submitBatch($container, [
            ['candidate' => $good, 'start' => '2026-09-01', 'months' => 12],
            ['candidate' => $stale, 'start' => '2026-09-01', 'months' => 12],
        ]);

        // The second source becomes stale after submit (another actor bumped
        // its version without changing the state).
        DB::table('participation')->where('id', $stale['participation_id'])->update([
            'version' => 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->checker);
        try {
            $this->batch->approveBatch($this->checker, $pendingId, ['version' => 0]);
            $this->fail('One stale source must roll back the entire batch.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('placement_participants')->count());
        $this->assertDatabaseHas('participation', [
            'id' => $good['participation_id'],
            'status_wawancara' => 'Siap Dikirim',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $good['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertSame(0, DB::table('audit_log')->where('action_type', ActionType::BATCH_SENT->value)->count());
    }

    public function test_second_container_cannot_pull_the_same_candidate(): void
    {
        $firstContainer = $this->activePlacementContainer('W5 Kontainer A');
        $secondContainer = $this->activePlacementContainer('W5 Kontainer B');
        $candidate = $this->readyCandidate();

        $firstPending = $this->submitBatch($firstContainer, [
            ['candidate' => $candidate, 'start' => '2026-09-01', 'months' => 12],
        ]);
        $secondPending = $this->submitBatch($secondContainer, [
            ['candidate' => $candidate, 'start' => '2026-09-01', 'months' => 12],
        ]);

        $this->actingAs($this->checker);
        $this->batch->approveBatch($this->checker, $firstPending, ['version' => 0]);

        try {
            $this->batch->approveBatch($this->checker, $secondPending, ['version' => 0]);
            $this->fail('A candidate already placed must roll back the second batch.');
        } catch (ValidationException $exception) {
            // The first container already moved the source to Terkirim, so the
            // second batch fails source revalidation before any placement row.
            $this->assertSame(['SOURCE_NOT_READY'], $exception->errors()['source_participation_id'] ?? []);
        }

        $this->assertSame(1, DB::table('placement_participants')->count());
        $this->assertDatabaseHas('pending_request', [
            'id' => $secondPending,
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertDatabaseHas('participation', [
            'id' => $candidate['participation_id'],
            'status_wawancara' => 'Terkirim',
        ]);
    }

    public function test_maker_cannot_self_approve_and_double_approval_conflicts(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $pendingId = $this->submitBatch($container, [
            ['candidate' => $candidate, 'start' => '2026-09-01', 'months' => 12],
        ]);

        $this->maker->givePermissionTo('placement.review');
        try {
            $this->batch->approveBatch($this->maker, $pendingId, ['version' => 0]);
            $this->fail('The maker must not approve their own batch.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }

        $this->actingAs($this->checker);
        $this->batch->approveBatch($this->checker, $pendingId, ['version' => 0]);

        try {
            $this->batch->approveBatch($this->checker, $pendingId, ['version' => 0]);
            $this->fail('A second approval must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }
    }

    public function test_batch_rejection_leaves_source_untouched_and_audits(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $pendingId = $this->submitBatch($container, [
            ['candidate' => $candidate, 'start' => '2026-09-01', 'months' => 12],
        ]);

        $this->actingAs($this->checker);
        try {
            $this->batch->rejectBatch($this->checker, $pendingId, '   ', ['version' => 0]);
            $this->fail('A rejection note is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        $this->batch->rejectBatch($this->checker, $pendingId, 'Perusahaan belum siap', ['version' => 0]);

        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Perusahaan belum siap',
        ]);
        $this->assertSame(0, DB::table('placement_participants')->count());
        $this->assertDatabaseHas('participation', [
            'id' => $candidate['participation_id'],
            'status_wawancara' => 'Siap Dikirim',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertSame(1, DB::table('audit_log')
            ->where('action_type', ActionType::BATCH_REJECTED->value)
            ->count());
    }

    public function test_stale_container_version_conflicts_at_approve(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $pendingId = $this->submitBatch($container, [
            ['candidate' => $candidate, 'start' => '2026-09-01', 'months' => 12],
        ]);

        $this->actingAs($this->checker);
        try {
            $this->batch->approveBatch($this->checker, $pendingId, ['version' => 3]);
            $this->fail('A stale container version must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }
    }

    /**
     * @param  array<int, array{candidate: array{candidate_id: int, participation_id: int}, start: string, months: int}>  $rows
     */
    private function submitBatch(object $container, array $rows): int
    {
        $visaId = $this->visaId;
        $result = $this->batch->submitBatch($this->maker, (int) $container->id, array_map(
            static fn (array $row): array => [
                'candidate_id' => $row['candidate']['candidate_id'],
                'source_participation_id' => $row['candidate']['participation_id'],
                'jenis_visa_id' => $visaId,
                'tanggal_mulai_kerja' => $row['start'],
                'durasi_kontrak_bulan' => $row['months'],
            ],
            $rows,
        ), ['version' => 0]);

        return $result->pending_request_id;
    }
}
