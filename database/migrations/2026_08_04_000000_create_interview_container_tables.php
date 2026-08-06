<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_counter', function (Blueprint $table): void {
            $table->string('prefix', 2);
            $table->smallInteger('year');
            $table->integer('last_value')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['prefix', 'year']);
        });

        Schema::create('interview_container', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('kode_kontainer', 13)->nullable()->unique();
            $table->text('judul');
            $table->foreignId('perusahaan_id')->constrained('perusahaan')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('posisi_pekerjaan_id')->constrained('posisi_pekerjaan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('jenis_wawancara');
            $table->foreignId('jenis_visa_id')->constrained('jenis_visa')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_wawancara');
            $table->integer('jumlah_peserta')->default(0);
            $table->integer('target_peserta_diterima')->nullable();
            $table->text('deskripsi')->nullable();
            $table->text('syarat')->nullable();
            $table->text('status')->default('Draft');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->integer('version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_interview_container_status');
            $table->index('perusahaan_id', 'idx_interview_container_company');
        });

        DB::statement("ALTER TABLE interview_container ADD CONSTRAINT interview_container_kind_check CHECK (jenis_wawancara IN ('OFFLINE', 'ONLINE'))");
        DB::statement("ALTER TABLE interview_container ADD CONSTRAINT interview_container_status_check CHECK (status IN ('Draft', 'Menunggu Approval', 'Aktif', 'Ditutup', 'Dibatalkan'))");
        DB::statement('ALTER TABLE interview_container ADD CONSTRAINT interview_container_target_check CHECK (target_peserta_diterima IS NULL OR target_peserta_diterima >= 0)');
        DB::statement('ALTER TABLE interview_container ADD CONSTRAINT interview_container_count_check CHECK (jumlah_peserta >= 0)');
        DB::statement('ALTER TABLE interview_container ADD CONSTRAINT interview_container_maker_checker CHECK (status <> \'Aktif\' OR (disetujui_oleh IS NOT NULL AND disetujui_oleh <> dibuat_oleh))');
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_container');
        Schema::dropIfExists('container_counter');
    }
};
