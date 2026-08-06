# UI-W5 Placement — Builder Handoff (STANDALONE, goal-driven)

**Task ID:** `UI-W5-BUILD-PLACEMENT-UI-01`  
**Status:** READY FOR BUILDER — operator-approved branch cut + GAP-4 in scope  
**Intended executor:** Builder agent (conversation **baru**, tanpa konteks sebelumnya)  
**Mode:** Builder only — **jangan** self-PASS final; serahkan ke Reviewer terpisah  
**Working material only** — jangan edit `docs/kakehashi/`, jangan catat secret  

Dokumen ini **standalone**. Baca seluruhnya, kejar FINAL GOAL sampai T8, lalu berhenti untuk Reviewer.

---

## 0. FINAL GOAL (selesai hanya jika SEMUA terpenuhi)

1. **Layar Placement P1–P6** (+ queue Checker + GAP-4 cancel Aktif kosong) hidup di Livewire 4 / Blade / routes / nav pada branch **`ui-w5-placement`**.
2. Mutasi **hanya** lewat service domain Placement (dan thin `PlacementQueryService` read-only). Tidak UPDATE langsung `candidate` / `participation`.
3. Task **UI-W5-T0 … T8** selesai berurutan; tiap task: kode + focused tests + pint + report `docs/tmp/UI-W5-T{n}-BUILDER-REPORT.md` (T2b = `UI-W5-T2b-BUILDER-REPORT.md`).
4. **GAP-4 IN:** escape `Aktif → Dibatalkan` hanya jika **belum pernah** ada baris `placement_participants` (`count = 0`), lewat pending `PC_CANCEL_ACTIVE`, approval Manajer Job, **tanpa step-up**. Domain method boleh ditambah **minimal** jika belum ada.
5. Invariant UI-domain:
   - Batch eligible = **`Siap Dikirim` + `Sedang Dipakai`** (bukan Tersedia)
   - Batch normal **tidak** memanggil `markInUse` / tidak buka window Tersedia
   - Force-Majeur, batch, resign, create container, cancel-active = **tanpa step-up**
   - **Hanya** approve expel (Cabut) = step-up (`APPROVE_CANDIDATE_EXPEL` + `placement_participants.{id}`)
   - **Tidak ada** tombol/route archive manual
6. `docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md` tertulis (DB/credential = **operator**, jangan setup secret).
7. Commit per slice (atau per task) di branch `ui-w5-placement`, push ke origin.
8. **Tidak** Guest public Wave 6; **tidak** rewrite domain di luar GAP-4 + thin query; **tidak** edit authority docs.

Setelah T8: tulis `docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md` (commit range, test matrix, risiko) dan **serah ke Reviewer** — jangan tag, jangan update master Build Log kecuali operator minta.

---

## 1. Branch & environment (WAJIB)

| Item | Nilai |
| --- | --- |
| Branch kerja | **`ui-w5-placement`** (sudah di origin; base `7496cea`) |
| Domain prasyarat | Wave 5 PASS di branch domain (`wave-5-placement`); UI branch berangkat dari tip yang sama + plan |
| Checkout | `git fetch origin && git checkout ui-w5-placement && git pull` |
| Test DB | PostgreSQL `kakehashi_test` (bukan SQLite) |
| Env test | `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql` |
| Stack | PHP 8.4 · Laravel 13 · Livewire 4 · Blade · Tailwind 4 |
| Manual smoke DB / password / TOTP | **JANGAN** diurus Builder — operator |

**Plan rinci (authority urutan task):** `docs/tmp/UI-W5-BUILD-PLAN.md`  
**Wireframe semantic:** `docs/kakehashi/ui/UI_WIREFRAME_NOTES.md` §1.4 (P1–P6)  
**Pola copy:** `app/Livewire/Jobs/*`, `InterviewQueryService`, `tests/Feature/UI/JobsScreensTest.php`

---

## 2. Required reading (baca sebelum T0)

1. `AGENTS.md`
2. `docs/kakehashi/BUILD_INVARIANTS.md` (bagian Jobs/Placement)
3. `docs/tmp/UI-W5-BUILD-PLAN.md` (**seluruh file**)
4. `docs/kakehashi/ui/UI_WIREFRAME_NOTES.md` §1.4
5. `docs/kakehashi/modules/MODULE_PLACEMENT.md` (lifecycle, GAP-4, FM, archive)
6. Domain services existing:
   - `app-modules/placement/src/Services/PlacementContainerService.php`
   - `PlacementBatchService.php`, `PlacementForceMajeurService.php`, `PlacementParticipationService.php`
7. Preseden UI: `app/Livewire/Jobs/InterviewIndex.php` + Detail + Form + ReviewQueue + StepUpModal

Jangan load seluruh PRD; rujuk plan + module bila ragu.

---

## 3. Domain call map (mutasi)

| Aksi UI | Service method |
| --- | --- |
| Buat/edit Draft, submit, cancel pre-Aktif | `PlacementContainerService` |
| Approve/reject create | `PlacementContainerService::approve` / `reject` |
| **GAP-4** ajukan/setujui/tolak batalkan Aktif kosong | **Tambah jika belum ada:** `requestCancelActive` / `approveCancelActive` / `rejectCancelActive` (`PC_CANCEL_ACTIVE`) |
| Batch submit / approve / reject | `PlacementBatchService` |
| Force-Majeur request / approve / reject | `PlacementForceMajeurService` |
| Selesai kontrak | `PlacementParticipationService::completeContract` |
| Resign / expel | request + approve/reject pada service yang sama |
| Step-up | Hanya expel approve — reuse `StepUpModal` + `StepUpAction::APPROVE_CANDIDATE_EXPEL` |

**Read-only (Builder buat thin service):**  
`Modules\Placement\Public\PlacementQueryService` (mirror `InterviewQueryService`):

- `paginate` (25)
- `findDetail` (+ participants + pending overlays)
- `eligibleSourcesForBatch` → Siap Dikirim + Sedang Dipakai + no Bekerja placement
- `eligibleForceMajeurCandidates` → Disetujui + Tersedia

Permission: `placement.view` | `placement.execute` | `placement.review` (sudah di `Rbac.php`).

---

## 4. Urutan task (JANGAN lompat)

| # | Task ID | Hasil | Gate |
| --- | --- | --- | --- |
| 0 | **UI-W5-T0** | Routes, nav, `PlacementQueryService`, empty list | 403 role salah; no mutation |
| 1 | **UI-W5-T1** | P1 list + P2 detail read | Badge status; Arsip/Dibatalkan read-only |
| 2 | **UI-W5-T2** | P3 Draft create/edit/submit/cancel pre-Aktif | P-code; perusahaan immutable |
| 2b | **UI-W5-T2b** | **GAP-4** domain + UI cancel Aktif kosong | `count=0` only; Checker approve; no step-up |
| 3 | **UI-W5-T3** | Queue Checker + approve/reject `PC_CREATE` (+ cancel-active di queue) | No step-up; APV_SELF |
| 4 | **UI-W5-T4** | P4 batch submit Maker | Eligible filter benar; max 50; source untouched |
| 5 | **UI-W5-T5** | P4 batch approve/reject Checker | Atomik; tetap Sedang Dipakai |
| 6 | **UI-W5-T6** | P5 Force-Majeur | Tanpa step-up; kategori+alasan wajib |
| 7 | **UI-W5-T7** | P6 status + resign + expel + tampil Arsip otomatis | Step-up **hanya** expel; **no** archive button |
| 8 | **UI-W5-T8** | Smoke plan draft + suite hijau + final handoff | Siap Reviewer |

### Per task — ritual wajib

1. Implement slice terkecil.
2. `php artisan test` fokus (UI Placement + domain terkait bila T2b).
3. `vendor/bin/pint --test` pada file yang disentuh.
4. Report `docs/tmp/UI-W5-T{n}-BUILDER-REPORT.md`: files, commands, hasil, risiko.
5. Commit jelas di `ui-w5-placement` (contoh: `ui(w5): t4 batch submit panel`).
6. Lanjut task berikutnya **tanpa menunggu** approval per task (kecuali stop condition).

### Format report (minimal)

```markdown
# UI-W5-T{n} Builder Report
**Status:** DONE | BLOCKED
**Branch / commit:** ui-w5-placement @ <sha>
## Ringkasan
## File diubah
## Perintah & hasil
## Risiko / catatan
## Siap review task? YA/TIDAK
```

---

## 5. Detail singkat per task

### T0 — Scaffold
- Routes: `/placements`, `/placements/create`, `/placements/review`, `/placements/{id}`, `/placements/{id}/edit`
- Middleware: `auth` + `can:placement.view|execute|review` sesuai aksi
- `config/navigation.php`: Penempatan + Antrian Penempatan
- Livewire skeleton list kosong OK
- Tests: route terdaftar, 403 tanpa permission

### T1 — List + Detail
- Pagination 25; kolom kode `P-YYYY-NNNNN` (kosong Draft), status badge, perusahaan
- Detail: participants + badge `status_penempatan`; overlay pending jika ada
- Arsip / Dibatalkan: **tanpa** tombol mutasi

### T2 — Form Draft
- Field: `nama`, `perusahaan_id` (aktif)
- Submit → Menunggu Approval + P-code
- Cancel hanya Draft / Menunggu Approval (pre-Aktif) via `cancel` existing
- Ubah perusahaan ditolak (tampilkan error service)
- Kirim `version` di setiap mutasi; 409 → banner reload

### T2b — GAP-4 (domain + UI) ⭐ operator-approved
- Guard: status `Aktif` **dan** `placement_participants` count **0** (belum pernah ada baris)
- Maker: request → pending `PC_CANCEL_ACTIVE`; kontainer tetap Aktif + overlay
- Checker: approve → `Dibatalkan`; reject + note → tetap Aktif
- **Tanpa step-up**; self-approve ditolak
- Setelah pernah ada 1 participant (meski nanti terminal), tombol **hilang** / service `PC_NOT_EMPTY`
- Tests domain wajib: empty OK; non-empty fail; double decide; self-approve

### T3 — Review queue
- Antrian pending placement (`PC_CREATE`, `PC_CANCEL_ACTIVE`, kemudian tipe lain seiring task)
- Deep link ke detail
- Approve/reject create: no step-up

### T4 — Batch submit
- Hanya Aktif
- Picker eligible: **Siap Dikirim + Sedang Dipakai** + ownership + tanpa Bekerja
- Per baris: visa, tanggal mulai, durasi, optional end date
- Max 50; submit → pending; source **tidak** berubah
- Copy UI: dilarang teks/filter “Tersedia” sebagai eligible normal

### T5 — Batch decide
- Approve/reject seluruh batch
- Setelah approve: UI menampilkan Bekerja; ketersediaan tetap Sedang Dipakai (jangan klaim Tersedia)
- No step-up

### T6 — Force-Majeur
- Entry terpisah “Tambah langsung / Force-Majeur”
- Kategori lookup + alasan free-text **wajib**
- Candidate: Tersedia + Disetujui
- Approve tanpa step-up

### T7 — Status
- Selesai Kontrak: langsung
- Resign: pending + approve rutin
- Expel: pending + approve **dengan step-up** + catatan checker
- Setelah Bekerja terakhir terminal: detail jadi **Arsip** (read-only) — tanpa aksi archive

### T8 — Package
- Full UI Placement tests + `php artisan test --filter=Placement` domain
- Pint
- `docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md` (protokol STOP FOR OPERATOR INPUT untuk password/TOTP — copy pola UI-W4)
- `docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md`
- Push branch; minta Reviewer

---

## 6. File map yang diharapkan

```
routes/web.php
config/navigation.php
lang/id/ui.php  lang/ja/ui.php
app/Livewire/Placement/
  PlacementIndex.php
  PlacementDetail.php
  PlacementForm.php
  PlacementReviewQueue.php
  (+ panel batch / FM di Detail atau komponen terpisah)
resources/views/placement/
resources/views/livewire/placement/
app-modules/placement/src/Public/PlacementQueryService.php
app-modules/placement/src/Services/PlacementContainerService.php  # + GAP-4 methods
tests/Feature/UI/PlacementScreensTest.php   # atau pecah per area
tests/Feature/Placement/...                 # T2b domain tests
docs/tmp/UI-W5-T*-BUILDER-REPORT.md
docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md
docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md
```

Reuse komponen: `resources/views/components/*`, layout `authenticated`, `StepUpModal`.

---

## 7. Stop conditions (berhenti, laporkan, jangan tebak)

- Butuh mengubah `docs/kakehashi/` / keputusan produk baru
- Filter batch digeser ke `Siap Dikirim + Tersedia`
- Ingin `markInUse` dari jalur batch normal
- Ingin step-up untuk FM / batch / resign / create / cancel-active
- Ingin tombol archive manual
- Butuh secret / credential pack / DB manual (serahkan operator)
- Konflik authority antar snapshot

---

## 8. Larangan keras

| Dilarang |
| --- |
| Edit `docs/kakehashi/` |
| Guest public routes / Wave 6 |
| Rewrite domain di luar GAP-4 + query read |
| Commit password, TOTP, token, `.env` |
| Partial batch di UI (tampilkan error server; jangan fake success sebagian) |
| Self-PASS final wave / bikin tag |

---

## 9. Test minimum (agregat T8)

- Authz: view / execute / review 403
- Create → submit → approve container
- GAP-4 empty cancel path + non-empty deny
- Batch submit source untouched; approve keeps Sedang Dipakai
- FM no step-up
- Expel without step-up fails; with token OK
- No archive control in DOM/routes
- Domain Placement suite tetap hijau
- `vendor/bin/pint --test` file disentuh

---

## 10. Setelah selesai (serah Reviewer)

Kirim ke operator / Reviewer:

1. Branch: `ui-w5-placement`  
2. Range commit: `<base 7496cea>..<HEAD>`  
3. Path: `docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md`  
4. Matrix test (command + pass count)  
5. Known notes / risks  

**Reviewer checklist** ada di `docs/tmp/UI-W5-BUILD-PLAN.md` §7.  
Reviewer **tidak** mengubah kode; verdict: PASS | PASS WITH NON-BLOCKING NOTES | FAIL | BLOCKED.

---

## 11. Prompt start (tempel di chat Builder)

```text
Anda adalah Builder Agent Kakehashi. Eksekusi UI-W5 Placement end-to-end.

Handoff: docs/tmp/UI-W5-BUILDER-HANDOFF.md
Plan: docs/tmp/UI-W5-BUILD-PLAN.md
Branch: ui-w5-placement (git fetch && checkout && pull)

Baca AGENTS.md + handoff + plan. Domain Wave 5 sudah PASS — UI wiring + GAP-4 minimal.
Urutan: T0→T1→T2→T2b→T3→T4→T5→T6→T7→T8 tanpa menunggu approval per task.
Setiap task: tests fokus + pint + report docs/tmp + commit push.
Batch eligible = Siap Dikirim + Sedang Dipakai. FM/batch/resign/cancel-active tanpa step-up.
Expel approve wajib step-up. Tidak ada archive manual. Tidak Guest. Tidak edit docs/kakehashi/.
DB smoke/credential = bukan urusan Anda.

Setelah T8: UI-W5-BUILDER-FINAL-HANDOFF.md lalu serah Reviewer (jangan self-PASS final).
```

---

## 12. Status operator (locked)

| Keputusan | Nilai |
| --- | --- |
| Branch UI | `ui-w5-placement` @ dari `7496cea`+ |
| GAP-4 | **IN** |
| DB / akun smoke | Operator later |
| Reviewer | Conversation terpisah setelah Builder T8 |

**Status:** READY — Builder may start immediately on `ui-w5-placement`.
