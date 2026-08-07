<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only guest access log (DATABASE_SCHEMA §5.3). IP is personal
        // data — retention follows DATA_RETENTION_AND_PRIVACY (~180 days).
        Schema::create('guest_access_log', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('guest_link_id')
                ->constrained('guest_link')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->timestampTz('accessed_at')->useCurrent();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
        });

        DB::statement(
            'CREATE INDEX idx_gal_link ON guest_access_log (guest_link_id, accessed_at)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_gal_link');
        Schema::dropIfExists('guest_access_log');
    }
};
