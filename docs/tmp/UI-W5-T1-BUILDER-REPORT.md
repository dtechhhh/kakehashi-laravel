# UI-W5-T1 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `c6db1a7` (branch `wave-5-placement`)

## File diubah
- `app-modules/placement/src/Enums/PlacementContainerStatus.php` (baru)
- `app-modules/placement/src/Enums/PlacementParticipantStatus.php` (baru)
- `app-modules/placement/src/Services/PlacementContainerService.php` (baru)
- `database/migrations/2026_08_06_000000_create_placement_tables.php` (baru)
- `database/migrations/2026_08_06_000001_allow_placement_maker_cancellation.php` (baru)
- `app/Shared/Approval/PendingRequestService.php` (`cancelByMaker` menerima type/targetType opsional)
- `tests/Feature/Placement/PlacementContainerLifecycleTest.php` (baru)

## Command & hasil
- `php artisan test --filter=PlacementContainerLifecycleTest` → **8/8 passed (33 assertions)** — migration fresh PostgreSQL.
- Regresi `--filter='PendingRequest|MakerCheckerGate|InterviewContainerLifecycleTest'` → **55/55 passed**.
- `vendor/bin/pint` → passed.

## Gate T1
- Draft tanpa kode/pending; kode `P-YYYY-NNNNN` saat submit pertama (counter `container_counter` prefix P, per-tahun JST); `perusahaan_id` immutable (edit ditolak `PC_COMPANY_IMMUTABLE`); cancel hanya pre-Aktif (Draft + Menunggu Approval; Aktif ditolak `PC_NOT_CANCELLABLE`); Maker tidak self-approve (`APV_SELF` via MakerCheckerGate); optimistic `version` → 409; reject wajib catatan; resubmit tanpa perubahan ditolak.

## Risiko / catatan
- Escape `Aktif → Dibatalkan` (GAP-4, count=0 + approval) **tidak** masuk scope handoff T1 ("cancel hanya pre-Aktif"); dicatat sebagai non-blocking note T8.
- Constraint `pending_request_decision_shape` diperluas agar maker-cancellation `PC_CREATE`/`placement_container` sah (pola `IC_CREATE`).
