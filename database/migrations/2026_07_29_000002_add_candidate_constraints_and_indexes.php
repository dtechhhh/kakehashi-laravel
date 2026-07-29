<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate', function (Blueprint $table) {
            $table->foreign('parent_candidate_id')
                ->references('id')
                ->on('candidate')
                ->restrictOnUpdate()
                ->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE candidate ADD CONSTRAINT candidate_jenis_kelamin_check CHECK (jenis_kelamin IN ('M', 'F'))");
        DB::statement("ALTER TABLE candidate ADD CONSTRAINT candidate_status_pernikahan_check CHECK (status_pernikahan IN ('MARRIED', 'SINGLE'))");
        DB::statement("ALTER TABLE candidate ADD CONSTRAINT candidate_status_ketersediaan_check CHECK (status_ketersediaan IN ('TERSEDIA', 'SEDANG_DIPAKAI'))");
        DB::statement(
            "ALTER TABLE candidate ADD CONSTRAINT candidate_status_approval_check CHECK (status_approval IN ('Draft', 'Menunggu Tinjauan-BARU', 'Menunggu Tinjauan-REVISI', 'Disetujui', 'Ditolak', 'Diterapkan'))"
        );
        DB::statement(
            "ALTER TABLE candidate ADD CONSTRAINT candidate_maker_checker CHECK (status_approval <> 'Disetujui' OR (approved_by IS NOT NULL AND approved_by <> created_by))"
        );

        DB::statement("ALTER TABLE candidate_physical ADD CONSTRAINT candidate_physical_dominan_tangan_check CHECK (dominan_tangan IN ('RIGHT', 'LEFT'))");

        foreach ([
            'buta_warna',
            'merokok',
            'minum_sake',
            'pembatasan_makanan',
            'riwayat_penyakit',
            'riwayat_operasi',
        ] as $column) {
            DB::statement(
                "ALTER TABLE candidate_physical ADD CONSTRAINT candidate_physical_{$column}_check CHECK ({$column} IN ('YES', 'NO'))"
            );
        }

        DB::statement("ALTER TABLE candidate_immigration ADD CONSTRAINT candidate_immigration_pernah_ke_jepang_check CHECK (pernah_ke_jepang IN ('YES', 'NO'))");

        DB::statement(
            "CREATE UNIQUE INDEX uq_candidate_one_active_revision ON candidate (parent_candidate_id)
             WHERE parent_candidate_id IS NOT NULL
               AND status_approval IN ('Draft', 'Menunggu Tinjauan-REVISI')"
        );
        DB::statement('CREATE INDEX idx_candidate_nama_alpha_trgm ON candidate USING gin (lower(nama_alphabet) gin_trgm_ops)');
        DB::statement('CREATE INDEX idx_candidate_nama_kana_trgm ON candidate USING gin (nama_katakana gin_trgm_ops)');
        DB::statement(
            'CREATE INDEX idx_candidate_list ON candidate (status_approval, created_at DESC)
             WHERE deleted_at IS NULL AND pii_anonymized_at IS NULL'
        );
        DB::statement(
            'CREATE INDEX idx_candidate_avail ON candidate (status_ketersediaan, created_at DESC)
             WHERE deleted_at IS NULL AND pii_anonymized_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_candidate_avail');
        DB::statement('DROP INDEX IF EXISTS idx_candidate_list');
        DB::statement('DROP INDEX IF EXISTS idx_candidate_nama_kana_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_candidate_nama_alpha_trgm');
        DB::statement('DROP INDEX IF EXISTS uq_candidate_one_active_revision');

        DB::statement('ALTER TABLE candidate_immigration DROP CONSTRAINT IF EXISTS candidate_immigration_pernah_ke_jepang_check');

        foreach ([
            'riwayat_operasi',
            'riwayat_penyakit',
            'pembatasan_makanan',
            'minum_sake',
            'merokok',
            'buta_warna',
        ] as $column) {
            DB::statement("ALTER TABLE candidate_physical DROP CONSTRAINT IF EXISTS candidate_physical_{$column}_check");
        }

        DB::statement('ALTER TABLE candidate_physical DROP CONSTRAINT IF EXISTS candidate_physical_dominan_tangan_check');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_maker_checker');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_status_approval_check');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_status_ketersediaan_check');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_status_pernikahan_check');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_jenis_kelamin_check');
        DB::statement('ALTER TABLE candidate DROP CONSTRAINT IF EXISTS candidate_parent_candidate_id_foreign');
    }
};
