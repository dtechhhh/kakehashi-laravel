# UI-W7-T5 — Staging Rehearsal — Builder Report (BLOCKED)

**Task:** W7-T5 · **Branch:** `ui-w7-hardening` · **Status:** **BLOCKED — menunggu checkpoint operator**

## Checkpoint operator (handoff §4)

```text
CHECKPOINT OPERATOR — T5 — konfirmasi staging = ephemeral VPS test ATAU staging lokal
production-like; kredensial test-only; domain/email/Drive policy/TOTP test disiapkan;
bukan production DB.
```

Checkpoint sudah disampaikan ke operator pada 2026-08-07; belum ada `LANJUT`/konfirmasi. Sesuai handoff: **tidak ada infrastruktur yang disentuh / tidak ada improvisasi**.

## Yang sudah siap (tanpa menyentuh staging)

- T1–T4 hijau di branch `ui-w7-hardening` (`e0be2a5`).
- Komponen rehearsal sudah teruji lokal:
  - login + RBAC + step-up (Auth suite, 341 test UI/Auth/Audit hijau);
  - Guest headers/whitelist/PII (GuestSurface + GuestPiiLeak hijau);
  - Redis lokal: `noeviction`, bind localhost, protected-mode, maxmemory ≤1GB (`RedisEnvironmentTest` hijau);
  - security headers + HTTPS redirect production-like (`SecurityHardeningTest` hijau);
  - anonimisasi E2E (`CandidateAnonymizationTest` hijau).

## Yang belum bisa diverifikasi (butuh staging)

- Nginx + PHP-FPM + PostgreSQL 18 + Redis production-like di satu host.
- Login/smoke production-like, read data hasil restore (T6), R2 photo test, Guest headers/whitelist di server nyata.
- Firewall 22/80/443, `APP_DEBUG=false` di environment production-like.
- Dua Redis queue worker + scheduler hidup.

## Aksi operator

Balas `LANJUT` + pilihan staging (ephemeral VPS test atau staging lokal production-like) + akses/kredensial test-only, atau konfirmasi T5 ditunda.
