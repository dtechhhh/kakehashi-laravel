# kakehashi-laravel

Laravel modular app for human management (Kakehashi).

## Runtime (W0-T2)

- PHP 8.4 (verified: 8.4.21)
- Laravel 13 (locked: 13.21.1)

## PostgreSQL (W0-T3)

- PostgreSQL 18 + extension `pg_trgm` (required; SQLite is not used for database behavior).
- PHP extension: `pdo_pgsql` / `pgsql` (`php8.4-pgsql` on Ubuntu).
- Separate databases: **`kakehashi`** (dev, `.env`) and **`kakehashi_test`** (PHPUnit, `phpunit.xml`).
- After creating both DBs and a role matching `.env`, run `php artisan migrate` (migration enables `pg_trgm`).

Redis, modular layout, and full fresh-clone tooling docs land in later Wave 0 tasks.
