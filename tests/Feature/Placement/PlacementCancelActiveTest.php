<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Placement\Services\PlacementContainerService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementCancelActiveTest extends TestCase
{
    use PlacementFixture;
    use RefreshDatabase;

    private PlacementContainerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlacementUsers();
        $this->seedPlacementReferences();
        $this->service = app(PlacementContainerService::class);
    }

    public function test_empty_active_request_creates_pending_and_stays_active(): void
    {
        $container = $this->activePlacementContainer();

        $result = $this->service->requestCancelActive($this->maker, (int) $container->id, 'Tidak jadi', ['version' => 0]);

        $this->assertSame('Aktif', $result->status);
        $this->assertDatabaseHas('pending_request', [
            'type' => PendingType::PC_CANCEL_ACTIVE->value,
            'target_type' => 'placement_container',
            'target_id' => $container->id,
            'requested_by' => $this->maker->id,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_request_fails_when_participants_ever_existed(): void
    {
        $container = $this->activePlacementContainer();
        $this->addParticipant((int) $container->id, 'Bekerja');

        try {
            $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
            $this->fail('A container that ever had participants must not be cancellable.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NOT_EMPTY'], $exception->errors()['container'] ?? []);
        }

        $this->assertSame(0, DB::table('pending_request')
            ->where('type', PendingType::PC_CANCEL_ACTIVE->value)
            ->where('target_id', $container->id)
            ->count());
    }

    public function test_request_fails_when_only_terminal_participants_exist(): void
    {
        $container = $this->activePlacementContainer();
        $this->addParticipant((int) $container->id, 'Selesai Kontrak');

        try {
            $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
            $this->fail('Terminal participant rows still count as ever-had participants.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NOT_EMPTY'], $exception->errors()['container'] ?? []);
        }
    }

    public function test_approve_cancels_active_without_step_up(): void
    {
        $container = $this->activePlacementContainer();
        $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
        $pendingId = $this->cancelPendingId((int) $container->id);

        $this->actingAs($this->checker);
        $cancelled = $this->service->approveCancelActive($this->checker, $pendingId, ['version' => 0]);

        $this->assertSame('Dibatalkan', $cancelled->status);
        $this->assertSame(1, (int) $cancelled->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PC_CANCELLED->value,
            'entity_type' => 'placement_container',
            'entity_id' => $container->id,
        ]);
    }

    public function test_reject_keeps_active_with_note(): void
    {
        $container = $this->activePlacementContainer();
        $this->service->requestCancelActive($this->maker, (int) $container->id, 'Batal saja', ['version' => 0]);
        $pendingId = $this->cancelPendingId((int) $container->id);

        $this->actingAs($this->checker);
        $row = $this->service->rejectCancelActive($this->checker, $pendingId, 'Kontrak masih berjalan', ['version' => 0]);

        $this->assertSame('Aktif', $row->status);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Kontrak masih berjalan',
        ]);
    }

    public function test_double_decision_is_conflict(): void
    {
        $container = $this->activePlacementContainer();
        $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
        $pendingId = $this->cancelPendingId((int) $container->id);

        $this->actingAs($this->checker);
        $this->service->approveCancelActive($this->checker, $pendingId, ['version' => 0]);

        try {
            $this->service->approveCancelActive($this->checker, $pendingId, ['version' => 1]);
            $this->fail('A second decision must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('PC_PENDING_INVALID', $exception->getMessage());
        }
    }

    public function test_self_approve_is_denied(): void
    {
        $container = $this->activePlacementContainer();
        $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
        $pendingId = $this->cancelPendingId((int) $container->id);

        $this->maker->givePermissionTo('placement.review');

        try {
            $this->service->approveCancelActive($this->maker, $pendingId, ['version' => 0]);
            $this->fail('Maker must not approve their own cancel-active request.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }
    }

    public function test_approve_revalidates_empty_after_race(): void
    {
        $container = $this->activePlacementContainer();
        $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
        $pendingId = $this->cancelPendingId((int) $container->id);
        $this->addParticipant((int) $container->id, 'Bekerja');

        try {
            $this->actingAs($this->checker);
            $this->service->approveCancelActive($this->checker, $pendingId, ['version' => 0]);
            $this->fail('Approve must revalidate that the container is still empty.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NOT_EMPTY'], $exception->errors()['container'] ?? []);
        }

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $container->id)->value('status'));
    }

    public function test_request_blocked_by_other_pending(): void
    {
        $container = $this->activePlacementContainer();
        DB::table('pending_request')->insert([
            'type' => PendingType::PLACEMENT_BATCH->value,
            'target_type' => 'placement_container',
            'target_id' => $container->id,
            'requested_by' => $this->maker->id,
            'reason_maker' => 'Batch uji',
            'payload' => json_encode(['snapshot' => []]),
            'status' => PendingStatus::PENDING->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->service->requestCancelActive($this->maker, (int) $container->id, null, ['version' => 0]);
            $this->fail('Cancel-active must be blocked while another pending exists.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_BLOCKED_PENDING'], $exception->errors()['container'] ?? []);
        }
    }

    private function addParticipant(int $containerId, string $status): void
    {
        $ready = $this->readyCandidate();

        DB::table('placement_participants')->insert([
            'placement_container_id' => $containerId,
            'candidate_id' => $ready['candidate_id'],
            'source_participation_id' => $ready['participation_id'],
            'kategori_force_majeur_id' => null,
            'alasan_force_majeur' => null,
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => $status,
            'tanggal_status_final' => $status === 'Bekerja' ? null : now()->toDateString(),
            'catatan_alasan' => null,
            'disetujui_oleh' => $this->checker->id,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cancelPendingId(int $containerId): int
    {
        return (int) DB::table('pending_request')
            ->where('type', PendingType::PC_CANCEL_ACTIVE->value)
            ->where('target_type', 'placement_container')
            ->where('target_id', $containerId)
            ->where('status', PendingStatus::PENDING->value)
            ->value('id');
    }
}
