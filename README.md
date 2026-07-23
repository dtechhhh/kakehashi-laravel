# Kakehashi

Laravel 13 modular monolith. Wave 0 contains only the runtime and module shells—no Auth or business-domain features.

## Required local runtime

- PHP 8.4 with `pgsql`/`pdo_pgsql` and `redis`
- Composer 2, Node.js 20.19+ (or 22.12+) and npm
- PostgreSQL 18 with `pg_trgm`
- Redis 7, bound to localhost with `maxmemory-policy noeviction`

The application uses Livewire 4, custom Blade, and Tailwind 4. Database behavior is always tested on PostgreSQL, never SQLite.

## Fresh clone setup

Create the local PostgreSQL role plus separate databases `kakehashi` (development) and `kakehashi_test` (testing). The helper configures both databases and `pg_trgm` without printing the generated password:

```bash
bash scripts/setup-pgsql-local.sh
bash scripts/setup-redis-local.sh
```

The PostgreSQL helper creates `.env` from `.env.example` when needed and writes only the generated local database password there. Then install the locked dependencies and run the full gate:

```bash
composer install
npm ci
php artisan key:generate
php artisan migrate --force
composer verify
```

`.env` stays local and must never be committed. Fill only local credentials there; do not place them in this README or any tracked file.

## Daily commands

```bash
composer lint                 # check PHP formatting
composer format               # apply PHP formatting
composer test:migrate-fresh   # reset only kakehashi_test
composer test                 # PHPUnit on PostgreSQL
npm run build                 # production assets
composer verify               # lint, test migration, tests, build
```

`composer test:migrate-fresh` explicitly targets `kakehashi_test`; it is destructive to that test database only. It enables `pg_trgm` through the tracked migration. Do not point it at a production database.

## Modular layout

Modules live under `app-modules/` through Composer path repositories. The six Wave 0 shells are `auth`, `candidates`, `jobs`, `placement`, `guest-access`, and `lookup-data`; each currently has only a ServiceProvider and an empty `src/Public/` boundary.

```bash
php artisan modules:list
```
