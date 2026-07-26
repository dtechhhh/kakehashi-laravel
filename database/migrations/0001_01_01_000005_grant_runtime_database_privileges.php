<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for runtime table/sequence grants, default privileges,
 * and audit_log REVOKE (DATABASE_SCHEMA §5.7, SECURITY_CHECKLIST §7/§10).
 *
 * Must run on the migrator/owner connection: php artisan migrate --database=pgsql_migrator
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $quoted = $this->quotedRuntimeRole();

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$quoted}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$quoted}");

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$quoted}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$quoted}");

        // Append-only audit_log: runtime may SELECT/INSERT only.
        // The owner keeps DML privileges but stays blocked by trg_audit_log_immutable.
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON TABLE audit_log FROM {$quoted}");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $quoted = $this->quotedRuntimeRole();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$quoted}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM {$quoted}");

        DB::statement("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM {$quoted}");
        DB::statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM {$quoted}");
    }

    /**
     * Prove this runs as the configured migrator role, then quote the runtime role
     * via a bound SELECT quote_ident(?) — never string interpolation of raw input.
     */
    private function quotedRuntimeRole(): string
    {
        $runtime = (string) config('database.connections.pgsql.username', '');
        $migrator = (string) config('database.connections.pgsql_migrator.username', '');

        if ($migrator === '') {
            throw new RuntimeException(
                'DB_MIGRATOR_USERNAME must be set (process env or .env.migrator) before running privilege migrations.'
            );
        }

        if ($runtime === '') {
            throw new RuntimeException('Runtime database role (DB_USERNAME) must not be empty.');
        }

        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $runtime) !== 1) {
            throw new RuntimeException('Runtime database role failed identifier validation.');
        }

        if (strcasecmp($runtime, $migrator) === 0) {
            throw new RuntimeException('Runtime database role must differ from the migrator role.');
        }

        $currentUser = (string) DB::selectOne('select current_user as usr')->usr;

        if ($currentUser !== $migrator) {
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
