# UI-W5-T3 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `b1fbda1`

## File diubah
- `app-modules/jobs/src/Public/InterviewPlacementTransferService.php` (baru) — kontrak lintas-modul Jobs (API_CONTRACTS §3.4): `assertReadyForPlacement` (snapshot, opsional `FOR UPDATE`), `markSentForPlacement` (`Siap Dikirim → Terkirim`).
- `app-modules/placement/src/Support/ContractDates.php` (baru) — F-019 formula inklusif.
- `app-modules/placement/src/Services/PlacementBatchService.php` (baru) — `submitBatch`.
- `app/Shared/Audit/ActionType.php` — tambah `BATCH_REJECTED` (non-breaking, PRD §6.4 batch boleh ditolak).
- `app/Shared/Approval/PendingRequestService.php` — `submit()` auditAction nullable (flow FM/batch tidak punya event submit kanonik).
- `tests/Feature/Placement/PlacementFixture.php` + `PlacementBatchSubmitTest.php` (baru).

## Command & hasil
- `php artisan test --filter='PlacementBatch'` → **12/12 passed (53 assertions)** (bersama T4).
- Regresi `--filter='Interview|Participation|PendingRequest|MakerChecker|Availability|Placement'` → **129/129 passed**.
- `vendor/bin/pint` → passed.

## Gate T3
- Maksimum **50** row (`PC_BATCH_TOO_LARGE`); duplikat candidate/source ditolak; submit membuat pending `PLACEMENT_BATCH` dengan payload snapshot per-kandidat (source version, candidate version, visa, tanggal, durasi, end-date); **source & availability tidak berubah**; kontainer harus `Aktif`; version container diverifikasi.

## Risiko / catatan
- Submit FM/batch tidak menulis event audit submit (tidak ada event kanonik; jejak = row `pending_request` + audit keputusan). Dicatat sebagai non-blocking note T8.
