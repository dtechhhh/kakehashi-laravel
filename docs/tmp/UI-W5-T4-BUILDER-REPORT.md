# UI-W5-T4 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `c9e3609`

## File diubah
- `tests/Feature/Placement/PlacementBatchApproveTest.php` (baru). Implementasi `approveBatch`/`rejectBatch` ada di `PlacementBatchService` (commit T3, satu service).

## Command & hasil
- `php artisan test --filter='PlacementBatch'` → **12/12 passed (53 assertions)**.
- Full suite sementara → lihat T8 (610 tests, 609 passed, 1 skipped env-gated).
- `vendor/bin/pint` → passed.

## Gate T4
- Transfer atomik: kontainer di-lock, source `FOR UPDATE` (urutan konsisten), revalidasi pending/source/candidate dalam satu transaksi; insert `placement_participants` (`Bekerja`, `source_participation_id`); source → `Terkirim`; availability **tetap `SEDANG_DIPAKAI`** dengan `assertInUse` (bukan `markInUse`; version kandidat tidak berubah → tidak ada window `Tersedia`).
- 1 row invalid/stale → **rollback seluruh batch** (0 participant, semua source tetap `Siap Dikirim`, pending tetap pending, tanpa audit).
- Double approve → `APV_DONE` 409; stale source version → `CONFLICT` 409; Maker self-approve → `APV_SELF`.
- `rejectBatch` (PRD §6.4 "disetujui atau ditolak seluruhnya"): tanpa mutasi bisnis, audit `BATCH_REJECTED`.
- Dua kontainer menarik kandidat sama: batch kedua gagal revalidasi source (`SOURCE_NOT_READY`) → rollback.

## Risiko / catatan
- `BATCH_REJECTED` adalah ekstensi enum audit non-breaking (preseden `FM_REJECTED` F-016).
