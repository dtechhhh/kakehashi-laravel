# UI-W7-T4 — Security Hardening (Headers/HTTPS/Debug/Redis/Log) — Builder Report

**Task:** W7-T4 · **Branch:** `ui-w7-hardening` · **DB:** `kakehashi_test` (PostgreSQL)
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Perubahan

- `app/Http/Middleware/SecurityHeaders.php` (baru) — baseline security headers semua response web: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (kamera/mikro/geolokasi off), HSTS 2 tahun; CSP ketat (`default-src 'self'`, `script-src 'self'` tanpa `unsafe-inline`, `object-src 'none'`, `base-uri/form-action 'self'`) hanya di production. Tidak menimpa header GuestSurface yang lebih ketat (`no-referrer`, CSP guest, no-store).
- `app/Http/Middleware/ForceHttps.php` (baru) — redirect HTTP→HTTPS 301 di production.
- `bootstrap/app.php` — daftarkan kedua middleware di grup `web`.
- `.env.example` — template production-safe: `APP_DEBUG=false`, `LOG_LEVEL=error` (REDIS_HOST sudah `127.0.0.1`); seluruh key secret tetap kosong.
- `tests/Feature/SecurityHardeningTest.php` (baru) — 6 test: production header + CSP ketat; HTTP→HTTPS 301; header di luar production; template env debug off/log error/tanpa secret; `.env`/`.env.migrator` tidak ter-commit; error page debug-off tanpa bocor pesan/path.
- Infra Redis `noeviction` + bind localhost + protected-mode + maxmemory ≤1GB diverifikasi live oleh `RedisEnvironmentTest` yang sudah ada; audit immutable dijamin `AuditImmutableTest`.

## Command & hasil

```text
php artisan test --filter='SecurityHardeningTest|GuestPublicScreensTest|GuestSurfaceTest|RedisEnvironmentTest'
=> passed, 20 tests, 169 assertions

php artisan test tests/Feature/UI tests/Feature/Auth tests/Feature/Audit
=> passed, 341 tests, 1937 assertions

vendor/bin/pint --test <semua file berubah>
=> passed
```

## Risiko / catatan

- CSP production `script-src 'self'` diasumsikan kompatibel Livewire 4 (semua aset self-hosted, tanpa inline script); verifikasi browser final tetap bagian T5 rehearsal.
- Firewall 22/80/443 dan dua worker/scheduler adalah verifikasi infrastruktur → T5 (staging).
- `.env` lokal tetap dev; template sekarang aman untuk disalin.

## Stop condition

Tidak ada yang terpicu.
