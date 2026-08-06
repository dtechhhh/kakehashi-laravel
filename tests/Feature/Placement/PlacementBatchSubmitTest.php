<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Placement\Services\PlacementBatchService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementBatchSubmitTest extends TestCase
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

    public function test_submit_creates_pending_with_snapshot_and_does_not_touch_source(): void
    {
        $container = $this->activePlacementContainer();
        $first = $this->readyCandidate();
        $second = $this->readyCandidate();

        $result = $this->batch->submitBatch($this->maker, (int) $container->id, [
            $this->batchRow([
                'candidate_id' => $first['candidate_id'],
                'source_participation_id' => $first['participation_id'],
            ]),
            $this->batchRow([
                'candidate_id' => $second['candidate_id'],
                'source_participation_id' => $second['participation_id'],
                'tanggal_mulai_kerja' => '2026-01-15',
                'durasi_kontrak_bulan' => 3,
            ]),
        ], ['version' => 0]);

        $pending = DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_BATCH->value)
            ->where('target_type', 'placement_container')
            ->where('target_id', $container->id)
            ->where('status', PendingStatus::PENDING->value)
            ->first();

        $this->assertNotNull($pending);
        $this->assertSame($this->maker->id, (int) $pending->requested_by);
        $this->assertSame(2, $result->candidate_count);
        $this->assertSame((int) $pending->id, $result->pending_request_id);

        $snapshot = is_string($pending->payload)
            ? json_decode($pending->payload, true, 512, JSON_THROW_ON_ERROR)['snapshot']
            : (array) $pending->payload['snapshot'];

        $this->assertCount(2, $snapshot);
        $byCandidate = collect($snapshot)->keyBy('candidate_id');
        $this->assertSame($first['participation_id'], $byCandidate[$first['candidate_id']]['source_participation_id']);
        $this->assertSame(0, $byCandidate[$first['candidate_id']]['source_version']);
        $this->assertSame(0, $byCandidate[$first['candidate_id']]['candidate_version']);
        $this->assertSame('2027-08-31', $byCandidate[$first['candidate_id']]['tanggal_berakhir_kontrak']);
        $this->assertSame('2026-04-14', $byCandidate[$second['candidate_id']]['tanggal_berakhir_kontrak']);

        $this->assertDatabaseHas('participation', [
            'id' => $first['participation_id'],
            'status_wawancara' => 'Siap Dikirim',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $first['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('placement_participants')->count());
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif',
            'version' => 0,
        ]);
    }

    public function test_submit_rejects_more_than_50_rows(): void
    {
        $container = $this->activePlacementContainer();
        $rows = [];
        foreach (range(1, 51) as $i) {
            $rows[] = $this->batchRow([
                'candidate_id' => 1000 + $i,
                'source_participation_id' => 2000 + $i,
            ]);
        }

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, $rows, ['version' => 0]);
            $this->fail('A batch over 50 rows must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_BATCH_TOO_LARGE'], $exception->errors()['rows'] ?? []);
        }

        $this->assertDatabaseMissing('pending_request', [
            'type' => PendingType::PLACEMENT_BATCH->value,
        ]);
    }

    public function test_submit_rejects_duplicate_candidate_or_source(): void
    {
        $container = $this->activePlacementContainer();
        $first = $this->readyCandidate();
        $second = $this->readyCandidate();

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $first['candidate_id'],
                    'source_participation_id' => $first['participation_id'],
                ]),
                $this->batchRow([
                    'candidate_id' => $first['candidate_id'],
                    'source_participation_id' => $second['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('Duplicate candidate must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_BATCH_DUPLICATE_CANDIDATE'], $exception->errors()['candidate_id'] ?? []);
        }

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $first['candidate_id'],
                    'source_participation_id' => $first['participation_id'],
                ]),
                $this->batchRow([
                    'candidate_id' => $second['candidate_id'],
                    'source_participation_id' => $first['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('Duplicate source must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_BATCH_DUPLICATE_SOURCE'], $exception->errors()['source_participation_id'] ?? []);
        }

        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_submit_rejects_source_not_ready_or_wrong_owner(): void
    {
        $container = $this->activePlacementContainer();
        $notReady = $this->readyCandidate([], ['status_wawancara' => 'Menunggu Wawancara']);
        $foreign = $this->readyCandidate();

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $notReady['candidate_id'],
                    'source_participation_id' => $notReady['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('A source that is not Siap Dikirim must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['SOURCE_NOT_READY'], $exception->errors()['source_participation_id'] ?? []);
        }

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $foreign['candidate_id'],
                    'source_participation_id' => $notReady['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('A source owned by another candidate must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['SOURCE_OWNERSHIP_MISMATCH'], $exception->errors()['candidate_id'] ?? []);
        }
    }

    public function test_submit_rejects_candidate_not_in_use_and_non_active_container(): void
    {
        $container = $this->activePlacementContainer();
        $available = $this->readyCandidate([
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
        ]);

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $available['candidate_id'],
                    'source_participation_id' => $available['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('A candidate not Sedang Dipakai must be rejected at submit.');
        } catch (ValidationException $exception) {
            $this->assertSame(['CANDIDATE_NOT_IN_USE'], $exception->errors()['status_ketersediaan'] ?? []);
        }

        $draftContainer = $this->activePlacementContainer();
        DB::table('placement_container')->where('id', $draftContainer->id)->update(['status' => 'Draft']);
        $candidate = $this->readyCandidate();

        try {
            $this->batch->submitBatch($this->maker, (int) $draftContainer->id, [
                $this->batchRow([
                    'candidate_id' => $candidate['candidate_id'],
                    'source_participation_id' => $candidate['participation_id'],
                ]),
            ], ['version' => 0]);
            $this->fail('A non-active container must reject batch submit.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NOT_ACTIVE'], $exception->errors()['container'] ?? []);
        }
    }

    public function test_submit_stale_container_version_conflicts(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();

        try {
            $this->batch->submitBatch($this->maker, (int) $container->id, [
                $this->batchRow([
                    'candidate_id' => $candidate['candidate_id'],
                    'source_participation_id' => $candidate['participation_id'],
                ]),
            ], ['version' => 4]);
            $this->fail('A stale container version must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }
    }
}
