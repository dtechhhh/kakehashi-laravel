# UI-W6-T4 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — G2 daftar pseudonim**

## Ringkasan

`GuestCandidateReadModel::listForContainer(GuestSession)` — read-model server-side untuk daftar G2:

- Identifier = Nomor Induk `K-YYYY-NNNNN`; tanpa nama, foto, riwayat kerja/pendidikan, tanpa field HIDE.
- Field G2: id (untuk link detail G3), nomor_induk, umur (computed), jenis kelamin (M/F kanonik), kewarganegaraan (label_ja), level bahasa Jepang (jenis + skor), kualifikasi SSW (label_ja), bidang diminati (label_ja).
- Kandidat `pii_anonymized_at` / `deleted_at` dikecualikan; scope selalu dari sesi token (container id), bukan parameter klien.
- Pagination server-side default 25; param page divalidasi.
- Sort allowlist ketat: nomor_induk / umur / kewarganegaraan / bidang_diminati. Nama, foto, lembaga, perusahaan, dan kolom HIDE **bukan** parameter sort; param tak dikenal diabaikan.

## File diubah

- `app-modules/guest-access/src/Public/GuestCandidateReadModel.php` (baru; `listForContainer` — T5 menambah `detailForGuest`)
- `tests/Feature/Guest/GuestFixture.php` (+ helper kandidat/partisipasi, qualifikasi Jepang/SSW, bidang diminati)
- `tests/Feature/Guest/GuestCandidateListTest.php` (baru; 6 test)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestCandidateListTest.php` → 6 passed / 34 assertions
- Regresi seluruh suite Guest → 27 passed / 277 assertions
- `vendor/bin/pint --test app-modules/guest-access tests/Feature/Guest` → passed

## Risiko / catatan

- Satu kandidat bisa punya >1 baris partisipasi di kontainer yang sama (masuk lagi setelah terminal): list memakai baris partisipasi terakhir (`MAX(id)`) per kandidat.
- G2 tetap memakai id kandidat internal untuk link detail; scope dipaksa ulang di G3 (T5) — id bukan data yang di-whitelist.

## Siap review task? YA
