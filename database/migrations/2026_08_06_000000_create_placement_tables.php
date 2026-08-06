<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('placement_container', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('kode_kontainer', 13)->nullable()->unique();
            $table->text('nama');
            $table->foreignId('perusahaan_id')->constrained('perusahaan')->restrictOnUpdate()->restrictOnDelete();
            $table->text('status')->default('Draft');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->integer('version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('status', 'idx_pc_status');
            $table->index('perusahaan_id', 'idx_pc_perusahaan');
        });

        DB::statement("ALTER TABLE placement_container ADD CONSTRAINT placement_container_status_check CHECK (status IN ('Draft', 'Menunggu Approval', 'Aktif', 'Arsip', 'Dibatalkan'))");
        DB::statement('ALTER TABLE placement_container ADD CONSTRAINT placement_container_maker_checker CHECK (status <> \'Aktif\' OR (disetujui_oleh IS NOT NULL AND disetujui_oleh <> dibuat_oleh))');

        Schema::create('placement_participants', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('placement_container_id')->constrained('placement_container')->restrictOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('source_participation_id')->nullable();
            $table->foreignId('kategori_force_majeur_id')->nullable()->constrained('kategori_force_majeur')->restrictOnUpdate()->restrictOnDelete();
            $table->text('alasan_force_majeur')->nullable();
            $table->foreignId('jenis_visa_id')->constrained('jenis_visa')->restrictOnUpdate()->restrictOnDelete();
            $table->date('tanggal_mulai_kerja')->nullable();
            $table->integer('durasi_kontrak_bulan')->nullable();
            $table->date('tanggal_berakhir_kontrak')->nullable();
            $table->text('status_penempatan')->default('Bekerja');
            $table->date('tanggal_status_final')->nullable();
            $table->text('catatan_alasan')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->integer('version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE placement_participants ADD CONSTRAINT placement_participants_status_check CHECK (status_penempatan IN ('Bekerja', 'Selesai Kontrak', 'Mengundurkan Diri', 'Dikeluarkan'))");
        DB::statement('ALTER TABLE placement_participants ADD CONSTRAINT pp_force_majeur_chk CHECK ((source_participation_id IS NULL) = (kategori_force_majeur_id IS NOT NULL AND alasan_force_majeur IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX uq_pp_one_active_work ON placement_participants (candidate_id) WHERE status_penempatan = \'Bekerja\'');
        DB::statement('CREATE INDEX idx_pp_container ON placement_participants (placement_container_id, id)');
        DB::statement('CREATE INDEX idx_pp_candidate ON placement_participants (candidate_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_participants');
        Schema::dropIfExists('placement_container');
    }
};
