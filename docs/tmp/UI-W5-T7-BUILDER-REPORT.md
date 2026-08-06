# UI-W5-T7 Builder Report

**Status:** DONE
**Branch / commit:** ui-w5-placement @ (lihat commit berikutnya)

## Ringkasan

P6 status penempatan per partisipan (hanya kontainer Aktif):
- **Selesai Kontrak** — langsung, tanpa approval.
- **Mengundurkan Diri** — request + alasan; Checker approve/reject + catatan,
  tanpa step-up.
- **Dikeluarkan** — request + alasan 2 lapis; Checker approve **wajib
  step-up** (`APPROVE_CANDIDATE_EXPEL` + `placement_participants.{id}`),
  reject + catatan.

Arsip otomatis: partisipan Bekerja terakhir terminal → kontainer `Arsip`
(domain, in-transaction). Detail terminal read-only; **tidak ada tombol/route
archive manual** (`Route::has('placements.archive')` = false, diuji).
Queue menampilkan `PLACEMENT_RESIGN` + `PLACEMENT_EXPEL` (join partisipan),
approve resign inline, approve expel via step-up modal.

## File diubah

- `app-modules/placement/src/Public/PlacementQueryService.php`
  (`reviewQueue` join partisipan + tipe resign/expel; `pendingOverlays`
  + `participant_id`/`participant_version`)
- `app/Livewire/Placement/PlacementDetail.php` (+ aksi P6 + step-up expel)
- `resources/views/livewire/placement/placement-detail.blade.php`
  (kolom aksi per partisipan, kontrol decide resign/expel, step-up modal)
- `app/Livewire/Placement/PlacementReviewQueue.php` (+ resign/expel decide,
  step-up expel)
- `resources/views/livewire/placement/placement-review-queue.blade.php`
  (kolom kandidat, kontrol expel, step-up modal)
- `lang/id/ui.php`, `lang/ja/ui.php` (+ `ui.placement.status.*`)
- `tests/Feature/UI/PlacementScreensTest.php` (+ 9 test P6)

## Perintah & hasil

- `php artisan test --filter=PlacementScreensTest` → 53 passed / 194 assertions
- `php artisan test --filter='Placement|JobsScreensTest'` → 168 passed /
  612 assertions (regresi domain + UI Jobs hijau)
- `vendor/bin/pint --test` file tersentuh → passed

## Risiko / catatan

- Step-up tanpa elevation: UI men-dispatch `stepup.open` (belum mutasi);
  domain menolak `STEPUP_REQUIRED` 403 (diuji kedua sisi).
- Setelah partisipan terminal terakhir, kontainer otomatis Arsip — perilaku
  sinkron domain, bukan aksi UI.

## Siap review task? YA
