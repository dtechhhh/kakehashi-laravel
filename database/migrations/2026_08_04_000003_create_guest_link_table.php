<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_link', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->text('label');
            // Cross-module reference; Guest Access must validate the container at read time.
            $table->unsignedBigInteger('interview_container_id');
            $table->text('token_hash')->unique();
            $table->text('kode_tambahan_hash')->nullable();
            $table->timestampTz('tanggal_kadaluarsa');
            $table->text('status_link')->default('Menunggu Approval');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['interview_container_id', 'status_link'], 'idx_guest_link_container_status');
        });

        DB::statement("ALTER TABLE guest_link ADD CONSTRAINT guest_link_status_check CHECK (status_link IN ('Menunggu Approval', 'Aktif', 'Kadaluarsa'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_link');
    }
};
