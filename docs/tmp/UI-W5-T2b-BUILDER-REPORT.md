# UI-W5-T2b Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

GAP-4 (domain + UI): escape `Aktif → Dibatalkan` hanya untuk kontainer yang
**belum pernah** punya baris `placement_participants` (count=0), lewat pending
`PC_CANCEL_ACTIVE`, approval Manajer Job, **tanpa step-up**.

Domain (satu-satunya tambahan domain UI-W5, minimal):
- `PlacementContainerService::requestCancelActive` — guard Aktif + count=0 +
  tanpa pending lain (`PC_BLOCKED_PENDING`); kontainer tetap Aktif + overlay.
- `approveCancelActive` — revalidasi Aktif + count=0 + version snapshot di
  dalam transaksi; → Dibatalkan + audit `PC_CANCELLED`.
- `rejectCancelActive` (+note wajib) — kontainer tetap Aktif.
- Self-approve ditolak via `MakerCheckerGate` (`APV_SELF`).

UI:
- Detail: Maker (pembuat) melihat tombol "Ajukan pembatalan kontainer" hanya
  saat Aktif + 0 partisipan; tombol hilang bila pernah ada partisipan / bukan
  pembuat.
- Checker: approve / reject (+catatan) langsung di detail.

## File diubah

- `app-modules/placement/src/Services/PlacementContainerService.php`
  (+3 method GAP-4 + helper `cancelActivePending`, `assertContainerEmpty`,
  `assertNoOtherPending`)
- `app/Livewire/Placement/PlacementDetail.php` (+ `version`, aksi GAP-4)
- `resources/views/livewire/placement/placement-detail.blade.php`
  (+ tombol request Maker + kontrol decide Checker)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `cancel_active.*`,
  `errors.PC_BLOCKED_PENDING`)
- `tests/Feature/Placement/PlacementCancelActiveTest.php` (baru, 9 test domain)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 6 test UI GAP-4)

## Perintah & hasil

- `php artisan test --filter=PlacementCancelActiveTest` → 9 passed
- `php artisan test --filter=PlacementScreensTest` → 27 passed
- `php artisan test --filter=Placement` (seluruh suite domain) → 81 passed /
  296 assertions (regresi hijau)
- `vendor/bin/pint` (fix) lalu `--test` → passed

## Risiko / catatan

- `requestCancelActive` menolak bila ada pending lain pada kontainer
  (`PC_BLOCKED_PENDING`) — guard eksplisit plan ("no other blocking pending").
- Race: participant ditambahkan setelah request → approve gagal `PC_NOT_EMPTY`
  (sudah diuji `test_approve_revalidates_empty_after_race`).

## Siap review task? YA
