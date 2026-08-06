<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Placement\Services\PlacementParticipationService;
use Shared\Audit\ActionType;
use Tests\TestCase;

class PlacementArchiveTest extends TestCase
{
    use PlacementFixture;
    use RefreshDatabase;

    private PlacementParticipationService $participation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlacementUsers();
        $this->seedPlacementReferences();
        $this->participation = app(PlacementParticipationService::class);
    }

    public function test_archive_is_never_premature_and_auto_after_last_working_terminal(): void
    {
        $container = $this->activePlacementContainer();
        $first = $this->readyCandidate();
        $second = $this->readyCandidate();
        $firstId = $this->workingParticipant($container, $first);
        $secondId = $this->workingParticipant($container, $second);

        $this->participation->completeContract($this->maker, $firstId, ['version' => 0]);
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif',
        ]);

        $this->participation->completeContract($this->maker, $secondId, ['version' => 0]);

        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Arsip',
        ]);
        $this->assertNotNull(DB::table('placement_container')->where('id', $container->id)->value('archived_at'));
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::CONTAINER_ARCHIVED->value,
            'entity_type' => 'placement_container',
            'entity_id' => $container->id,
        ]);
    }

    public function test_empty_active_container_is_never_archived(): void
    {
        $container = $this->activePlacementContainer();

        $this->assertFalse($this->participation->maybeArchiveContainer((int) $container->id));

        $this->artisan('placement:archive-sweeper')->assertSuccessful();

        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif',
        ]);
    }

    public function test_sweeper_is_an_idempotent_safety_net(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $this->workingParticipant($container, $candidate);

        // Simulate a sync path that slipped: terminal rows without archive.
        DB::table('placement_participants')
            ->where('placement_container_id', $container->id)
            ->update([
                'status_penempatan' => 'Selesai Kontrak',
                'tanggal_status_final' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif',
        ]);

        $this->artisan('placement:archive-sweeper')->assertSuccessful();
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Arsip',
        ]);

        // Second run is a no-op and must not create a duplicate audit.
        $this->artisan('placement:archive-sweeper')->assertSuccessful();
        $this->assertSame(1, DB::table('audit_log')
            ->where('action_type', ActionType::CONTAINER_ARCHIVED->value)
            ->where('entity_id', $container->id)
            ->count());
    }

    public function test_archive_guard_only_transitions_from_active(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);

        DB::table('placement_container')->where('id', $container->id)->update([
            'status' => 'Dibatalkan',
        ]);
        $this->assertFalse($this->participation->maybeArchiveContainer((int) $container->id));

        DB::table('placement_container')->where('id', $container->id)->update([
            'status' => 'Arsip',
            'archived_at' => now(),
        ]);
        $this->assertFalse($this->participation->maybeArchiveContainer((int) $container->id));
        $this->assertDatabaseHas('placement_participants', [
            'id' => $participantId,
            'status_penempatan' => 'Bekerja',
        ]);
    }

    /**
     * @param  array{candidate_id: int, participation_id: int}  $candidate
     */
    private function workingParticipant(object $container, array $candidate): int
    {
        return (int) DB::table('placement_participants')->insertGetId([
            'placement_container_id' => $container->id,
            'candidate_id' => $candidate['candidate_id'],
            'source_participation_id' => $candidate['participation_id'],
            'kategori_force_majeur_id' => null,
            'alasan_force_majeur' => null,
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => 'Bekerja',
            'version' => 0,
            'disetujui_oleh' => $this->checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
