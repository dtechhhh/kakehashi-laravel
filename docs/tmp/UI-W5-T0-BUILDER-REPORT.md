# UI-W5-T0 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

Scaffold Placement UI: routes `/placements`, `/placements/create`,
`/placements/review`, `/placements/{id}`, `/placements/{id}/edit` dengan
middleware ability `placement.view|execute|review`; nav "Penempatan" +
"Antrian Penempatan"; read-only `PlacementQueryService::paginate` (25);
Livewire `PlacementIndex` P1 list kosong berjalan; lang ID + JA.

Belum ada mutasi; belum ada tombol aksi selain Lihat/Buat.

## File diubah

- `routes/web.php` (5 route placement)
- `config/navigation.php` (2 item nav)
- `lang/id/ui.php`, `lang/ja/ui.php` (nav + `ui.placement.*`)
- `app-modules/placement/src/Public/PlacementQueryService.php` (baru, paginate)
- `app/Livewire/Placement/PlacementIndex.php` (baru)
- `resources/views/placement/index.blade.php` (baru, wrapper)
- `resources/views/livewire/placement/placement-index.blade.php` (baru)
- `tests/Feature/UI/PlacementScreensTest.php` (baru, 8 test)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 8 passed / 21 assertions
- `vendor/bin/pint --test` file tersentuh → passed (1x fix imports di test)

## Risiko / catatan

- Checker/Super Admin wajib TOTP di test (middleware `EnsureTwoFactorIsEnrolled`
  redirect 302 ke `/two-factor/enroll`) — sudah ditangani helper `withTwoFactor`.
- View `placement.show/form/review` dibuat di task berikutnya (T1/T2/T3);
  route sudah terdaftar + 403 role salah sudah diuji.

## Siap review task? YA
