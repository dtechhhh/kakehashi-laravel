<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE pending_request
            ADD CONSTRAINT pending_request_decision_shape CHECK (
                status NOT IN ('pending', 'approved', 'rejected')
                OR (
                    (
                        status = 'pending'
                        AND checker_id IS NULL
                        AND note_checker IS NULL
                        AND decided_at IS NULL
                    )
                    OR (
                        status = 'approved'
                        AND checker_id IS NOT NULL
                        AND decided_at IS NOT NULL
                    )
                    OR (
                        status = 'rejected'
                        AND checker_id IS NOT NULL
                        AND decided_at IS NOT NULL
                        AND note_checker IS NOT NULL
                        AND btrim(note_checker) <> ''
                    )
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE pending_request
            ADD CONSTRAINT pending_request_checker_not_maker CHECK (
                checker_id IS NULL OR checker_id <> requested_by
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pending_request_lifecycle_guard()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'pending_request_no_delete: rows cannot be deleted'
                        USING ERRCODE = '23514',
                              CONSTRAINT = 'pending_request_no_delete';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status IN ('approved', 'rejected') THEN
                        RAISE EXCEPTION 'pending_request_insert_pending_only: request must start pending'
                            USING ERRCODE = '23514',
                                  CONSTRAINT = 'pending_request_insert_pending_only';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'pending_request_decision_once: decision is immutable'
                        USING ERRCODE = '23514',
                              CONSTRAINT = 'pending_request_decision_once';
                END IF;

                IF NEW.status NOT IN ('approved', 'rejected') THEN
                    RAISE EXCEPTION 'pending_request_pending_to_decision: update must be a decision'
                        USING ERRCODE = '23514',
                              CONSTRAINT = 'pending_request_pending_to_decision';
                END IF;

                IF ROW(
                    NEW.id,
                    NEW.type,
                    NEW.target_type,
                    NEW.target_id,
                    NEW.requested_by,
                    NEW.reason_maker,
                    NEW.payload,
                    NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id,
                    OLD.type,
                    OLD.target_type,
                    OLD.target_id,
                    OLD.requested_by,
                    OLD.reason_maker,
                    OLD.payload,
                    OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'pending_request_provenance_immutable: provenance is immutable'
                        USING ERRCODE = '23514',
                              CONSTRAINT = 'pending_request_provenance_immutable';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_pending_request_lifecycle
                BEFORE INSERT OR UPDATE OR DELETE ON pending_request
                FOR EACH ROW
                EXECUTE FUNCTION pending_request_lifecycle_guard()
            SQL);

        $runtime = $this->quotedRuntimeRole();

        // ponytail: use a dedicated decision role/function only if raw SQL leaves trusted app code.
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON TABLE pending_request FROM {$runtime}");
        DB::statement(
            "GRANT UPDATE (status, checker_id, note_checker, decided_at, updated_at) ON TABLE pending_request TO {$runtime}"
        );
    }

    public function down(): void
    {
        $runtime = $this->quotedRuntimeRole();

        DB::statement(
            "REVOKE UPDATE (status, checker_id, note_checker, decided_at, updated_at) ON TABLE pending_request FROM {$runtime}"
        );
        DB::statement("GRANT UPDATE, DELETE ON TABLE pending_request TO {$runtime}");

        DB::statement('DROP TRIGGER IF EXISTS trg_pending_request_lifecycle ON pending_request');
        DB::statement('DROP FUNCTION IF EXISTS pending_request_lifecycle_guard()');
        DB::statement('ALTER TABLE pending_request DROP CONSTRAINT IF EXISTS pending_request_checker_not_maker');
        DB::statement('ALTER TABLE pending_request DROP CONSTRAINT IF EXISTS pending_request_decision_shape');
    }

    private function quotedRuntimeRole(): string
    {
        $runtime = (string) config('database.connections.pgsql.username', '');
        $migrator = (string) config('database.connections.pgsql_migrator.username', '');

        if ($runtime === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $runtime) !== 1) {
            throw new RuntimeException('Runtime database role is missing or invalid.');
        }

        if ($migrator === '' || $runtime === $migrator) {
            throw new RuntimeException('A separate migrator database role is required.');
        }

        if ((string) DB::selectOne('select current_user as usr')->usr !== $migrator) {
            throw new RuntimeException(
                'Privilege migration must run as the migrator role; use --database=pgsql_migrator.'
            );
        }

        $quoted = DB::selectOne('select quote_ident(?) as quoted', [$runtime])->quoted ?? null;

        if (! is_string($quoted) || $quoted === '') {
            throw new RuntimeException('Failed to quote runtime database role via quote_ident.');
        }

        return $quoted;
    }
};
