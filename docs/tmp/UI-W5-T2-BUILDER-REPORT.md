# UI-W5-T2 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `b7c7ad9`

## File diubah
- `tests/Feature/Placement/PlacementSchemaTest.php` (baru) — verifikasi skema PostgreSQL.

## Command & hasil
- `php artisan test --filter=PlacementSchemaTest` → **6/6 passed (18 assertions)**; migration fresh PostgreSQL via RefreshDatabase.
- `vendor/bin/pint` → passed.

## Gate T2
- `pp_force_majeur_chk`: `(source_participation_id IS NULL) = (kategori_force_majeur_id IS NOT NULL AND alasan_force_majeur IS NOT NULL)` — 3 kasus pelanggaran ditolak DB, pasangan normal/FM valid tersimpan.
- Partial unique `uq_pp_one_active_work` (candidate_id WHERE status_penempatan='Bekerja'): row kedua `Bekerja` ditolak; terminal tidak mengisi slot.
- Index pagination `idx_pp_container (placement_container_id, id)` + `idx_pp_candidate` terverifikasi via `pg_indexes`.
- Tanpa FK lintas-modul pada `candidate_id` / `source_participation_id`.
- Status CHECK 4 nilai `status_penempatan`.

## Risiko / catatan
- Skema dibuat di migrasi T1 (`2026_08_06_000000_create_placement_tables.php`); T2 = verifikasi constraint + index di PostgreSQL.
