# UI-W6-UI-T1 Builder Report (U1 — Guest link management)

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)

## Ringkasan

Panel pengelolaan link Tamu (Maker request / Checker approve-reject) sudah dibangun Wave 4 di `InterviewDetail`; U1 memoles + mengunci invariant token-once:

- Panel approval kini menampilkan **URL publik lengkap** (`/guest/{token}`) untuk dikirim via email, bukan hanya token mentah.
- Test baru memastikan: token hanya muncul setelah approval; DB hanya menyimpan SHA-256; token **hilang setelah reload** (mount ulang komponen → `guestToken` null, token tidak dirender ulang); reject tidak menghasilkan token/baris `guest_link`.

## File diubah

- `resources/views/livewire/jobs/interview-detail.blade.php` (panel token → URL publik lengkap)
- `tests/Feature/UI/GuestLinkManagementScreensTest.php` (baru; 3 test)

## Perintah & hasil

- `php artisan test tests/Feature/UI/GuestLinkManagementScreensTest.php` → 3 passed / 10 assertions

## Risiko / catatan

- Menampilkan URL penuh = menampilkan token (URL berisi token); konsisten dengan mekanisme "token sekali" — URL hanya tampil pada respons approval.

## Siap review task? YA
