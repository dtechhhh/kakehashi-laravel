# UI-W7-T2 — Anonimisasi UI (Super Admin + Step-up) — Builder Report

**Task:** W7-T2 · **Branch:** `ui-w7-hardening` · **DB:** `kakehashi_test` (PostgreSQL)
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Perubahan

- `app-modules/candidates/src/Services/CandidateAnonymizationService.php` (baru) — service anonimisasi formal:
  - Super Admin aktif + permission `candidate.anonymize` + actor sesi;
  - `StepUpService::require(ANONYMIZE_PII, candidate, id)` di dalam transaksi eligibility (token sekali pakai, TTL 5 menit);
  - guard Wave 3 direvalidasi dalam transaksi via `CandidateAnonymizationEligibilityService` (availability Tersedia, participation aktif, placement Bekerja, pending terbuka, revision aktif; kandidat + revisi di-lock `FOR UPDATE`);
  - tombstone `pii_anonymized_at` + scramble nama/DOB, null-kan kolom PII, hapus seluruh tabel anak PII (termasuk dokumen aplikasi & metadata foto);
  - foto R2 dihapus after-commit best-effort (gagal hapus file tidak me-rollback bisnis);
  - audit `CANDIDATE_ANONYMIZED` dalam transaksi yang sama.
- `app-modules/jobs/src/Public/InterviewQueryService.php` — probe publik `hasActiveParticipation()`.
- `app-modules/placement/src/Public/PlacementQueryService.php` — probe publik `hasWorkingPlacement()`.
- `app/Livewire/Candidate/CandidateDetail.php` + blade — tombol "Anonimisasi PII" hanya untuk Super Admin; klik tanpa elevasi membuka `StepUpModal` (action/entity scope); `stepup.success` mengeksekusi anonimisasi; error guard ditampilkan; sukses redirect ke daftar kandidat.
- `lang/id/ui.php` + `lang/ja/ui.php` — key tombol + error (ANONYMIZE_FORBIDDEN/FAILED, STEPUP_REQUIRED, PII_ACTIVE/PII_FROZEN).
- `tests/Feature/UI/CandidateAnonymizeScreensTest.php` (baru) — 6 test UI.

## Command & hasil

```text
php artisan test --filter='CandidateAnonymizeScreensTest'
=> passed, 6 tests, 30 assertions

php artisan test --filter='CandidateAnonymizeScreensTest|CandidateScreensTest|CandidateAnonymizationEligibilityTest'
=> passed, 31 tests, 114 assertions

php artisan test --testsuite=Feature --filter='CandidateAnonymizeScreensTest|CandidateScreensTest|CandidateFormScreensTest|CandidateAnonymizationEligibilityTest|RbacNegativeRegressionTest|UserRbacTest'
=> passed, 77 tests, 500 assertions

vendor/bin/pint --test <semua file berubah>
=> passed
```

## Risiko / catatan

- `nama_alphabet` dan `tanggal_lahir` NOT NULL di schema → scramble placeholder (`ANONIM`, `1970-01-01`), bukan null; kandidat anonim tetap dikecualikan dari semua daftar/detail/similarity.
- Token step-up terkonsumsi sebelum guard eligibility final; kandidat terblokir harus re-elevate — konsisten pola UserRbacService.
- Tidak ada route HTTP anonimisasi (Livewire + step-up modal); soft-delete/restore tetap tidak diekspos (diverifikasi 404/405).

## Stop condition

Tidak ada yang terpicu.
