# UI-W5-T8 Builder Selfcheck

**Status:** DONE  
**Branch:** `ui-w5-placement`  
**Tanggal:** 2026-08-06

## Perintah & hasil

Env: `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql`

| Perintah | Hasil |
| --- | --- |
| `php artisan test --filter=PlacementScreensTest` | 53 passed / 194 assertions |
| `php artisan test --filter=Placement` (suite domain) | 98 passed / 363 assertions |
| `php artisan test --filter='PlacementScreensTest\|Placement'` | 107 passed / 407 assertions |
| `php artisan test` (full suite) | 672 tests — 671 passed / 1 skipped / 5202 assertions |
| `vendor/bin/pint --test` (app-modules/placement, app/Livewire/Placement, routes, config, tests) | passed |

## Cek invariant wajib

- [x] Mutasi UI hanya lewat `Placement*Service` + read-only `PlacementQueryService`
- [x] Batch eligible = Siap Dikirim + Sedang Dipakai (label & query, tanpa Tersedia)
- [x] Normal batch tidak memanggil `markInUse` (availability tetap Sedang Dipakai)
- [x] Step-up hanya approve expel (`APPROVE_CANDIDATE_EXPEL` +
  `placement_participants.{id}`)
- [x] GAP-4: cancel Aktif hanya count=0, maker→checker, tanpa step-up
- [x] Perusahaan immutable (UI error + server)
- [x] Batch max 50 (UI + service)
- [x] Self-approve ditolak semua checker action
- [x] Arsip/Dibatalkan read-only; tidak ada route/tombol archive manual
- [x] 409 version handling pada mutasi utama
- [x] Nav + permission RBAC (`placement.view|execute|review`)
- [x] Tidak ada Guest routes / Wave 6; tidak edit `docs/kakehashi/`
- [x] Tidak ada secret di commit/report

## Catatan

- 1 test skipped di full suite = di luar area Placement (pre-existing).
- Manual smoke DB/kredensial = operator (lihat `UI-W5-MANUAL-SMOKE-PLAN.md`).
