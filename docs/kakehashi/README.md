# Kakehashi Documentation Snapshot

> Canonical source: Notion workspace, hub `Brainstrom MD Kakehashi`, when connected. During an explicit `DOC-SYNC-REPO-FIRST` period, committed repository snapshots and root `BUILD_LOG.md` are the operational source until a later sync.
> Files here are controlled read-only snapshots for Codex.

## Start here

1. Read repository `AGENTS.md`.
2. Read `BUILD_INVARIANTS.md`.
3. Check `MANIFEST.md`.
4. Read the active wave guide.
5. Read the routed module and technical documents.
6. Stop if a snapshot is missing, stale, or contradictory.

## Authority order

1. PRD v0.3.14
2. DECISIONS_LOG Batch A/B
3. Foundation, module, schema, API, security, and privacy documents
4. UI_WIREFRAME_NOTES
5. Approved HTML — non-authoritative visual reference

## File groups

| Directory | Purpose | Edit policy |
| --- | --- | --- |
| `authority/` | Highest product decisions | DOC-SYNC only |
| `foundation/` | Architecture, roles, states, business rules | DOC-SYNC only |
| `modules/` | Domain implementation contracts | DOC-SYNC only |
| `technical/` | Schema, API, security, privacy, deployment | DOC-SYNC only |
| `ui/` | Semantic UI notes and visual-reference policy | DOC-SYNC only |
| `playbook/` | Operator workflow and Codex prompts | DOC-SYNC only |

## Routing by wave

| Wave | Required documents |
| --- | --- |
| Wave 0 | Architecture, Database Schema, Deployment, Security, Wave 0 guide |
| Wave 1 | Auth, Roles, Business Rules, Schema, API, Security, Wave 1 guide |
| Wave 2 | Lookup, Roles, API, Schema, Security, Wave 2 guide |
| Wave 3 | Candidates, State Machine, Business Rules, Schema, Privacy, Wave 3 guide |
| Wave 4 | Jobs, Candidates API, State Machine, Schema, Wave 4 guide |
| Wave 5 | Placement, Jobs, State Machine, API, Schema, Wave 5 guide |
| Wave 6 | Guest Access, PRD Appendix C, Privacy, Security, Wave 6 guide |
| Wave 7 | Security, Privacy, Deployment, Backup/Recovery, Wave 7 guide |

## Environment model

- Local Dev is the primary coding and daily testing environment.
- Ephemeral Test VPS is a disposable integration and rehearsal environment using synthetic data and test credentials.
- Production VPS is a separate stable server for real users and data.

## Conflict handling

If Notion is connected, stop coding, identify the conflicting files and sections, ask the operator to verify Notion, sync the approved decision through DOC-SYNC, and resume only after review. If Notion is unavailable, stop only for a conflict between committed repository snapshots; record the approved resolution through DOC-SYNC-REPO-FIRST before continuing.

## Offline build record

`BUILD_LOG.md` at the repository root is the live build-status record when Notion is unavailable. It must contain one row per task, sanitized test evidence, commit/tag references, and the separate Reviewer verdict. `docs/tmp/` remains non-canonical working material.

## Snapshot status

**FINAL Codex documentation snapshot — MD-1 through MD-5, exported 2026-07-16.**

Included: authority, foundation, modules, technical references, UI semantic notes, Approved HTML policy, and complete operator Playbook with Ephemeral VPS Addendum. Raw Approved HTML is intentionally excluded.
