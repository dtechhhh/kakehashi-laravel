# UI-W7-T5 — Staging Rehearsal (AWS Test VPS) — Builder Report

**Task:** W7-T5 · **Branch:** `ui-w7-hardening` (`f2f2620` + `4f63403`) · **Date:** 2026-08-08
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Environment staging (production-like, test-only)

| Item | Nilai |
| --- | --- |
| Instance | AWS EC2 `98.84.35.94` — Ubuntu 24.04.4 LTS, 2 vCPU / 3.7 GB RAM / 38 GB disk |
| Stack | Nginx + PHP 8.4.24-FPM + PostgreSQL 18.4 (PGDG) + Redis 7 + Composer 2.10 + Supervisor |
| DB | `kakehashi` (test) — bukan production; data sintetis |
| HTTPS | Self-signed (uji, tanpa domain); HTTP → HTTPS 301 |
| App | `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=error`, session/cache/queue Redis |
| R2 | `kakehashi-test-photo` + `kakehashi-test-backup` (bucket terpisah) |

## Yang diverifikasi

1. **Login + smoke**: POST `/login` → `LOGIN_SUCCESS` (JSON kontrak aplikasi) → `/home` 200 menampilkan akun uji (`W7 Staff`).
2. **Security headers**: HSTS, `X-Content-Type-Options`, `X-Frame-Options: DENY`, Referrer-Policy, CSP ketat production (`script-src 'self'` tanpa `unsafe-inline`) — tanpa duplikat.
3. **HTTP→HTTPS redirect**: 301 ke `https://...`.
4. **Guest surface**: gate token → 302 ke daftar; `/guest/candidates` 200 dengan `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, CSP guest; daftar menampilkan NIK `K-2026-00001`.
5. **R2 photo test**: upload → exists → delete OK pada bucket foto (via `FileStorageService`).
6. **Redis**: `maxmemory-policy noeviction`, `maxmemory` 256 MB, bind `127.0.0.1`/`::1`, protected-mode yes; PostgreSQL hanya `127.0.0.1:5432`.
7. **2 queue worker**: `kakehashi-worker_00/01` RUNNING (Supervisor).
8. **Scheduler**: cron `schedule:run` tiap menit + `schedule:list` OK.
9. **Backup cron**: `0 2 * * *` `php artisan backup:database` terpasang.
10. **Firewall**: port 22 terbuka; 80/443 terblokir dari luar oleh security group AWS (Nginx terverifikasi dari dalam VPS; buka 80/443 bila ingin cek via browser).

## Risiko / catatan

- Spek staging (2C/4G) di bawah baseline produksi 4C/8G — hasil representatif untuk rehearsal, bukan beban produksi.
- Sertifikat self-signed hanya untuk uji; produksi wajib Let's Encrypt + domain.
- TLS redirect app-level (`ForceHttps`) + Nginx 301 keduanya aktif.

## Stop condition

Tidak ada yang terpicu.
