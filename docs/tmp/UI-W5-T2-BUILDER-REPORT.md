# UI-W5-T2 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

P3 Draft form (Maker): buat Draft (tanpa kode/pending), edit Draft dengan
perusahaan immutable (`PC_COMPANY_IMMUTABLE` ditampilkan sebagai error),
submit → P-code `P-YYYY-NNNNN` + pending `PC_CREATE`, cancel Draft /
Menunggu Approval, banner 409 untuk konflik versi. Semua mutasi lewat
`PlacementContainerService`.

## File diubah

- `app/Livewire/Placement/PlacementForm.php` (baru)
- `resources/views/placement/form.blade.php` (baru, wrapper)
- `resources/views/livewire/placement/placement-form.blade.php` (baru)
- `app-modules/placement/src/Public/PlacementQueryService.php`
  (+ `perusahaanOptions` gate `placement.execute`)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.form.*`, `errors.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 8 test form)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 21 passed / 64 assertions
- `vendor/bin/pint` (fix imports/spacing) lalu `--test` → passed

## Risiko / catatan

- `updateDraft` mengembalikan row tanpa bump versi bila payload kosong; UI
  selalu mengirim `nama` + `perusahaan_id` sehingga versi naik normal.
- Kolom `perusahaan.kode` tidak ada — helper test tidak memakainya.

## Siap review task? YA
