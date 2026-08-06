<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participation', function (Blueprint $table): void {
            $table->timestampTz('frozen_at')->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS uq_participation_one_active');
        DB::statement(
            "CREATE UNIQUE INDEX uq_participation_one_active ON participation (candidate_id)
             WHERE status_wawancara IN (
                 'Menunggu Wawancara',
                 'Lulus',
                 'Proses Dokumen',
                 'Siap Dikirim'
             )
             AND frozen_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_participation_one_active');
        DB::statement(
            "CREATE UNIQUE INDEX uq_participation_one_active ON participation (candidate_id)
             WHERE status_wawancara IN (
                 'Menunggu Wawancara',
                 'Lulus',
                 'Proses Dokumen',
                 'Siap Dikirim'
             )"
        );

        Schema::table('participation', function (Blueprint $table): void {
            $table->dropColumn('frozen_at');
        });
    }
};
