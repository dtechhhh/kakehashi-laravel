# UI-W5-T3 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

Checker review queue untuk kontainer penempatan: daftar pending `PC_CREATE` +
`PC_CANCEL_ACTIVE` (deep link ke detail), approve/reject keduanya **tanpa
step-up**; reject wajib catatan; self-approve ditolak (`APV_SELF`); double
decide / versi basi → banner 409. Semua keputusan lewat
`PlacementContainerService`.

## File diubah

- `app-modules/placement/src/Public/PlacementQueryService.php`
  (+ `reviewQueue`)
- `app/Livewire/Placement/PlacementReviewQueue.php` (baru)
- `resources/views/placement/review.blade.php` (baru, wrapper)
- `resources/views/livewire/placement/placement-review-queue.blade.php` (baru)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.queue.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 6 test queue)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 33 passed / 106 assertions
- `vendor/bin/pint --test` file tersentuh → passed

## Risiko / catatan

- Test listing memakai pending insert langsung (payload wajib untuk
  `PC_CANCEL_ACTIVE`); test keputusan memakai service (submit/request) agar
  state valid.

## Siap review task? YA
