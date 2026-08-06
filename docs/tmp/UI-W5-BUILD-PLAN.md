# UI-W5 Build Plan — Placement screens (manual smoke after domain Wave 5)

**Status:** PLAN / REPO-FIRST (working material — `docs/tmp`, not authority)  
**Task ID (orchestration):** `UI-W5-ORCHESTRATE-PLACEMENT-UI-01`  
**Intended builder:** agent conversation terpisah (Builder only)  
**Intended reviewer:** conversation terpisah (Reviewer only — **this orchestrator will review builder output**)  
**Prerequisite domain:** Wave 5 Placement **WAVE PASS** on branch `wave-5-placement` @ `dc45d91` (tag `wave-5-placement-complete` may exist)  
**Prerequisite UI patterns:** UI shell W0–W3 + UI-W4 Jobs (Livewire list/detail/form/queue/step-up modal)  
**Working material only** — do not edit `docs/kakehashi/` or rewrite domain rules.

---

## 0. FINAL GOAL

Selesai hanya jika **semua** berikut terpenuhi:

1. **Layar P1–P6** (wireframe Placement) diimplementasi di Livewire + Blade + routes + nav, memanggil **hanya** public/domain service Placement (dan read helper thin), tanpa mutate tabel lintas-modul langsung.
2. Setiap task builder (`UI-W5-T0`…`UI-W5-T7`) punya report `docs/tmp/UI-W5-T{n}-BUILDER-REPORT.md` + focused UI tests hijau + pint.
3. **Manual smoke package** tertulis + (setelah operator/data) bisa dijalankan: Draft → submit → approve container → batch submit → batch approve → (opsional FM) → Selesai/resign/expel → archive otomatis terlihat.
4. **Reviewer terpisah PASS** (conversation ini / reviewer agent) sebelum operator anggap UI-W5 siap smoke operator.
5. Tidak ada secret di commit/report; tidak ada Guest public; tidak mengubah domain invariants W5.

**BUKAN goal:** rewrite domain invariants, Guest Wave 6, VPS deploy, redesign token besar, setup DB/credential pack (operator-owned).

**IN SCOPE (operator-approved):** **GAP-4** escape `Aktif → Dibatalkan` hanya jika `count(placement_participants)=0` (belum pernah ada kandidat), **ber-approval Manajer Job** (`PendingType::PC_CANCEL_ACTIVE`), **tanpa step-up**.

---

## 1. Authority & stack

| Rank | Document |
| --- | --- |
| 1 | PRD §6.4 / §7.1 / §7.10, DECISIONS_LOG, MODULE_PLACEMENT, STATUS_STATE_MACHINE §2/§4/§7 |
| 2 | BUSINESS_RULES, DATABASE_SCHEMA, API_CONTRACTS, MODULE_AUTH, SECURITY_CHECKLIST |
| 3 | `UI_WIREFRAME_NOTES.md` **§1.4** (P1–P6) |
| 4 | `DESIGN.md` (badge: Tersedia hijau / Sedang Dipakai zinc) |
| 5 | Approved HTML — visual only jika ada; placement HTML **belum dibuat** di NOTES |

**Stack locked:** PHP 8.4 · Laravel 13 · Livewire 4 · Blade custom · Tailwind 4 · PostgreSQL.

**Permissions (sudah di RBAC):**

| Permission | Role |
| --- | --- |
| `placement.view` | Asisten Manajer, Manajer Job, Super Admin (read) |
| `placement.execute` | Asisten Manajer (Maker) |
| `placement.review` | Manajer Job (Checker) |

Super Admin: view-only (no execute/review) — samakan pola Jobs.

---

## 2. Domain services (mutation only through these)

| Service | UI may call |
| --- | --- |
| `Modules\Placement\Services\PlacementContainerService` | `createDraft`, `updateDraft`, `submit`, `approve`, `reject`, `cancel` (pre-Aktif), **`requestCancelActive` / `approveCancelActive` / `rejectCancelActive` (GAP-4)** — add smallest domain methods if missing, `findOrFail` |
| `Modules\Placement\Services\PlacementBatchService` | `submitBatch`, `approveBatch`, `rejectBatch` |
| `Modules\Placement\Services\PlacementForceMajeurService` | `requestForceMajeur`, `approveForceMajeur`, `rejectForceMajeur` |
| `Modules\Placement\Services\PlacementParticipationService` | `completeContract`, `requestResign`, `approveResign`, `rejectResign`, `requestExpel`, `approveExpel`, `rejectExpel` |
| `Modules\Jobs\Public\InterviewPlacementTransferService` | **read eligibility only if needed via query helper** — UI **must not** call `markSentForPlacement` directly (batch approve only) |
| `Modules\Candidates\Public\CandidateAvailabilityService` | display only if already used by query; no mark from UI |
| `Modules\Auth\Public\StepUpService` / existing `StepUpModal` | **only** Cabut Penempatan (`APPROVE_CANDIDATE_EXPEL` on `placement_participants`) |
| Lookup read | `perusahaan`, `jenis_visa`, `kategori_force_majeur` active rows |

**Thin read helper (Builder may add — smallest possible):**

`Modules\Placement\Public\PlacementQueryService` (mirror `InterviewQueryService`):

- `paginate(User, filters, 25)` containers  
- `findDetail(User, id)` container + participants + pending overlays  
- `eligibleSourcesForBatch(User, filters)` rows: participation `Siap Dikirim` + candidate `Sedang Dipakai` + no active placement Bekerja (read-only joins; no mutation)  
- `eligibleForceMajeurCandidates(User, filters)`: `Disetujui` + `Tersedia`  

Do **not** invent a second write path.

---

## 3. Screen inventory (wireframe IDs)

Naming: **P1–P6** = `UI_WIREFRAME_NOTES` §1.4.  
**Builder task IDs** = `UI-W5-T*`.

| Screen | Title | Role | Domain |
| --- | --- | --- | --- |
| **P1** | List kontainer penempatan | Maker + Checker 👁️ + SA 👁️ | query list |
| **P2** | Detail + participants | same | query detail |
| **P3** | Create/edit Draft + submit + cancel | Maker | ContainerService |
| **P3b** | Approve/reject container create | Checker | ContainerService approve/reject — **no step-up** |
| **P3c** | Escape cancel Aktif kosong (GAP-4) | Maker request · Checker decide | `PC_CANCEL_ACTIVE` — only if never had participants; **no step-up** |
| **P4** | Batch kirim (eligible Siap Dikirim) | Maker submit · Checker decide | BatchService — **no step-up** |
| **P5** | Force-Majeur | Maker request · Checker decide | ForceMajeurService — **no step-up** |
| **P6** | Update status penempatan | Maker + Checker (resign/expel) | ParticipationService; expel approve 🔒 step-up |
| **Queue** | Antrian Placement (opsional digabung review) | Checker | pending types: `PC_CREATE`, `PLACEMENT_BATCH`, `FORCE_MAJEUR`, `PLACEMENT_RESIGN`, `PLACEMENT_EXPEL` |

Archive: **no UI action** — status Arsip appears after last Bekerja terminal; show read-only.

---

## 4. Execution principles (ponytail + AGENTS)

1. **No domain rewrite.** UI wires existing services. If read helper missing → smallest `PlacementQueryService` only.
2. **Reuse shell** from UI-W0/W4: layout, nav config, badges, i18n ID/JP keys, step-up modal, 409 banner, flash, components (`button`, `badge`, `modal`, …).
3. **One task = one shippable slice** + focused Livewire/Feature tests + builder report.
4. **Business rules stay server-side.** Hidden buttons ≠ auth.
5. **Version always sent** on mutations (`version` from UI state); map 409 → banner reload.
6. **Do not edit** `docs/kakehashi/`. Reports only in `docs/tmp/`.
7. **Never log/commit secrets.**
8. Prefer copy of Jobs Livewire structure (`InterviewIndex` / `Detail` / `Form` / `ReviewQueue`) over new abstractions.

---

## 5. Branch & environment

| Item | Value |
| --- | --- |
| Suggested branch | `ui-w5-placement` from `wave-5-placement` (or latest merge of domain W5 + UI-W4 if operator prefers integration branch — **ask operator once if unclear**) |
| Test DB | `kakehashi_test` (PostgreSQL) for automated tests |
| Manual smoke DB | synthetic manual DB (e.g. R3-style) — **not** production |
| Default | `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql` |

---

## 6. Builder task sequence (do not skip)

### UI-W5-T0 — Scaffold routes, nav, query helper

**Outcome:** Placement routes exist; nav “Penempatan” + optional “Antrian Penempatan”; read-only list empty works.

- Routes (auth middleware + ability):
  - `GET /placements` → list (`placement.view`)
  - `GET /placements/create` → form (`placement.execute`)
  - `GET /placements/review` → checker queue (`placement.review`)
  - `GET /placements/{id}` → detail (`placement.view`)
  - `GET /placements/{id}/edit` → edit draft (`placement.execute`)
- `config/navigation.php`: items with `placement.view` / `placement.review`.
- `PlacementQueryService` (or equivalent) for empty list.
- Lang keys `ui.nav.placement`, `ui.placement.*` (ID + JA stubs as existing pattern).

**Gate:** 403 wrong role; route named; no mutation yet.  
**Tests:** route middleware / can middleware smoke.  
**Report:** `docs/tmp/UI-W5-T0-BUILDER-REPORT.md`

---

### UI-W5-T1 — P1 List + P2 Detail (read)

**Outcome:** list pagination 25; status badges; detail with participants table.

- Columns: kode `P-YYYY-NNNNN` (empty if Draft), nama, perusahaan, status, dates.
- Detail: header (immutable perusahaan), participants (`status_penempatan` badges), pending overlay labels if any.
- Arsip / Dibatalkan: **read-only** (no action buttons).
- Empty / not found / forbidden states.

**Manual checklist (later smoke):** open list; open Aktif vs Draft vs Arsip.  
**Tests:** Livewire list empty + detail 404/403.  
**Depends:** T0.

---

### UI-W5-T2 — P3 Create/edit Draft + submit + cancel

**Outcome:** Maker lifecycle container.

- Fields: `nama`, `perusahaan_id` (active companies only).
- Create Draft → no P-code, no pending.
- Edit Draft: **reject UI change of perusahaan** (service returns `PC_COMPANY_IMMUTABLE`).
- Submit → P-code assigned, Menunggu Approval, pending `PC_CREATE`.
- Cancel Draft / pending pre-Aktif → Dibatalkan.
- Version conflict → 409 UI.

**Tests:** create/submit/cancel Livewire + self-permission.  
**Depends:** T1.

---

### UI-W5-T2b — GAP-4 domain escape `Aktif → Dibatalkan` (if missing) + UI

**Outcome:** empty **Aktif** container can be cancelled only via maker-checker, never if any `placement_participants` row ever existed.

**Domain (smallest complete change if service lacks it today):**

- Pending type already exists: `PendingType::PC_CANCEL_ACTIVE`.
- Add on `PlacementContainerService` (or dedicated thin methods):
  - `requestCancelActive(Maker, containerId, reason?, version)` — guard: status `Aktif`, `count(participants)=0`, no other blocking pending; create pending `PC_CANCEL_ACTIVE`; container stays Aktif with overlay.
  - `approveCancelActive(Checker, pendingId, version)` — revalidate count still 0 + Aktif; status → `Dibatalkan`; audit `PC_CANCELLED` (or canonical cancel-active event if already defined).
  - `rejectCancelActive(Checker, pendingId, note, version)` — leave Aktif.
- **No step-up.** Self-approve blocked via existing MakerCheckerGate.
- If any participant row exists (including terminal history), reject with clear code e.g. `PC_NOT_EMPTY` — **ever had participants**, not only current Bekerja (STATUS_STATE_MACHINE GAP-4: belum pernah ada).
- Focused domain tests: empty Aktif OK; after one participant (even if terminal then deleted? **rows remain** — once inserted, never empty) deny; race double approve; self-approve.

**UI:**

- On detail when Aktif + zero participants: Maker button “Ajukan pembatalan kontainer”.
- Checker queue / detail: approve/reject cancel-active.
- Hide button when participants exist or status not Aktif.

**Tests:** domain + Livewire negative for non-empty.  
**Depends:** T2 (container lifecycle). May land before or after T3; must be green before T8.

**Note:** This is the only **domain** addition allowed in UI-W5 without a separate domain wave — keep it minimal; no other domain rewrites.

---

### UI-W5-T3 — Checker approve/reject container + review queue

**Outcome:** Manajer Job decides `PC_CREATE` (and cancel-active / later types as wired) without step-up.

- Review queue lists placement pendings (at least `PC_CREATE` + `PC_CANCEL_ACTIVE`; other types appear as later tasks wire them).
- Detail deep-link when container pending.
- Approve → Aktif; reject + note → Draft (code retained).
- Self-approve denied (APV_SELF).
- **No step-up.**

**Tests:** approve/reject + self-approve negative.  
**Depends:** T2.

---

### UI-W5-T4 — P4 Batch submit (Maker)

**Outcome:** on **Aktif** container, Maker builds batch ≤50.

- Eligible picker: only `Siap Dikirim` + `Sedang Dipakai` + ownership + no Bekerja placement.
  - **Forbidden in UI filter copy and query:** `Siap Dikirim + Tersedia`.
- Per row fields: jenis visa, tanggal mulai, durasi bulan, optional end date override (default formula server-side).
- Uniform fill then per-row edit OK.
- Submit → pending `PLACEMENT_BATCH` + snapshot; **source unchanged** (still Siap Dikirim); show overlay “Menunggu keputusan batch”.
- Errors: too large, empty, not in use, not ready, version.

**Tests:** submit success (source untouched assertion via DB or service); reject Tersedia candidate if attempted.  
**Depends:** T3 (need Aktif) — tests may seed domain directly for isolation.

---

### UI-W5-T5 — P4 Batch approve/reject (Checker)

**Outcome:** Checker decides whole batch atomically.

- Approve → participants Bekerja, source Terkirim, availability **remains Sedang Dipakai** (UI may show badge; no “became Tersedia” messaging).
- Reject + note → no participants; source still Siap Dikirim.
- Self-approve / double decide / stale version messaging.
- **No step-up.**

**Tests:** UI path calls service; optional assert after approve via DB in feature test.  
**Depends:** T4.

---

### UI-W5-T6 — P5 Force-Majeur

**Outcome:** separate entry “Tambah langsung / Force-Majeur” on Aktif container.

- Form: candidate picker (`Tersedia`+`Disetujui` only), `kategori_force_majeur`, alasan free-text **both required**, visa, dates.
- Submit → pending `FORCE_MAJEUR`; candidate still Tersedia until approve.
- Checker approve **without step-up** → Bekerja + Sedang Dipakai; reject → `FM_REJECTED` trail (message generic OK).
- Distinct visual label from normal batch (NOTES: exceptional path).

**Tests:** request requires reason; approve without step-up succeeds in test setup.  
**Depends:** T3.

---

### UI-W5-T7 — P6 Status + expel step-up + archive visibility

**Outcome:** per-participant actions on Aktif only.

| Action | UI | Step-up |
| --- | --- | --- |
| Selesai Kontrak | direct confirm | no |
| Mengundurkan Diri | request + reason; Checker approve/reject note | no |
| Dikeluarkan | request + reason; Checker note + **step-up** | **yes** on approve |

- After last Bekerja ends: detail shows container **Arsip** (read-only); no manual archive button.
- Reuse existing `StepUpModal` with action `APPROVE_CANDIDATE_EXPEL` + entity `placement_participants.{id}` (same as domain test).

**Tests:** complete contract; resign routine; expel without step-up fails; with step-up token succeeds; no archive button exists.  
**Depends:** T5 (need Bekerja rows) or seed.

---

### UI-W5-T8 — Package for smoke + regression gate (Builder self-check)

**Outcome:** builder packages automated green + smoke checklist draft for operator/agent smoke.

- Run: Placement UI tests + Placement domain suite + pint.
- Write `docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md` (copy structure from UI-W4 manual smoke: STOP FOR OPERATOR INPUT for password/TOTP, synthetic users, scenarios P1–P6).
- Write `docs/tmp/UI-W5-T8-BUILDER-SELFCHECK.md` with command results.
- **Do not** mark wave complete; hand off to **Reviewer**.

---

## 7. Reviewer protocol (this conversation after builder ships)

Reviewer **does not change code**. Verdicts:

| Verdict | Meaning |
| --- | --- |
| **PASS** | Ready for manual smoke |
| **PASS WITH NON-BLOCKING NOTES** | Smoke allowed; notes only |
| **FAIL — FIX REQUIRED** | Builder must fix before smoke |
| **BLOCKED — AUTHORITY CONFLICT** | Stop for operator/DOC-SYNC |

### Reviewer checklist (must all green for PASS)

1. Mutations only via Placement*Service (no direct `candidate`/`participation` writes from Livewire).
2. Batch eligible UI/query = Siap Dikirim + Sedang Dipakai (not Tersedia).
3. No UI control that calls `markInUse` for normal batch.
4. No step-up on container create, cancel-active (GAP-4), batch, FM, resign.
5. Step-up **only** on placement expel approve.
6. No manual archive button/route.
6b. GAP-4: cancel Aktif only when never had participants; requires Checker approval; non-empty fails.
7. Perusahaan immutable in form UX + server error surfaced.
8. Batch max 50 enforced in UI (disable/validate) + server.
9. Self-approve blocked on all checker actions.
10. Arsip/Dibatalkan/Closed-equivalent read-only.
11. 409 version handling present on main mutations.
12. Nav + permissions match RBAC.
13. Focused UI tests + domain Placement suite still green.
14. Pint OK; no secrets; no Guest routes; no `docs/kakehashi` edits.
15. Scope: no Wave 6 public Guest.

### Atomicity smoke (manual / later agent)

Minimum browser proof (or equivalent E2E if present):

1. Batch 2 rows one invalid at approve time → zero placement rows (or show server error without partial UI state).
2. Valid batch → source Terkirim, Bekerja, Sedang Dipakai.
3. Last Bekerja terminal → container Arsip.

---

## 8. File map (expected — adjust only if pattern demands)

```
routes/web.php
config/navigation.php
lang/... (ui.placement.*)
app/Livewire/Placement/
  PlacementIndex.php
  PlacementDetail.php
  PlacementForm.php
  PlacementReviewQueue.php
  PlacementBatchPanel.php   # or embedded in Detail
  PlacementForceMajeurPanel.php
resources/views/placement/...
resources/views/livewire/placement/...
app-modules/placement/src/Public/PlacementQueryService.php
tests/Feature/UI/Placement*.php
docs/tmp/UI-W5-T*-BUILDER-REPORT.md
docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md
```

---

## 9. Stop conditions (Builder must stop and report)

- Need to change domain invariants / authority docs.
- Tempted to filter batch as `Siap Dikirim + Tersedia`.
- Need markInUse on normal batch from UI.
- Need step-up for Force-Majeur or batch.
- Need manual archive action.
- Missing domain method that requires product decision (not just thin read).
- Secret required in repo.

---

## 10. Definition of Done (UI-W5)

- [ ] P1–P6 screens usable by Maker/Checker with correct abilities  
- [ ] All mutations via domain services  
- [ ] Invariant-safe filters and step-up matrix  
- [ ] Automated UI + Placement domain tests green  
- [ ] Manual smoke plan written  
- [ ] Reviewer PASS (or PASS WITH NON-BLOCKING NOTES)  
- [ ] Operator can run browser smoke without domain rebuild  

**Tag:** no automatic tag required for UI; operator may note `ui-w5-placement` completion in Build Log after smoke PASS. Domain tag `wave-5-placement-complete` stays domain-only.

---

## 11. Suggested builder prompt (paste into Builder agent)

```text
Anda adalah Builder Agent Kakehashi. Kerjakan UI Wave 5 Placement sesuai plan:
docs/tmp/UI-W5-BUILD-PLAN.md

Baca: AGENTS.md, BUILD_INVARIANTS, MODULE_PLACEMENT, UI_WIREFRAME_NOTES §1.4,
dan plan di atas. Domain services sudah ada — JANGAN rewrite domain.

Urutan: UI-W5-T0 → T1 → … → T8. Satu task selesai = report docs/tmp + tests + pint, lalu lanjut.
Mutasi hanya lewat Placement*Service. Batch eligible = Siap Dikirim + Sedang Dipakai.
Force-Majeur & batch & resign TANPA step-up. Expel approve WAJIB step-up.
Tidak ada archive manual. Tidak ada Guest. Tidak edit docs/kakehashi/.

Setelah T8, serahkan ke Reviewer terpisah (jangan self-PASS final).
```

---

## 12. Suggested reviewer prompt (this conversation / Reviewer agent)

```text
Anda adalah Reviewer UI-W5 Kakehashi. Jangan ubah kode.
Audit diff branch ui-w5-placement (atau branch builder) terhadap docs/tmp/UI-W5-BUILD-PLAN.md
checklist §7. Jalankan suite UI Placement + domain Placement; pint; secret scan.
Verdict: PASS | PASS WITH NON-BLOCKING NOTES | FAIL — FIX REQUIRED | BLOCKED — AUTHORITY CONFLICT.
Tulis docs/tmp/UI-W5-REVIEWER-REPORT.md.
```

---

## 13. Operator decisions (locked for this plan)

| Item | Decision |
| --- | --- |
| Base | `wave-5-placement` @ domain PASS tip (commit before UI branch cut) |
| UI branch | `ui-w5-placement` |
| GAP-4 | **IN** — domain methods if missing + UI maker/checker |
| Manual smoke DB / credentials | **Operator-owned** — Builder/Reviewer do not set up DB packs or secrets |

Builder may start after branch `ui-w5-placement` exists and plan is on that branch.

---

**Status:** PLAN approved for branch cut → Builder on `ui-w5-placement` → Reviewer (this agent) → Manual smoke (operator DB).
