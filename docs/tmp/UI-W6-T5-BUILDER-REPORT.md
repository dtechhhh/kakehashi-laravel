# UI-W6-T5 Builder Report

**Status:** DONE
**Branch / commit:** ui-w6-guest @ (lihat commit berikutnya)
**Wave 6 — G3 detail whitelist + audit**

## Ringkasan

`GuestCandidateReadModel::detailForGuest(GuestSession, candidateId)`:

- Whitelist Lampiran C G3: field G2 + nama alphabet/katakana, ketersediaan foto, level bahasa Inggris, kualifikasi mengemudi, riwayat pekerjaan PENUH (nama perusahaan + penanggung TSK/Kumiai + bidang + tanggal), riwayat pendidikan PENUH (jenis + jurusan + nama lembaga + tanggal).
- Dokumen shareable = hanya lookup `is_shareable=true` (skill_ssw / kualifikasi_keahlian_lainnya) dengan `url_file` Drive; dokumen non-shareable tidak pernah keluar.
- Video **default OFF** — tidak pernah keluar walau `video_*_url` terisi (belum ada aktivasi per link).
- Audit `GUEST_DETAIL_VIEWED` pada tiap pembukaan detail: detail `{token_id, candidate_id, container_id, ip}`, actor NULL.
- Detail kandidat anonymized / soft-deleted / di luar container sesi → ditolak generik (`GUEST_DENIED`) tanpa baris audit.

Object Candidate penuh tidak pernah diserialisasi — hanya array whitelist.

## File diubah

- `app-modules/guest-access/src/Public/GuestCandidateReadModel.php` (+ `detailForGuest` dan helper child-query whitelist)
- `tests/Feature/Guest/GuestFixture.php` (+ helper riwayat kerja/pendidikan, bahasa Inggris, SIM, keahlian lain, foto, video)
- `tests/Feature/Guest/GuestCandidateDetailTest.php` (baru; 7 test)

## Perintah & hasil

- `php artisan test tests/Feature/Guest/GuestCandidateDetailTest.php` → 7 passed / 48 assertions
- Regresi seluruh suite Guest → 34 passed / 325 assertions
- `vendor/bin/pint --test app-modules/guest-access tests/Feature/Guest` → passed

## Risiko / catatan

- Signed URL foto (TTL 15 mnt, scoped) belum digenerate di detail — masuk T6 (endpoint foto terpisah yang revalidasi sesi token).
- Video default OFF dicatat sebagai non-blocking gap: aktivasi per link butuh keputusan skema (kolom flag) yang belum ada di DATABASE_SCHEMA — tidak mengarang kolom baru.

## Siap review task? YA
