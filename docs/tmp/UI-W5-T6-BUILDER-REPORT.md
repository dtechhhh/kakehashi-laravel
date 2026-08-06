# UI-W5-T6 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `9fa89ce`

## File diubah
- `app-modules/placement/src/Services/PlacementParticipationService.php` (baru) — `completeContract`, `requestResign/approveResign/rejectResign`, `requestExpel/approveExpel/rejectExpel`, `maybeArchiveContainer` (sinkron).
- `tests/Feature/Placement/PlacementContractStatusTest.php` (baru)

## Command & hasil
- `php artisan test --filter='PlacementContractStatus'` → **6/6 passed (39 assertions)**.
- `vendor/bin/pint` → passed.

## Gate T6
- `Selesai Kontrak` langsung efektif tanpa approval & tanpa catatan; `tanggal_status_final` terisi; audit `PLACEMENT_STATUS_CHANGED`.
- `Mengundurkan Diri`: request (alasan wajib) → approval rutin **tanpa step-up**; `catatan_alasan` = alasan maker; reject wajib catatan.
- `Dikeluarkan`: request → approval **wajib step-up** (`APPROVE_CANDIDATE_EXPEL`, 403 `STEPUP_REQUIRED` tanpa token) + catatan checker wajib (alasan dua lapis); audit `PLACEMENT_EXPEL_*`.
- Formula akhir kontrak inklusif (F-019) diuji: `2026-01-15 + 3 bulan = 2026-04-14`; `2026-09-01 + 12 bulan = 2027-08-31`.
- Terminal non-auto memakai `markAvailable()` → `TERSEDIA`.

## Risiko / catatan
- `catatan_alasan` participant = alasan maker; catatan checker tersimpan di `pending_request.note_checker` (dua lapis utuh).
