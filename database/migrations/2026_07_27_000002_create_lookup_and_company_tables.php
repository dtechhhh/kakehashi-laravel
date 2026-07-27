<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_TABLES = [
        'negara',
        'bahasa',
        'provinsi',
        'kota_kabupaten',
        'kecamatan',
        'agama',
        'golongan_darah',
        'ukuran_sepatu',
        'tingkat_penglihatan',
        'asal_rekrutmen',
        'status_keluarga',
        'tingkat_pendidikan',
        'jurusan',
        'bidang_pekerjaan',
        'posisi_pekerjaan',
        'bidang_industri_perusahaan',
        'bidang_diminati',
        'jenis_kualifikasi_bahasa_inggris',
        'jenis_kualifikasi_bahasa_jepang',
        'skill_ssw',
        'kualifikasi_mengemudi',
        'kualifikasi_keahlian_lainnya',
        'jenis_visa',
        'kategori_force_majeur',
        'jenis_dokumen',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lookup_code_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'lookup code is immutable'
                        USING ERRCODE = '23514',
                              CONSTRAINT = TG_TABLE_NAME || '_code_immutable';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        $this->createLookup('negara', '^[A-Z]{2}$', function (Blueprint $table) {
            $table->text('region')->nullable();
            $table->text('dial_code')->nullable();
        });

        $this->createLookup('bahasa', '^[a-z]{2}$');

        $this->createLookup('provinsi', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->foreignId('negara_id')->nullable()->constrained('negara')->restrictOnUpdate()->restrictOnDelete();
        });

        $this->createLookup('kota_kabupaten', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->foreignId('provinsi_id')->nullable()->constrained('provinsi')->restrictOnUpdate()->restrictOnDelete();
        });

        $this->createLookup('kecamatan', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->foreignId('kota_kabupaten_id')->nullable()->constrained('kota_kabupaten')->restrictOnUpdate()->restrictOnDelete();
        });

        foreach ([
            'agama',
            'golongan_darah',
            'ukuran_sepatu',
            'tingkat_penglihatan',
            'asal_rekrutmen',
            'status_keluarga',
            'tingkat_pendidikan',
            'jurusan',
            'bidang_pekerjaan',
        ] as $table) {
            $this->createLookup($table, '^[A-Z0-9_]+$');
        }

        $this->createLookup('posisi_pekerjaan', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->foreignId('bidang_pekerjaan_id')->nullable()->constrained('bidang_pekerjaan')->restrictOnUpdate()->restrictOnDelete();
        });

        foreach ([
            'bidang_industri_perusahaan',
            'bidang_diminati',
            'jenis_kualifikasi_bahasa_inggris',
            'jenis_kualifikasi_bahasa_jepang',
        ] as $table) {
            $this->createLookup($table, '^[A-Z0-9_]+$');
        }

        $this->createLookup('skill_ssw', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->foreignId('bidang_id')->nullable()->constrained('bidang_pekerjaan')->restrictOnUpdate()->restrictOnDelete();
            $table->boolean('is_shareable')->default(false);
        });

        $this->createLookup('kualifikasi_mengemudi', '^[A-Z0-9_]+$');

        $this->createLookup('kualifikasi_keahlian_lainnya', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->boolean('is_shareable')->default(false);
        });

        $this->createLookup('jenis_visa', '^[A-Z0-9_]+$', function (Blueprint $table) {
            $table->text('kategori')->nullable();
        });

        $this->createLookup('kategori_force_majeur', '^[A-Z0-9_]+$');
        $this->createLookup('jenis_dokumen', '^[A-Z0-9_]+$');

        Schema::create('perusahaan', function (Blueprint $table) {
            $table->id()->generatedAs()->always();
            $table->text('nama_ja');
            $table->text('nama_romaji')->nullable();
            $table->text('nama_id')->nullable();
            $table->foreignId('negara_id')->nullable()->constrained('negara')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('bidang_industri_id')->nullable()->constrained('bidang_industri_perusahaan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('alamat')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('is_active', 'idx_perusahaan_active');
        });

        DB::statement(
            'ALTER TABLE perusahaan ADD CONSTRAINT perusahaan_nama_ja_not_empty '
            .'CHECK (length(btrim(nama_ja)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('perusahaan');

        foreach (array_reverse(self::LOOKUP_TABLES) as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('DROP FUNCTION IF EXISTS lookup_code_immutable()');
    }

    private function createLookup(string $name, string $codePattern, ?Closure $extra = null): void
    {
        Schema::create($name, function (Blueprint $table) use ($extra, $name) {
            $table->id()->generatedAs()->always();
            $table->string('code', 64);
            $table->string('label_id');
            $table->string('label_ja');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $extra?->__invoke($table);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique('code', "{$name}_code_unique");
        });

        DB::statement(
            "ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_not_empty "
            .'CHECK (length(btrim(code)) > 0)'
        );
        DB::statement(
            "ALTER TABLE {$name} ADD CONSTRAINT {$name}_code_format "
            ."CHECK (code ~ '{$codePattern}')"
        );
        DB::statement(
            "ALTER TABLE {$name} ADD CONSTRAINT {$name}_label_id_not_empty "
            .'CHECK (length(btrim(label_id)) > 0)'
        );
        DB::statement(
            "ALTER TABLE {$name} ADD CONSTRAINT {$name}_label_ja_not_empty "
            .'CHECK (length(btrim(label_ja)) > 0)'
        );
        DB::statement(<<<SQL
            CREATE TRIGGER trg_{$name}_code_immutable
                BEFORE UPDATE OF code ON {$name}
                FOR EACH ROW
                EXECUTE FUNCTION lookup_code_immutable()
            SQL);
    }
};
