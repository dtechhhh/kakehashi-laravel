# UI-W5-T5 — Builder Report (review-at-end in-session, operator-approved deviation)

**Commit:** `88d1d58`

## File diubah
- `app-modules/placement/src/Services/PlacementForceMajeurService.php` (baru)
- `tests/Feature/Placement/PlacementForceMajeurTest.php` (baru)

## Command & hasil
- `php artisan test --filter='PlacementForceMajeur'` → **7/7 passed (33 assertions)**.
- `vendor/bin/pint` → passed.

## Gate T5
- Request: kandidat wajib `TERSEDIA` + `Disetujui` (`FM_STATE`), `kategori_force_majeur_id` + `alasan_force_majeur` wajib (`FM_REASON`), tanpa placement `Bekerja`; pending `FORCE_MAJEUR` + payload snapshot; kandidat & availability tidak berubah.
- Approve **tanpa step-up** (sukses tanpa token): `markInUse()` → `SEDANG_DIPAKAI` (version +1), insert participant `Bekerja` (source null + kategori + alasan), audit `FORCE_MAJEUR_ADDED` dengan `fm_alasan_recorded` (dua lapis).
- Reject → `FM_REJECTED` (kanonik), kandidat tidak berubah, note wajib.
- Rollback saat kandidat tidak lagi `TERSEDIA` (`CANDIDATE_NOT_AVAILABLE`) atau version stale (`CONFLICT`); double approve → `APV_DONE`.

## Risiko / catatan
- Tidak ada event audit untuk submit FM (jejak = pending row + event keputusan). Non-blocking note T8.
