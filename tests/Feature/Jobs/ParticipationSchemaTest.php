<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParticipationSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $containerId;

    protected function setUp(): void
    {
        parent::setUp();

        $makerId = User::factory()->create()->getKey();
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W4 Participation Company',
            'nama_romaji' => 'W4 Participation',
            'nama_id' => 'Perusahaan Participation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W4_PARTICIPATION_POSITION',
            'label_id' => 'Posisi Participation',
            'label_ja' => '参加ポジション',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W4_PARTICIPATION_VISA',
            'label_id' => 'Visa Participation',
            'label_ja' => '参加ビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->containerId = (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W4 Participation Container',
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 1,
            'deskripsi' => 'Synthetic schema fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $makerId,
            'disetujui_oleh' => User::factory()->create()->getKey(),
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_schema_and_partial_unique_index_match_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('participation', [
            'id',
            'interview_container_id',
            'candidate_id',
            'status_wawancara',
            'catatan',
            'version',
            'created_at',
            'updated_at',
        ]));

        $index = DB::selectOne(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'participation'
               AND indexname = 'uq_participation_one_active'"
        );

        $this->assertNotNull($index);
        $this->assertStringContainsString('(candidate_id)', (string) $index->indexdef);
        foreach ([
            'Menunggu Wawancara',
            'Lulus',
            'Proses Dokumen',
            'Siap Dikirim',
        ] as $status) {
            $this->assertStringContainsString($status, (string) $index->indexdef);
        }
    }

    public function test_one_active_participation_per_candidate_is_enforced(): void
    {
        $this->insertParticipation(77, 'Menunggu Wawancara');

        $this->assertDbViolation(
            fn () => $this->insertParticipation(77, 'Lulus'),
            'uq_participation_one_active'
        );

        $this->assertSame(1, DB::table('participation')->where('candidate_id', 77)->count());
    }

    public function test_terminal_status_does_not_consume_active_slot(): void
    {
        $this->insertParticipation(88, 'Terkirim');
        $this->insertParticipation(88, 'Menunggu Wawancara');

        $this->assertSame(2, DB::table('participation')->where('candidate_id', 88)->count());
    }

    public function test_status_check_rejects_values_outside_the_state_machine(): void
    {
        $this->assertDbViolation(
            fn () => $this->insertParticipation(99, 'Draft'),
            'participation_status_wawancara_check'
        );
    }

    public function test_candidate_id_has_no_cross_module_foreign_key(): void
    {
        $this->insertParticipation(999999, 'Menunggu Wawancara');

        $this->assertDatabaseHas('participation', [
            'candidate_id' => 999999,
            'status_wawancara' => 'Menunggu Wawancara',
        ]);
    }

    private function insertParticipation(int $candidateId, string $status): int
    {
        return (int) DB::table('participation')->insertGetId([
            'interview_container_id' => $this->containerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => $status,
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
