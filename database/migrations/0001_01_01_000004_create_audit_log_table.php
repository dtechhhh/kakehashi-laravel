<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            // DATABASE_SCHEMA §1.2 — BIGINT GENERATED ALWAYS AS IDENTITY
            $table->id()->generatedAs()->always();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('actor_role_snapshot')->nullable();
            $table->text('action_type');
            $table->text('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('detail')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id)');
        DB::statement('CREATE INDEX idx_audit_actor ON audit_log (actor_id)');
        DB::statement('CREATE INDEX idx_audit_action ON audit_log (action_type)');
        DB::statement('CREATE INDEX idx_audit_created ON audit_log (created_at)');

        // Gate: append-only — UPDATE/DELETE raise even for table owner (DATABASE_SCHEMA §5.7).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is immutable';
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_audit_log_immutable
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW
                EXECUTE FUNCTION audit_log_immutable()
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_log_immutable ON audit_log');
        DB::statement('DROP FUNCTION IF EXISTS audit_log_immutable()');
        Schema::dropIfExists('audit_log');
    }
};
