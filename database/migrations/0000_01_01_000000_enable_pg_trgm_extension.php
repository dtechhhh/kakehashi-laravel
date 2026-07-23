<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable PostgreSQL pg_trgm for candidate name similarity (BR-DUP / DATABASE_SCHEMA §2).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'Kakehashi requires PostgreSQL with pg_trgm. SQLite and other drivers are not supported for database migrations.'
            );
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    /**
     * Reverse the migrations.
     *
     * Extension is left in place: dropping pg_trgm is not required for rollbacks
     * and may fail without superuser rights on shared clusters.
     */
    public function down(): void
    {
        //
    }
};
