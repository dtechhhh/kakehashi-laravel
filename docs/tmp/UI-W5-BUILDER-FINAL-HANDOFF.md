# UI-W5 Builder Final Handoff

**Status:** READY FOR REVIEWER — jangan self-PASS final, jangan tag  
**Branch:** `ui-w5-placement`  
**Commit range:** `7496cea..ea6d850` (+ commit T8 docs)  
**Builder:** conversation terpisah (ini) — **Reviewer** conversation terpisah

## Ringkasan

UI Placement P1–P6 + queue Checker + GAP-4 selesai di Livewire 4 / Blade /
routes / nav pada branch `ui-w5-placement`. Semua mutasi lewat service domain
Placement; read-only via `Modules\Placement\Public\PlacementQueryService`.
Satu-satunya tambahan domain: GAP-4 (`requestCancelActive` /
`approveCancelActive` / `rejectCancelActive`).

## Task & commit

| Task | Commit | Isi |
| --- | --- | --- |
| T0 | `76f0789` | routes, nav, query service, list kosong |
| T1 | `6805648` | P1 list + P2 detail read |
| T2 | `07fd130` | P3 draft form (create/edit/submit/cancel) |
| T2b | `a17bc31` | GAP-4 domain + UI cancel Aktif kosong |
| T3 | `4f77c17` | queue Checker PC_CREATE + PC_CANCEL_ACTIVE |
| T4 | `cdeb398` | P4 batch submit Maker |
| T5 | `cb0bfc6` | P4 batch approve/reject Checker |
| T6 | `c18a3f6` | P5 Force-Majeur |
| T7 | `ea6d850` | P6 status/resign/expel step-up + arsip otomatis |
| T8 | (commit ini) | smoke plan, selfcheck, handoff |

## File map (dibuat/diubah)

- `routes/web.php` — 5 route placement + ability
- `config/navigation.php` — Penempatan + Antrian Penempatan
- `lang/id/ui.php`, `lang/ja/ui.php` — `ui.placement.*`
- `app-modules/placement/src/Public/PlacementQueryService.php` — baru
- `app-modules/placement/src/Services/PlacementContainerService.php` — +GAP-4
- `app/Livewire/Placement/` — `PlacementIndex`, `PlacementDetail`,
  `PlacementForm`, `PlacementReviewQueue`, `PlacementBatchPanel`,
  `PlacementForceMajeurPanel`
- `resources/views/placement/` + `resources/views/livewire/placement/`
- `tests/Feature/UI/PlacementScreensTest.php` — 53 test UI
- `tests/Feature/Placement/PlacementCancelActiveTest.php` — 9 test domain GAP-4
- `docs/tmp/UI-W5-T*-BUILDER-REPORT.md`, `UI-W5-MANUAL-SMOKE-PLAN.md`,
  `UI-W5-T8-BUILDER-SELFCHECK.md`

## Test matrix

| Suite | Perintah | Hasil |
| --- | --- | --- |
| UI Placement | `php artisan test --filter=PlacementScreensTest` | 53 / 194 |
| Domain Placement | `php artisan test --filter=Placement` | 98 / 363 |
| Agregat UI+domain | `--filter='PlacementScreensTest\|Placement'` | 107 / 407 |
| Full suite | `php artisan test` | 671 passed, 1 skipped |
| Format | `vendor/bin/pint --test` (file tersentuh) | passed |

## Invariant / matrix step-up

- Batch eligible = **Siap Dikirim + Sedang Dipakai** (bukan Tersedia);
  normal batch tidak `markInUse`, availability tetap Sedang Dipakai.
- Force-Majeur, batch, resign, create, cancel-active = **tanpa step-up**.
- **Hanya** approve expel (Cabut) = step-up.
- GAP-4: Aktif → Dibatalkan hanya `count(placement_participants)=0` (belum
  pernah ada baris), maker→checker, tanpa step-up, `PC_NOT_EMPTY` diuji.
- Tidak ada tombol/route archive manual; Arsip otomatis (domain, in-transaction).
- Perusahaan immutable; max batch 50; self-approve ditolak; 409 version banner.
- Tidak ada Guest public; tidak edit `docs/kakehashi/`; tidak ada secret.

## Risiko / catatan

- Review queue menangani 6 tipe pending (PC_CREATE, PC_CANCEL_ACTIVE,
  PLACEMENT_BATCH, FORCE_MAJEUR, PLACEMENT_RESIGN, PLACEMENT_EXPEL); expel
  approve selalu lewat step-up modal (queue & detail).
- Perilaku arsip otomatis diuji end-to-end (Selesai Kontrak/resign/expel
  terakhir → kontainer Arsip).
- Manual smoke memakai DB synthetic operator (lihat smoke plan); password/TOTP
  tidak pernah masuk repo.

## Serah Reviewer

1. Branch: `ui-w5-placement`
2. Range commit: `7496cea..<HEAD>`
3. Path: `docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md`
4. Test matrix: di atas (semua hijau)
5. Reviewer checklist: `docs/tmp/UI-W5-BUILD-PLAN.md` §7

Builder **tidak** menandai wave complete dan tidak membuat tag.
