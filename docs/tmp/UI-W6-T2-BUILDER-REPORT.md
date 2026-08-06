# UI-W6-T2 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — Guest gate / additional-code validation**

## Ringkasan

Modul `guest-access` mulai diisi: gate bertoken dengan validasi berurutan
(token ada → belum kadaluarsa → kontainer `Aktif` → kode tambahan bila ada),
pesan gagal generik tunggal (`GUEST_DENIED`), compare kode constant-time
(`hash_equals`), dan lockout kode 5 gagal → 15 menit (sudah memakai
RateLimiter — lapis rate-limit penuh di T3).

- `GuestAccessService::enter()` — buka sesi Tamu read-only + audit
  `GUEST_ACCESS` + baris `guest_access_log`.
- `GuestAccessService::currentSession()` — revalidasi sesi tiap request
  (link masih Aktif, belum kadaluarsa, kontainer masih Aktif).
- `GuestSession` value object — container id selalu dari sesi token, tidak
  pernah dari parameter klien.
- Migration `guest_access_log` (append-only, FK `guest_link`, kolom `ip`
  INET + `user_agent` — retensi IP mengikuti DATA_RETENTION ~180 hari).
- Channel log `security` baru (storage/logs/security.log): percobaan gagal
  dicatat ke sini, **bukan** `audit_log` (Lampiran A tidak punya enum gagal).

Token mentah dan kode mentah tidak pernah masuk DB, log, atau audit.

## File diubah

- `app-modules/guest-access/src/Exceptions/GuestAccessDeniedException.php` (baru)
- `app-modules/guest-access/src/GuestSession.php` (baru)
- `app-modules/guest-access/src/Services/GuestAccessService.php` (baru)
- `database/migrations/2026_08_07_000000_create_guest_access_log_table.php` (baru)
- `config/logging.php` (+ channel `security`)
- `tests/Feature/Guest/GuestFixture.php` (baru — trait fixture bersama W6)
- `tests/Feature/Guest/GuestGateTest.php` (baru; 10 test)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestGateTest.php`
  → 10 passed / 39 assertions
- `vendor/bin/pint --test` (modul guest-access + config + migration + tests)
  → passed
- Migration baru tervalidasi otomatis via RefreshDatabase PostgreSQL.

## Risiko / catatan

- Sesi Tamu memakai Laravel session default (web middleware) — cocok untuk
  halaman Blade form-less (tanpa Livewire) sehingga CSP Tamu bisa ketat.
- Batas rate-limit sudah terpasang di flow (invalid/valid/code) — bukti test
  lengkap 3 lapis + Redis di T3.

## Siap review task? YA
