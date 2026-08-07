# UI-W6-T1 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — Token/link issuance invariants**

## Ringkasan

W6-T1 = verifikasi + test fokus atas invariant token/link yang sudah
dibangun W4 (`GuestLinkService`):

- Token acak 256-bit (`bin2hex(random_bytes(32))`) digenerate **hanya saat
  approval** Manajer Job; `guest_link` tidak dibuat saat request masih
  pending.
- Hanya `token_hash = sha256(token)` disimpan; token mentah hanya dikembalikan
  sekali ke Checker (transient, tidak pernah di DB/log/audit).
- Satu token terikat tepat satu `interview_container` (hash lookup + scope
  kontainer diverifikasi).
- Kode tambahan juga disimpan sebagai hash (`kode_tambahan_hash`), bukan
  mentah.

Tidak ada perubahan kode produksi: `GuestLinkService` (W4) sudah memenuhi
gate T1. Kerja T1 = suite test yang mengunci invariant (hash-only at rest,
token only-after-approval, one-token-one-container, no-raw-token-in-audit).

## File diubah

- `tests/Feature/Guest/GuestTokenIssuanceTest.php` (baru; 4 test)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestTokenIssuanceTest.php`
  → 4 passed / 46 assertions
- Regresi Jobs (JobsScreens + GuestLink + InterviewContainer +
  InterviewParticipation) → 101 passed / 424 assertions
- `vendor/bin/pint --test tests/Feature/Guest/GuestTokenIssuanceTest.php`
  → passed

## Risiko / catatan

- `guest_access_log` belum ada di DB (migration belum dibuat — masuk T2);
  leak-scan T1 melewati tabel yang belum ada (`Schema::hasTable` guard) dan
  akan aktif penuh setelah T2.
- Audit `GUEST_LINK_APPROVED` membawa `interview_container_id` + version;
  tidak ada token mentah (diverifikasi dengan scan substring seluruh kolom).

## Siap review task? YA
