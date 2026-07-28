# Kakehashi Documentation Manifest

Bootstrap date: **2026-07-15**
Canonical source: **Notion — Brainstrom MD Kakehashi**
Last DOC-SYNC: **2026-07-28**

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
| `ui/` | UI Notes and Approved HTML policy | Ready 2026-07-16 | MD-5 |

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
