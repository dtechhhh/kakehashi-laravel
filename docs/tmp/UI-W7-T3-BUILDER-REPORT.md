# UI-W7-T3 — Anonimisasi E2E (Tombstone/File/Audit/Guest) — Builder Report

**Task:** W7-T3 · **Branch:** `ui-w7-hardening` · **DB:** `kakehashi_test` (PostgreSQL)
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Perubahan

- `tests/Feature/Candidates/CandidateAnonymizationTest.php` (baru) — suite E2E anonimisasi:
  - **Eligible penuh:** semua kolom PII main di-scramble/null, seluruh 14 tabel anak PII (termasuk `candidate_document` URL Drive dan `candidate_photo`) kosong, foto R2 benar-benar terhapus after-commit, audit `CANDIDATE_ANONYMIZED` (actor, role snapshot, candidate_id, nomor_induk, tanpa URL dokumen mentah).
  - **Guard nyata lintas modul:** availability bukan Tersedia, participation aktif (`hasActiveParticipation`), placement `Bekerja` (`hasWorkingPlacement`), pending terbuka, revision aktif → `PII_ACTIVE`, tombstone tidak terisi.
  - **Step-up:** missing dan wrong-scope → 403 `STEPUP_REQUIRED`; token benar → sukses.
  - **Otorisasi:** Staf Input / Approver / Manajer Job / Super Admin Nonaktif → `CANDIDATE_ANONYMIZE_FORBIDDEN`.
  - **File failure:** R2 delete dipaksa gagal → tombstone + audit tetap commit (best-effort, tidak rollback).
  - **Irreversible & Guest:** detail internal null, photo URL ditolak, reveal dokumen ditolak, revision ditolak; kandidat hilang dari G2 list dan detail G3 langsung ditolak generik.
- Test memakai real commit (`connectionsToTransact = []`) agar after-commit photo cleanup benar-benar dieksekusi; fixture memakai kode unik dan teardown membersihkan tabel (pola `CandidatePhotoRevisionLifecycleTest`).

## Command & hasil

```text
php artisan test --filter='CandidateAnonymizationTest'
=> passed, 6 tests, 63 assertions

php artisan test --testsuite=Feature --filter='CandidateAnonymizationTest|CandidateAnonymizeScreensTest|CandidateAnonymizationEligibilityTest|GuestCandidateListTest|GuestCandidateDetailTest|GuestPiiLeakTest|GuestSurfaceTest'
=> passed, 48 tests, 360 assertions

php artisan test tests/Feature/Candidates tests/Feature/Guest
=> passed, 182 passed / 1 skipped (R2 live smoke env-gated), 1667 assertions

vendor/bin/pint --test <file baru>
=> passed
```

## Risiko / catatan

- Scramble `nama_alphabet='ANONIM'`, `tanggal_lahir='1970-01-01'` karena kolom NOT NULL; semua query operasional sudah mengecualikan `pii_anonymized_at IS NOT NULL`.
- Prosedur Drive manual (hapus file Google Drive di luar aplikasi) tetap tanggung jawab operator sesuai `DATA_RETENTION_AND_PRIVACY §7.3`; aplikasi sudah mengosongkan semua URL.
- Audit immutable diverifikasi ulang di T8 (role app tidak punya UPDATE/DELETE `audit_log`).

## Stop condition

Tidak ada yang terpicu.
