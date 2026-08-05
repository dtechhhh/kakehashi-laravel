# Kakehashi Documentation Manifest

Bootstrap date: **2026-07-15**
Canonical source: **Notion — Brainstrom MD Kakehashi**
Last DOC-SYNC: **2026-08-05**

## MD-1 files

| File | Status | Edit policy |
| --- | --- | --- |
| `README.md` | Ready | DOC-SYNC only |
| `MANIFEST.md` | Ready | DOC-SYNC only |
| `SOURCE_MAP.md` | Ready | DOC-SYNC only |
| `BUILD_INVARIANTS.md` | Ready | DOC-SYNC only |
| Repository `AGENTS.md` | Ready | Controlled instructions |

## Export plan

| Target group | Source | Status | Pass |
| --- | --- | --- | --- |
| `authority/` | PRD and DECISIONS_LOG | Ready 2026-07-15 | MD-2 |
| `foundation/` | Overview, Glossary, Architecture, Roles, State, Rules, Tech | Ready 2026-07-15 | MD-2 |
| `technical/DATABASE_SCHEMA.md` | DATABASE_SCHEMA | Ready 2026-07-15 | MD-2 |
| `technical/SECURITY_CHECKLIST.md` | SECURITY_CHECKLIST | Ready 2026-07-15 | MD-2 |
| `modules/` | Auth, Lookup, Candidates, Jobs, Placement, Guest | Ready 2026-07-15 | MD-3 |
| Other `technical/` docs | API, Privacy, Deployment, Backup | Ready 2026-07-15 | MD-3 |
| `playbook/` main + pages 00–15 | Build & Deployment Playbook + VPS Addendum | Ready 2026-07-16 | MD-4 |
| `ui/` | UI Notes, Design system, Approved HTML policy/index, and UI build plan | Ready 2026-08-01 | MD-5 / DOC-SYNC-UI-APPROVED-REFS |

Build Log is exported as a template only. Live session records are not copied automatically.

## DOC-SYNC rule

Every sync updates export status, source version, export date, reviewer verdict, and intentional exclusions. Documentation changes use a separate commit from application code.

## MD-2 export review

- Exported: 2026-07-15
- Canonical source: Notion
- Files exported: 11
- Conversion: callouts to Markdown notes; page mentions to repository links when mapped; HTML tables preserved.
- Reviewer status: structural validation PASS; package validation PASS.

## MD-3 export review

- Exported: 2026-07-15
- Files: 10
- Canonical source: Notion
- Structural validation: PASS
- Secret-pattern scan: PASS
- Historical labels retained; authority order applies.

## MD-4 export review

- Exported: 2026-07-16
- Files: main playbook + pages 00–15 (17 files)
- VPS Harian Ephemeral Addendum: included
- Build Log: template export only
- Structural validation: PASS
- Secret-pattern scan: PASS


## MD-5 final review

- Exported: 2026-07-16
- UI_WIREFRAME_NOTES: included
- Approved HTML: policy and approved-screen inventory included; raw HTML intentionally excluded
- Repository-local link validation: PASS
- Codex routing test: PASS
- Unresolved Notion marker scan: PASS
- Secret-pattern scan: PASS
- ZIP integrity: PASS
- Final snapshot status: READY FOR CODEX

## DOC-SYNC 2026-07-23

- Source decision: user approval on 2026-07-23.
- Source version: ARCHITECTURE FINAL v1.3; PRD remains v0.3.14.
- Changed: `foundation/ARCHITECTURE.md`, `authority/DECISIONS_LOG.md`, and this manifest.
- Decision: `internachi/modular` 3.x supersedes 2.x for Laravel 13 stable compatibility.
- Intentional exclusions: no application code, PRD, domain rules, schema, module contracts, or UI changes.
- Reviewer status: PASS.

## DOC-SYNC 2026-07-28 — DOC-SYNC-W2-T5-AUTHORITY

- Source decision: operator approval on 2026-07-28; repository is the source for this sync because Notion is not connected.
- Source version: PRD remains v0.3.14; no schema or product-version change.
- Exported: 2026-07-28.
- Export status: READY.
- Changed: repository `AGENTS.md`; `BUILD_INVARIANTS.md`; `authority/PRD_Kakehashi_v0_3_14.md`; `authority/DECISIONS_LOG.md`; `foundation/BUSINESS_RULES.md`; `foundation/STATUS_STATE_MACHINE.md`; `technical/API_CONTRACTS.md`; `technical/SECURITY_CHECKLIST.md`; `playbook/02_MENYIAPKAN_CODEX.md`; `playbook/05_WAVE_2_LOOKUP.md`; `ui/UI_WIREFRAME_NOTES.md`; and this manifest.
- Decision: `lookup_request` and `company_request` use their own status as the Checker decision source, create no `pending_request`, and add no type to `PendingType`; both reuse the remaining Wave 1 approval foundation. `pending_request` remains authoritative for other approval domains.
- Intentional exclusions: `DATABASE_SCHEMA.md` and `MODULE_LOOKUP_DATA.md` remain unchanged because they are already consistent; no application code, migration, test, dependency, configuration, PRD version, global provenance metadata, or unrelated product decision changed.
- Reviewer status: PASS — separate read-only review.

## DOC-SYNC 2026-08-01 — DOC-SYNC-REPO-FIRST

- Source decision: operator instruction; Notion has never been connected for this repository.
- Operating mode: committed repository snapshots are the operational authority while offline; Notion is a later mirror.
- Changed: repository `AGENTS.md`; `README.md`; `playbook/14_BUILD_LOG_TEMPLATE.md`; `authority/DECISIONS_LOG.md`; and root `BUILD_LOG.md`.
- Build status source: root `BUILD_LOG.md`, with one row per task and separate Builder/Reviewer evidence.
- Intentional exclusions: no application code, migration, dependency, or product decision outside the Wave 3 closeout rulings; `docs/tmp/` remains working material only.
- Reviewer status: pending separate review of this DOC-SYNC commit.

## DOC-SYNC 2026-08-01 — DOC-SYNC-UI-APPROVED-REFS

- Source decision: operator instruction; Notion has never been connected for this repository.
- Operating mode: repository-local UI snapshots are the operational source while offline.
- Source material: existing `DESIGN md — Kakehashi` snapshot and existing `UI_WIREFRAME — HTML (Approved Refs)` index, normalized without changing their content.
- Changed: `ui/DESIGN.md`; `ui/UI_WIREFRAME_APPROVED_REFS.md`; `ui/README.md`; `ui/README_APPROVED_HTML.md`; `ui/UI_BUILD_PLAN_W0_W3.md`; and this manifest.
- Intentional exclusions: per-screen raw HTML, new product/domain decisions, application code, and any Notion content not present in the repository. `UI_WIREFRAME_APPROVED_REFS.md` is an index and canonical-link snapshot, not executable HTML.
- Cleanup: Windows `Zone.Identifier` sidecar files were moved out of the repository to a recoverable `/tmp` directory and are not tracked.
- Reviewer status: pending separate read-only review of this DOC-SYNC commit.

## DOC-SYNC 2026-08-05 — DOC-SYNC-W4-GAP3-FROZEN-AT

- Source decision: operator instruction “DOC-SYNC dulu” after W4 final review WAVE FAIL (close freeze vs active slot); Notion not connected — repository is operational authority under DOC-SYNC-REPO-FIRST.
- Source version: PRD remains v0.3.14; no PRD version bump; no application code.
- Exported: 2026-08-05.
- Export status: READY.
- Changed: `technical/DATABASE_SCHEMA.md`; `foundation/STATUS_STATE_MACHINE.md`; `foundation/BUSINESS_RULES.md`; `BUILD_INVARIANTS.md`; `modules/MODULE_JOBS.md`; `playbook/07_WAVE_4_JOBS.md`; `authority/DECISIONS_LOG.md`; and this manifest.
- Decision: `participation.frozen_at` is the denormalized GAP-3 freeze stamp; active slot = four non-terminal statuses **and** `frozen_at IS NULL`; close stamps freeze then `markAvailable`; re-entry uses a new participation row. Aligns BR-AVL-01, BR-CON-05, BR-PII-08 with partial unique.
- Intentional exclusions: no application code, migrations, tests, dependencies, configuration, Placement/Guest product changes, PRD version change, or raw UI HTML. Implementation remains W4-R1 coding task after this sync.
- Reviewer status: pending separate read-only review of this DOC-SYNC commit.
