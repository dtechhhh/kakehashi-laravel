# W5-T8 Independent Reviewer Verdict — Atomicity / Concurrency

**Role:** Reviewer akhir terpisah (conversation baru).  
**Scope:** W5-T1 … W5-T7 (+ T8 gate) pada branch `wave-5-placement` @ `dc45d91`.  
**Mode:** Read-only — tidak mengubah kode, server, `docs/kakehashi/`, atau `BUILD_LOG.md`.  
**Date:** 2026-08-06.

## Verdict

# **WAVE PASS**

Gate atomicity/concurrency sebelum Guest terpenuhi. Tidak ada Blocker/Major terhadap invariant wajib atau larangan wave. Tidak ada authority conflict. Tidak ada scope Wave 6 (Guest).

Tag annotated `wave-5-placement-complete` **sudah ada** di `dc45d91` (dibuat orkestrator in-session). Operator boleh mempertahankannya setelah verdict reviewer terpisah ini, atau mere-tag jika kebijakan operator menuntut tag hanya setelah independent PASS.

---

## Evidence basis

| Item | Result |
| --- | --- |
| Commits wave | `c6db1a7` T1 → `b7c7ad9` T2 → `b1fbda1` T3 → `c9e3609` T4 → `88d1d58` T5 → `9fa89ce` T6 → `0cd0f71` T7 → `dc45d91` T8/build-log |
| Placement suite (re-run) | **45 passed / 197 assertions** (`php artisan test --filter=Placement`, pgsql/`kakehashi_test`) |
| Regression filter (re-run) | **93 passed / 557 assertions** (`PendingRequest\|MakerChecker\|CandidateAvailability\|InterviewPlacement\|Placement`) |
| Pint | PASS on placement + transfer service + console + ActionType |
| `git diff --check` wave | clean |
| Secret scan on wave commits | no secrets / credentials |
| Scope | no Guest module; no `docs/kakehashi/` edits; Jobs touch only `InterviewPlacementTransferService` public API |
| Authority docs edited? | No |

---

## Mandatory invariants

| Invariant | Status | Evidence |
| --- | --- | --- |
| Batch normal hanya `Siap Dikirim` + `Sedang Dipakai`, max 50, rollback total | **PASS** | `PlacementBatchService` + `InterviewPlacementTransferService::assertReadyForPlacement` / `assertInUse`; tests max-50, not-in-use reject, one-invalid full rollback, second-container conflict |
| Transfer normal `assertInUse`; no `Tersedia` window | **PASS** | Approve path: assertInUse only (no markInUse); valid-batch test keeps `candidate.version=0` + `Sedang Dipakai`; source → `Terkirim` via public Jobs service |
| Force-Majeur tanpa step-up; `FM_REJECTED`; archive otomatis | **PASS** | FM approve tanpa token; reject → `FM_REJECTED`; archive only via `maybeArchiveContainer` / daily sweeper; no manual archive API |

## Mandatory prohibitions

| Prohibition | Status |
| --- | --- |
| Normal batch memakai `Tersedia` atau `markInUse` | **Absent** (grep + tests) |
| Partial success batch | **Absent** (single transaction; rollback tests) |
| Archive manual atau step-up Force-Majeur | **Absent** |

---

## Playbook 08 Definition of Done

| DoD item | Result |
| --- | --- |
| Kode P on first submit; perusahaan immutable | PASS (`PlacementContainerLifecycleTest`) |
| One Bekerja partial unique + FM CHECK | PASS (`PlacementSchemaTest` + migration) |
| Batch max 50 + payload snapshot; source untouched on submit | PASS |
| Transfer atomik; source Terkirim; placement Bekerja; availability tetap Sedang Dipakai | PASS |
| One invalid → full batch rollback | PASS |
| No self-approve; double approve 409 | PASS |
| FM kategori+alasan, no step-up, canonical audit | PASS |
| End date = start + months − 1 day | PASS (`ContractDates` + batch/FM tests) |
| Expel step-up; resign routine | PASS |
| Archive after last Bekerja terminal | PASS |
| Reviewer PASS | **This verdict** |

---

## Atomicity / concurrency notes (non-blocking)

1. **N-1 (scope):** GAP-4 escape `Aktif → Dibatalkan` when `count(participants)=0` not implemented (T1 limited to pre-Aktif cancel). Not a stop condition for W5 atomicity gate; record for UI/later if operator needs it.
2. **N-2 (enum):** `BATCH_REJECTED` added to app `ActionType` (not listed in PRD audit table). Allowed as non-breaking by DATABASE_SCHEMA GAP-DB4 (no hard DB CHECK on action_type); supports PRD §6.4 whole-batch reject.
3. **N-3 (audit trail):** Batch/FM submit uses `auditAction: null`; trail is `pending_request` + decision audits (`BATCH_SENT` / `FORCE_MAJEUR_ADDED` / `FM_REJECTED`).
4. **N-4 (notify label):** FM request in-app notify reuses `FORCE_MAJEUR_ADDED` (no REQUESTED event). Non-business-impacting.
5. **N-5 (hardening):** Batch approve re-reads live `currentVersion` for `assertInUse` rather than enforcing snapshot `candidate_version`. Mitigated by source `FOR UPDATE` + source version check + `uq_pp_one_active_work`. Does not create a `Tersedia` window.
6. **N-6 (process):** Prior BUILD_LOG/T8 used in-session builder=reviewer (operator-approved deviation). This document is the separate-conversation re-verification.

---

## Open FAIL / BLOCKED?

- **None.**
- No Wave 6 Guest code in scope.
- No authority conflict between committed snapshots for the reviewed invariants.

## Operator next steps

1. Accept **WAVE PASS** (this independent review).
2. Keep or reaffirm tag `wave-5-placement-complete` @ `dc45d91` per process preference.
3. Record this independent verdict in master Build Log when operator updates it (Reviewer does not edit BUILD_LOG in this pass).
4. Proceed to Wave 6 Guest only after operator acknowledges tag/log.
