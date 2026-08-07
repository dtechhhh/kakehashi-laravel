# UI-W6-T6 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — Aset foto scoped + headers surface Tamu**

## Ringkasan

- `GuestPhotoService::signedPhotoUrl(GuestSession, candidateId)` — signed URL foto R2 hanya dalam sesi token valid, scoped ke container sesi, kandidat tidak anonymized/soft-deleted, TTL 15 menit (`FileStorageService` default 900 dtk). Tanpa sesi / di luar scope / tanpa foto → ditolak generik.
- `GuestSurface` middleware (alias `guest.surface`): locale **JP-only**, `Cache-Control: no-store, private`, HSTS, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy no-referrer, Permissions-Policy minimal, CSP ketat (halaman Tamu Blade form-less, tanpa Livewire → tidak butuh nonce).
- Route Tamu + `GuestAccessController`: gate (`GET /guest/{token}` auto-enter bila tanpa kode; form kode bila `requiresCode`), submit kode (POST), daftar G2, detail G3, foto scoped. Halaman gagal → view denied seragam (404; 429 saat throttle) tanpa membedakan alasan.
- Views minimal guest (layout JP, code form, denied, list, detail) + lang keys `ui.guest.*` (ja + id).
- Dokumen hanya lewat whitelist read-model (link Drive shareable); endpoint foto tidak pernah melayani dokumen.

## File diubah

- `app-modules/guest-access/src/Services/GuestPhotoService.php` (baru)
- `app/Http/Middleware/GuestSurface.php` (baru)
- `app/Http/Controllers/GuestAccessController.php` (baru)
- `routes/web.php` (+ group guest.surface; static route sebelum `{token}`)
- `bootstrap/app.php` (+ alias middleware)
- `resources/views/layouts/guest.blade.php` + `resources/views/guest/{code,denied,candidates,detail}.blade.php` (baru)
- `lang/ja/ui.php`, `lang/id/ui.php` (+ `ui.guest.*`)
- `tests/Feature/Guest/GuestSurfaceTest.php` (baru; 8 test)
- `tests/Feature/Guest/GuestFixture.php` (approveLink tidak lagi meninggalkan auth state — `Auth::login`/`logout` scoped)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestSurfaceTest.php` → 8 passed / 66 assertions
- Regresi Guest + GuestLinkApprovalConcurrency → 45 passed / 417 assertions
- `vendor/bin/pint --test app app-modules/guest-access tests/Feature/Guest` → passed

## Risiko / catatan

- CSP `style-src 'unsafe-inline'` dipertahankan untuk kompatibilitas Blade; `script-src 'self'` ketat (tanpa inline JS di halaman Tamu).
- Sesi Tamu memakai session Laravel default (web group); CSRF otomatis dimatikan hanya di env testing.
- URL foto lokal test memakai pseudo-signed `r2.local`; produksi R2 custom domain/proxy adalah keputusan DEPLOYMENT (di luar scope Wave 6).

## Siap review task? YA
