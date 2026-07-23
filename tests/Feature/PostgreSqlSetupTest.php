<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_is_postgresql_18_not_sqlite(): void
    {
        $this->assertSame(
            'pgsql',
            DB::getDriverName(),
            'Database-behavior tests must use PostgreSQL, not SQLite.'
        );

        $this->assertNotSame('sqlite', config('database.default'));
        $this->assertNotSame('sqlite', DB::connection()->getDriverName());

        $serverVersion = (int) DB::selectOne('SHOW server_version_num')->server_version_num;

        $this->assertSame(18, intdiv($serverVersion, 10000));
    }

    public function test_dev_and_test_databases_are_separated(): void
    {
        $testDatabase = (string) config('database.connections.pgsql.database');

        $this->assertNotSame(
            '',
            $testDatabase,
            'Test database name must be configured.'
        );

        $this->assertSame(
            'kakehashi_test',
            $testDatabase,
            'PHPUnit must use the dedicated kakehashi_test database.'
        );

        $this->assertNotSame(
            'kakehashi',
            $testDatabase,
            'Test must not share the development database name.'
        );
    }

    public function test_pg_trgm_extension_is_enabled(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 AS ok FROM pg_extension WHERE extname = 'pg_trgm'"
        );

        $this->assertNotNull($exists, 'pg_trgm extension must be enabled on the test database.');
    }

    public function test_pg_trgm_similarity_function_works(): void
    {
        $row = DB::selectOne('SELECT similarity(?, ?) AS score', ['kakehashi', 'kakehashi']);

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1.0, (float) $row->score, 0.0001);

        $partial = DB::selectOne('SELECT similarity(?, ?) AS score', ['kakehashi', 'kakehash']);
        $this->assertNotNull($partial);
        $this->assertGreaterThanOrEqual(0.4, (float) $partial->score);
    }
}
