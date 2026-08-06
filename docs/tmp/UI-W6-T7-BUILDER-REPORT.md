# UI-W6-T7 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — Response PII leak suite**

## Ringkasan

`GuestPiiLeakTest` — suite leak level-response:

- **G2 (HTTP)**: halaman list tidak memuat nama, foto, email, telepon, Line ID,
  alamat, tanggal lahir mentah, paspor, keluarga, dokumen peserta, fisik/
  kesehatan, IQ/psikotes, video, catatan internal, maupun kolom internal
  (`status_approval`, `parent_candidate_id`, `created_by`, `version`).
- **G3 (HTTP)**: whitelist v0.3.11 tetap tampil (nama alphabet/katakana,
  perusahaan riwayat kerja, nama lembaga pendidikan), sementara SEMUA field
  HIDE di atas tetap absen.
- **Sort/filter**: parameter sort/filter kolom PII/HIDE diabaikan (tidak
  error, tidak memengaruhi hasil).
- **Log**: token mentah tidak pernah masuk security log (dibuktikan dengan
  diff isi file log sebelum/sesudah percobaan), `guest_access_log`, maupun
  audit (scan substring seluruh kolom).
- **Serialization**: payload G2/G3 ter-serialize tidak membawa field internal
  Candidate (`status_approval`, `status_ketersediaan`, `parent_candidate_id`,
  `created_by`, `approved_by`, `version`, `deleted_at`, `pii_anonymized_at`,
  kontak).

## File diubah

- `tests/Feature/Guest/GuestPiiLeakTest.php` (baru; 5 test, 81 assertions)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestPiiLeakTest.php` → 5 passed / 81 assertions
- Regresi seluruh suite Guest → 47 passed / 472 assertions
- `vendor/bin/pint --test tests/Feature/Guest` → passed

## Risiko / catatan

- Fixture memakai nilai PII unik (mis. `LEAK-*`) agar deteksi substring tidak
  false-positive terhadap teks umum.
- Security log bersifat append-only file; tes membandingkan konten baru sejak
  baseline.

## Siap review task? YA
