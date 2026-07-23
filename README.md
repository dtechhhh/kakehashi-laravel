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
- Optional helper: `bash scripts/setup-pgsql-local.sh` (needs sudo once).

## Redis (W0-T4)

- Redis **7.4.x** co-located for **cache, session, queue, and rate-limit** (not business source of truth).
- Authority/ARCHITECTURE baseline: Redis **7.x**; local pin uses official APT **7.4.x**.
- PHP extension: `redis` (`php8.4-redis`) with `REDIS_CLIENT=phpredis`.
- Template defaults (`.env.example`): `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SESSION_LIFETIME=30`.
- Host must stay local: `REDIS_HOST=127.0.0.1`.
- Server policy: `bind 127.0.0.1 ::1`, `protected-mode yes`, `maxmemory 1gb`, `maxmemory-policy noeviction`.
- Queue Redis connection uses `after_commit=true` so email/queue dispatch cannot roll back business DB commits.
- Copy `.env.example` → `.env`, generate `APP_KEY`, fill local secrets. **Never commit `.env` or real credentials.**

### Install / upgrade (Ubuntu, official packages.redis.io)

Docs: https://redis.io/docs/latest/operate/oss_and_stack/install/install-stack/apt/

One-shot helper (sudo once):

```bash
bash scripts/setup-redis-local.sh
```

Or manually: add the Redis APT key/list, pin packages to `6:7.4.*`, install `redis-server` + `redis-tools`, then set in `/etc/redis/redis.conf`:

```text
bind 127.0.0.1 ::1
protected-mode yes
maxmemory 1gb
maxmemory-policy noeviction
```

```bash
sudo systemctl enable --now redis-server
redis-cli ping
redis-cli INFO server | grep '^redis_version:'
redis-cli CONFIG GET bind
redis-cli CONFIG GET protected-mode
redis-cli CONFIG GET maxmemory
redis-cli CONFIG GET maxmemory-policy
```

Expected: `PONG`, Redis **7.x**, local bind only, `maxmemory=1073741824`, policy `noeviction`.

Modular layout and full fresh-clone tooling docs land in later Wave 0 tasks.
