---
title: "Approved HTML Reference Policy"
status: "FINAL / FROZEN index snapshot"
source_notion_title: "UI_WIREFRAME — HTML (Approved Refs)"
exported_at: "2026-07-16"
authority_rank: "non-authoritative-visual-reference"
canonical_source: "Notion"
codex_edit_policy: "read-only"
bulk_html_exported: "false"
---

# Approved HTML Reference Policy

> [!WARNING]
> Approved HTML is a **non-authoritative visual reference**. It must never override PRD v0.3.14, Batch A/B, domain rules, schema, API contracts, security/privacy rules, or `UI_WIREFRAME_NOTES.md`.

## Why raw HTML is excluded

Raw Stitch HTML is intentionally **not bulk-exported** into this Codex snapshot. Loading all visual references would add noise, stale implementation details, preview-only controls, and a higher risk of copying behavior that conflicts with current authority documents.

Request or export only the single approved screen needed for an active UI task through a dedicated `DOC-SYNC` task.

## Approved reference inventory

| Area | Approved screen set | Status in canonical Notion collection |
| --- | --- | --- |
| Candidates | K1–K6, including K3 anchor | Approved/frozen |
| Authentication | A1–A6 | Approved/frozen |
| Interviews | W1–W9 semantic set | Approved/frozen |
| Placement | P1–P7 | Approved/frozen |
| Super Admin | S1–S5 | Approved/frozen |
| Guest | G1–G3, Japanese-only | Approved/frozen |

## Codex usage rules

1. Read `UI_WIREFRAME_NOTES.md` before using an approved HTML reference.
2. Read the active module, schema, API, security, and privacy documents.
3. Treat layout, spacing, visual hierarchy, and component composition as inspiration—not executable domain rules.
4. Rebuild the screen in Livewire 4 + custom Blade/Livewire + Tailwind CSS 4.
5. Remove preview state switchers, developer bars, fake data controls, and offline fallback CSS.
6. Add real accessibility, validation, authorization, step-up, polling, conflict handling, signed-URL refresh, and error/empty/loading states during engineering.
7. Never copy secrets, hard-coded credentials, production URLs, or unsafe inline scripts from a visual reference.
8. If HTML conflicts with authority documents, stop, follow the authority documents, and report the visual-reference drift.

## Allowed export procedure

- One screen at a time.
- Dedicated `DOC-SYNC` commit, separate from application code.
- Record source title, version/date, intended task, and checksum.
- Scan for secrets and preview-only controls.
- Keep the exported screen read-only.
- Delete or refresh it when the canonical Notion reference changes.

## Locked authority order

1. PRD v0.3.14
2. DECISIONS_LOG Batch A/B
3. Foundation, module, schema, API, security, and privacy documents
4. `UI_WIREFRAME_NOTES.md`
5. Approved HTML visual references
