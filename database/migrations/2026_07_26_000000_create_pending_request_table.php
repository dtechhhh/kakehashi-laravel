<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pending_request — entitas Maker-Checker (PRD §7.4, DATABASE_SCHEMA §5.7).
 *
 * Runs after 0001_01_01_000005_grant_runtime_database_privileges: the runtime role
 * inherits SELECT/INSERT/UPDATE/DELETE from ALTER DEFAULT PRIVILEGES, so grants stay
 * owned by that single migration.
 */
return new class extends Migration
{
    /** DATABASE_SCHEMA §5.7 — type CHECK whitelist. */
    private const TYPES = [
        'CANDIDATE_NEW',
        'CANDIDATE_REVISION',
        'IC_CREATE',
        'PC_CREATE',
        'PLACEMENT_BATCH',
        'IC_CLOSE',
        'IC_EXPEL',
        'GUEST_LINK',
        'PC_CANCEL_ACTIVE',
        'PLACEMENT_RESIGN',
        'PLACEMENT_EXPEL',
        'FORCE_MAJEUR',
    ];

    /** BR-APV-08 — payload snapshot wajib untuk batch/FM/expel/resign/cancel. */
    private const PAYLOAD_REQUIRED_TYPES = [
        'PLACEMENT_BATCH',
        'FORCE_MAJEUR',
        'IC_EXPEL',
        'PC_CANCEL_ACTIVE',
        'PLACEMENT_RESIGN',
        'PLACEMENT_EXPEL',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pending_request', function (Blueprint $table) {
            // DATABASE_SCHEMA §1.2 — BIGINT GENERATED ALWAYS AS IDENTITY
            $table->id()->generatedAs()->always();
            $table->text('type');
            // Polymorphic target, tanpa FK domain (DATABASE_SCHEMA §9).
            $table->text('target_type');
            $table->unsignedBigInteger('target_id');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason_maker')->nullable();
            $table->foreignId('checker_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note_checker')->nullable();
            $table->jsonb('payload')->nullable();
            $table->text('status')->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE pending_request ADD CONSTRAINT pending_request_type_check CHECK (type IN ('
            .$this->quotedList(self::TYPES).'))'
        );

        DB::statement(
            "ALTER TABLE pending_request ADD CONSTRAINT pending_request_status_check CHECK (status IN ('pending', 'approved', 'rejected'))"
        );

        // BR-APV-08 — anti double-request: satu pending aktif per (type, target).
        DB::statement(
            'CREATE UNIQUE INDEX uq_pending_active ON pending_request (type, target_type, target_id) WHERE status = \'pending\''
        );

        DB::statement(
            'ALTER TABLE pending_request ADD CONSTRAINT pending_payload_required CHECK ('
            .'type NOT IN ('.$this->quotedList(self::PAYLOAD_REQUIRED_TYPES).') OR payload IS NOT NULL)'
        );

        // Overlay lookup per target (semua status, bukan hanya pending).
        DB::statement('CREATE INDEX idx_pending_target ON pending_request (target_type, target_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_request');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".$value."'", $values));
    }
};
