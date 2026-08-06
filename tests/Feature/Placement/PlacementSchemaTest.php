<?php

namespace Tests\Feature\Placement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlacementSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $containerId;

    private int $visaId;

    private int $forceMajeurId;

    protected function setUp(): void
    {
        parent::setUp();

        $makerId = User::factory()->create()->getKey();
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W5 Schema Company',
            'nama_romaji' => 'W5 Schema',
            'nama_id' => 'Perusahaan Schema',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->containerId = (int) DB::table('placement_container')->insertGetId([
            'kode_kontainer' => 'P-2026-00001',
            'nama' => 'W5 Schema Container',
            'perusahaan_id' => $companyId,
            'status' => 'Aktif',
            'dibuat_oleh' => $makerId,
            'disetujui_oleh' => User::factory()->create()->getKey(),
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W5_SCHEMA_VISA',
            'label_id' => 'Visa Schema',
            'label_ja' => 'スキーマビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->forceMajeurId = (int) DB::table('kategori_force_majeur')->insertGetId([
            'code' => 'W5_SCHEMA_FM',
            'label_id' => 'Kategori Schema',
            'label_ja' => 'スキーマ区分',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_schema_and_indexes_match_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('placement_participants', [
            'id',
            'placement_container_id',
            'candidate_id',
            'source_participation_id',
            'kategori_force_majeur_id',
            'alasan_force_majeur',
            'jenis_visa_id',
            'tanggal_mulai_kerja',
            'durasi_kontrak_bulan',
            'tanggal_berakhir_kontrak',
            'status_penempatan',
            'tanggal_status_final',
            'catatan_alasan',
            'disetujui_oleh',
            'version',
            'created_at',
            'updated_at',
        ]));

        $unique = DB::selectOne(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'placement_participants'
               AND indexname = 'uq_pp_one_active_work'"
        );

        $this->assertNotNull($unique);
        $this->assertStringContainsString('(candidate_id)', (string) $unique->indexdef);
        $this->assertStringContainsString("status_penempatan = 'Bekerja'", (string) $unique->indexdef);

        foreach (['idx_pp_container', 'idx_pp_candidate'] as $indexName) {
            $index = DB::selectOne(
                "SELECT indexdef FROM pg_indexes
                 WHERE schemaname = current_schema()
                   AND tablename = 'placement_participants'
                   AND indexname = '{$indexName}'"
            );
            $this->assertNotNull($index, "Missing index {$indexName}");
        }

        $this->assertStringContainsString(
            '(placement_container_id, id)',
            (string) DB::selectOne(
                "SELECT indexdef FROM pg_indexes
                 WHERE schemaname = current_schema()
                   AND tablename = 'placement_participants'
                   AND indexname = 'idx_pp_container'"
            )->indexdef,
        );
    }

    public function test_force_majeur_check_requires_category_and_reason_only_when_source_is_null(): void
    {
        $this->assertDbViolation(
            fn () => $this->insertParticipant(11, null, null, null),
            'pp_force_majeur_chk',
        );

        $this->assertDbViolation(
            fn () => $this->insertParticipant(12, null, $this->forceMajeurId, null),
            'pp_force_majeur_chk',
        );

        $this->assertDbViolation(
            fn () => $this->insertParticipant(13, 500, $this->forceMajeurId, 'Alasan tidak boleh ada'),
            'pp_force_majeur_chk',
        );

        $this->assertSame(0, DB::table('placement_participants')->count());

        $normal = $this->insertParticipant(21, 500, null, null);
        $fm = $this->insertParticipant(22, null, $this->forceMajeurId, 'Kandidat sakit berat');

        $this->assertDatabaseHas('placement_participants', ['id' => $normal, 'source_participation_id' => 500]);
        $this->assertDatabaseHas('placement_participants', [
            'id' => $fm,
            'kategori_force_majeur_id' => $this->forceMajeurId,
            'alasan_force_majeur' => 'Kandidat sakit berat',
        ]);
    }

    public function test_one_active_work_per_candidate_is_enforced(): void
    {
        $this->insertParticipant(31, 500, null, null);

        $this->assertDbViolation(
            fn () => $this->insertParticipant(31, 501, null, null),
            'uq_pp_one_active_work',
        );

        $this->assertSame(1, DB::table('placement_participants')->where('candidate_id', 31)->count());
    }

    public function test_terminal_status_does_not_consume_active_slot(): void
    {
        $this->insertParticipant(41, 500, null, null, status: 'Selesai Kontrak');
        $this->insertParticipant(41, 501, null, null);

        $this->assertSame(2, DB::table('placement_participants')->where('candidate_id', 41)->count());
    }

    public function test_status_check_rejects_values_outside_the_state_machine(): void
    {
        $this->assertDbViolation(
            fn () => $this->insertParticipant(51, 500, null, null, status: 'Draft'),
            'placement_participants_status_check',
        );
    }

    public function test_cross_module_references_have_no_foreign_keys(): void
    {
        $this->insertParticipant(999999, 888888, null, null);

        $this->assertDatabaseHas('placement_participants', [
            'candidate_id' => 999999,
            'source_participation_id' => 888888,
        ]);
    }

    private function insertParticipant(
        int $candidateId,
        ?int $sourceParticipationId,
        ?int $forceMajeurId,
        ?string $reason,
        string $status = 'Bekerja',
    ): int {
        return (int) DB::table('placement_participants')->insertGetId([
            'placement_container_id' => $this->containerId,
            'candidate_id' => $candidateId,
            'source_participation_id' => $sourceParticipationId,
            'kategori_force_majeur_id' => $forceMajeurId,
            'alasan_force_majeur' => $reason,
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => $status,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDbViolation(callable $operation, string $constraint): void
    {
        $savepoint = 'sp_'.bin2hex(random_bytes(6));
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
            $this->fail("Expected PostgreSQL constraint [{$constraint}] to reject the write.");
        } catch (\Throwable $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
        }

        DB::statement("RELEASE SAVEPOINT {$savepoint}");
    }
}
