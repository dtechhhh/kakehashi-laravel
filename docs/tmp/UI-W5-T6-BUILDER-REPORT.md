# UI-W5-T6 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

P5 Force-Majeur: panel terpisah "Tambah langsung / Force-Majeur" pada detail
kontainer Aktif (visual amber = jalur luar biasa). Candidate picker =
**Tersedia + Disetujui**; kategori lookup + alasan free-text **keduanya wajib**;
visa + tanggal + durasi. Submit → pending `FORCE_MAJEUR`, kandidat tetap
Tersedia. Checker approve di queue **tanpa step-up** → Bekerja +
`markInUse` (Sedang Dipakai) + `source_participation_id` NULL; reject →
trail `FM_REJECTED`, kandidat tetap Tersedia.

## File diubah

- `app-modules/placement/src/Public/PlacementQueryService.php`
  (+ `eligibleForceMajeurCandidates`; queue + `FORCE_MAJEUR`)
- `app/Livewire/Placement/PlacementForceMajeurPanel.php` (baru)
- `resources/views/livewire/placement/placement-force-majeur-panel.blade.php`
  (baru)
- `resources/views/livewire/placement/placement-detail.blade.php` (+ embed)
- `app/Livewire/Placement/PlacementReviewQueue.php` (`decide()` + FM)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.force_majeur.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 5 test FM)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 44 passed / 150 assertions
- `php artisan test --filter=Placement` (domain) → 98 passed / 363 assertions
- `vendor/bin/pint --test` file tersentuh → passed

## Risiko / catatan

- `version` harus dikirim sebagai `$options` (arg ke-4) service FM, bukan di
  payload — sudah diperbaiki di panel dan test.

## Siap review task? YA
