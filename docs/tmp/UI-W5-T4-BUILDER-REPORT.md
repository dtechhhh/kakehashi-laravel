# UI-W5-T4 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

P4 batch normal submit (Maker): panel di detail kontainer Aktif. Picker
eligible = **Siap Dikirim + Sedang Dipakai + tanpa placement Bekerja**
(query read-only, `Tersedia` tidak pernah eligible). Per baris: jenis visa,
tanggal mulai, durasi bulan, end date opsional + uniform fill. Max 50.
Submit → pending `PLACEMENT_BATCH`; source/availability tidak berubah.

## File diubah

- `app-modules/placement/src/Public/PlacementQueryService.php`
  (+ `eligibleSourcesForBatch`)
- `app/Livewire/Placement/PlacementBatchPanel.php` (baru)
- `resources/views/livewire/placement/placement-batch-panel.blade.php` (baru)
- `resources/views/livewire/placement/placement-detail.blade.php`
  (+ embed panel pada Aktif + execute)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.batch.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 4 test batch)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 37 passed / 117 assertions
- `vendor/bin/pint` (fix imports) lalu `--test` → passed

## Risiko / catatan

- Server-side tetap otoritas: `submitBatch` service menolak kandidat
  `Tersedia` (`CANDIDATE_NOT_IN_USE`) meski UI tak menampilkannya (diuji).
- Test kapasitas 50 memakai `set('rows', ...)` 50 baris + toggle ke-51.

## Siap review task? YA
