<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participation', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('interview_container_id')
                ->constrained('interview_container')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->text('status_wawancara')->default('Menunggu Wawancara');
            $table->text('catatan')->nullable();
            $table->integer('version')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(
                ['interview_container_id', 'id'],
                'idx_participation_container'
            );
            $table->index('candidate_id', 'idx_participation_candidate');
        });

        DB::statement(
            "ALTER TABLE participation ADD CONSTRAINT participation_status_wawancara_check
             CHECK (status_wawancara IN (
                 'Menunggu Wawancara',
                 'Lulus',
                 'Proses Dokumen',
                 'Siap Dikirim',
                 'Terkirim',
                 'Tidak Lolos',
                 'Mengundurkan Diri',
                 'Dikeluarkan'
             ))"
        );
        DB::statement(
            'ALTER TABLE participation ADD CONSTRAINT participation_version_check CHECK (version >= 0)'
        );
        DB::statement(
            "CREATE UNIQUE INDEX uq_participation_one_active ON participation (candidate_id)
             WHERE status_wawancara IN (
                 'Menunggu Wawancara',
                 'Lulus',
                 'Proses Dokumen',
                 'Siap Dikirim'
             )"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_participation_one_active');
        Schema::dropIfExists('participation');
    }
};
