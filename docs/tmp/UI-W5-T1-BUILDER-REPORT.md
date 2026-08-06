# UI-W5-T1 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

P1 list (dari T0) + P2 detail read: `PlacementQueryService::detail`
(kontainer + participants + pending overlays), Livewire `PlacementDetail`,
view detail dengan badge status, tabel partisipasi + badge
`status_penempatan`, overlay pending per tipe. Arsip/Dibatalkan read-only
(banner, tanpa tombol mutasi). Belum ada mutasi.

## File diubah

- `app-modules/placement/src/Public/PlacementQueryService.php` (+ `detail`,
  `pendingOverlays`; tipe pending kontainer: PC_CREATE, PC_CANCEL_ACTIVE,
  PLACEMENT_BATCH, FORCE_MAJEUR; partisipasi: PLACEMENT_RESIGN, PLACEMENT_EXPEL)
- `app/Livewire/Placement/PlacementDetail.php` (baru, read-only)
- `resources/views/placement/show.blade.php` (baru, wrapper)
- `resources/views/livewire/placement/placement-detail.blade.php` (baru)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.detail_*`,
  `field.*`, `participant_status.*`, `pending.*`, `not_found.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ helper candidate/participant,
  5 test detail)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 13 passed / 33 assertions
- `vendor/bin/pint --test` file tersentuh → passed

## Risiko / catatan

- Helper test: CHECK `pp_force_majeur_chk` mengharuskan baris normal punya
  `source_participation_id` non-null — sudah di-set default 1 di fixture.
- `kewarganegaraan_id` candidate NOT NULL — fixture seed `negara`.
- Tombol keputusan overlay (approve/reject) belum ada di detail; masuk T2b/T3.

## Siap review task? YA
