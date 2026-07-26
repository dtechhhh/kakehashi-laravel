<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Tests\TestCase;

/**
 * Runtime role is non-owner: SELECT/INSERT on audit_log only (DATABASE_SCHEMA §5.7).
 * The migrator/owner keeps DML privileges but stays blocked by the immutability trigger.
 */
class AuditRuntimePrivilegeTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_role_has_select_insert_but_not_update_delete_truncate_on_audit_log(): void
    {
        $row = DB::selectOne(
            "SELECT current_user AS usr,
                    has_table_privilege(current_user, 'audit_log', 'SELECT') AS can_select,
                    has_table_privilege(current_user, 'audit_log', 'INSERT') AS can_insert,
                    has_table_privilege(current_user, 'audit_log', 'UPDATE') AS can_update,
                    has_table_privilege(current_user, 'audit_log', 'DELETE') AS can_delete,
                    has_table_privilege(current_user, 'audit_log', 'TRUNCATE') AS can_truncate"
        );

        $this->assertSame(
            (string) config('database.connections.pgsql.username'),
            $row->usr,
            'Default connection must be the runtime role'
        );
        $this->assertTrue($this->toBool($row->can_select));
        $this->assertTrue($this->toBool($row->can_insert));
        $this->assertFalse($this->toBool($row->can_update));
        $this->assertFalse($this->toBool($row->can_delete));
        $this->assertFalse($this->toBool($row->can_truncate));
    }

    public function test_runtime_can_insert_audit_log_via_logger(): void
    {
        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::LOGOUT,
            entityType: 'user',
            entityId: 1,
            detail: ['user_id' => 1, 'marker' => 'runtime-insert-ok'],
        );

        $this->assertDatabaseHas('audit_log', [
            'id' => $row->id,
            'action_type' => ActionType::LOGOUT->value,
        ]);
    }

    public function test_runtime_update_and_delete_are_denied_by_privilege(): void
    {
        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::LOGOUT,
            entityType: 'user',
            entityId: 2,
            detail: ['user_id' => 2],
        );

        // PostgreSQL reports insufficient_privilege before the trigger when UPDATE is revoked.
        DB::statement('SAVEPOINT runtime_update_priv');
        try {
            DB::table('audit_log')->where('id', $row->id)->update(['entity_type' => 'tampered']);
            $this->fail('Runtime UPDATE must be denied by privilege');
        } catch (QueryException $e) {
            $this->assertTrue(
                $this->isPrivilegeDenied($e),
                'UPDATE must be denied by privilege (insufficient_privilege), got: '.$e->getMessage()
            );
            DB::statement('ROLLBACK TO SAVEPOINT runtime_update_priv');
        }

        DB::statement('SAVEPOINT runtime_delete_priv');
        try {
            DB::table('audit_log')->where('id', $row->id)->delete();
            $this->fail('Runtime DELETE must be denied by privilege');
        } catch (QueryException $e) {
            $this->assertTrue(
                $this->isPrivilegeDenied($e),
                'DELETE must be denied by privilege (insufficient_privilege), got: '.$e->getMessage()
            );
            DB::statement('ROLLBACK TO SAVEPOINT runtime_delete_priv');
        }

        $this->assertDatabaseHas('audit_log', ['id' => $row->id]);
    }

    public function test_migrator_update_is_blocked_by_trigger_and_probe_row_is_rolled_back(): void
    {
        $migrator = $this->migratorConnection();
        $marker = 'migrator-update-probe-'.bin2hex(random_bytes(6));

        // Probe row is created and mutated inside one migrator transaction: the row is
        // visible to the UPDATE, so the trigger really fires (0 rows would prove nothing).
        $migrator->beginTransaction();
        try {
            $id = $this->insertMigratorProbe($migrator, $marker);
            $this->assertSame(1, $this->countMigratorProbe($migrator, $marker));

            try {
                $migrator->table('audit_log')->where('id', $id)->update(['entity_type' => 'tampered']);
                $this->fail('Migrator UPDATE must hit the immutability trigger');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
            }
        } finally {
            $migrator->rollBack();
        }

        $this->assertSame(
            0,
            $this->countMigratorProbe($migrator, $marker),
            'Migrator probe row must not survive the rolled back transaction'
        );
    }

    public function test_migrator_delete_is_blocked_by_trigger_and_probe_row_is_rolled_back(): void
    {
        $migrator = $this->migratorConnection();
        $marker = 'migrator-delete-probe-'.bin2hex(random_bytes(6));

        $migrator->beginTransaction();
        try {
            $id = $this->insertMigratorProbe($migrator, $marker);
            $this->assertSame(1, $this->countMigratorProbe($migrator, $marker));

            try {
                $migrator->table('audit_log')->where('id', $id)->delete();
                $this->fail('Migrator DELETE must hit the immutability trigger');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
            }
        } finally {
            $migrator->rollBack();
        }

        $this->assertSame(
            0,
            $this->countMigratorProbe($migrator, $marker),
            'Migrator probe row must not survive the rolled back transaction'
        );
    }

    public function test_pgsql_migrator_uses_dedicated_role_without_runtime_fallback(): void
    {
        $runtimeUser = (string) config('database.connections.pgsql.username');
        $migratorUser = (string) config('database.connections.pgsql_migrator.username');

        $this->assertNotSame('', $migratorUser, $this->missingMigratorCredentialsMessage());
        $this->assertNotSame($runtimeUser, $migratorUser, 'Migrator role must not be the runtime role');

        // No env fallback: the connection resolves DB_MIGRATOR_USERNAME and nothing else.
        $this->assertSame(env('DB_MIGRATOR_USERNAME'), config('database.connections.pgsql_migrator.username'));

        $this->assertSame($runtimeUser, $this->currentUser(DB::connection()));
        $this->assertSame($migratorUser, $this->currentUser(DB::connection('pgsql_migrator')));
    }

    private function migratorConnection(): Connection
    {
        $this->assertNotSame(
            '',
            (string) config('database.connections.pgsql_migrator.username'),
            $this->missingMigratorCredentialsMessage()
        );

        return DB::connection('pgsql_migrator');
    }

    /**
     * actor_id stays null: users rows live in the runtime test transaction and are
     * invisible to this separate migrator session.
     */
    private function insertMigratorProbe(Connection $migrator, string $marker): int
    {
        return (int) $migrator->table('audit_log')->insertGetId([
            'actor_id' => null,
            'actor_role_snapshot' => null,
            'action_type' => ActionType::LOGOUT->value,
            'entity_type' => 'migrator_probe',
            'entity_id' => null,
            'detail' => json_encode(['marker' => $marker], JSON_THROW_ON_ERROR),
        ]);
    }

    private function countMigratorProbe(Connection $migrator, string $marker): int
    {
        $row = $migrator->selectOne(
            "SELECT count(*) AS total FROM audit_log WHERE detail->>'marker' = ?",
            [$marker]
        );

        return (int) $row->total;
    }

    private function currentUser(Connection $connection): string
    {
        return (string) $connection->selectOne('SELECT current_user AS usr')->usr;
    }

    private function missingMigratorCredentialsMessage(): string
    {
        return 'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function isPrivilegeDenied(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '42501'
            || str_contains(strtolower($e->getMessage()), 'permission denied')
            || str_contains(strtolower($e->getMessage()), 'insufficient_privilege');
    }
}
