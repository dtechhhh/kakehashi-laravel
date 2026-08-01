# Kakehashi — Builder Execution: UI Wave 0–3

Status: `READY FOR BUILDER`
Branch: `ui-w0-w3-build`
Operator approval: granted on 2026-08-01
Reviewer: separate read-only review after `UI-W3-HANDOFF`

## Objective

Build the missing presentation layer for Wave 0–3 so the operator can run
manual tests before Wave 4. This document is an execution prompt for the
Builder Agent. It does not authorize Wave 4, Placement, Guest, or the full
anonymization UI.

## Authority and required reading

Before every task, read:

1. `AGENTS.md`.
2. `docs/kakehashi/README.md`.
3. `docs/kakehashi/BUILD_INVARIANTS.md`.
4. The active wave guide in `docs/kakehashi/playbook/`.
5. The active module, schema, API, security, and privacy documents.
6. `docs/kakehashi/ui/DESIGN.md`.
7. `docs/kakehashi/ui/UI_WIREFRAME_NOTES.md`.
8. `docs/kakehashi/ui/UI_WIREFRAME_APPROVED_REFS.md` as visual reference only.

Repository snapshots and `BUILD_LOG.md` are the operational source while
repo-first offline mode is active. Do not require Notion access.

Authority order remains:

`PRD/decisions/foundation/module/schema/API/security/privacy` →
`UI_WIREFRAME_NOTES.md` → `DESIGN.md` → approved visual references.

## Non-negotiable implementation rules

- Use PHP 8.4, Laravel 13, Livewire 4, custom Blade/Livewire, Tailwind CSS 4,
  PostgreSQL, and Redis already present in the repository.
- Use existing Policies, public Services, routes, and contracts. Do not access
  domain tables/models directly from presentation code.
- Do not put business rules, authorization, approval, step-up, or state
  transitions in Blade/Livewire views.
- Do not add Filament, a new UI framework, a second design system, an event
  bus, WebSocket, mass DTO/repository abstractions, or empty scaffolding.
- Do not bulk-export or copy raw HTML from Notion. Use the repository-local
  design snapshot and approved-reference index.
- Remove preview switchers, developer bars, fake-data controls, and offline
  fallback CSS from production screens.
- Use PostgreSQL for database-behavior tests, never SQLite.
- Use synthetic local data. Never add secrets, production URLs, or credentials.
- K6 anonymization UI remains Wave 7 scope.
- Do not build Jobs, Placement, or Guest UI in this execution.

## Operating protocol

The operator has approved this complete sequence. Before editing each task,
the Builder must briefly report:

1. wave and task ID;
2. applicable rules;
3. expected files;
4. three to seven implementation steps;
5. focused, negative, authorization, and manual tests;
6. stop conditions.

Then implement the smallest complete patch. After each task:

- run the relevant focused tests and formatter/linter;
- review the diff and `git diff --check`;
- commit only that task;
- report files, commands/results, risks, and commit hash.

Do not provide a final PASS verdict. The final verdict is reserved for the
separate Reviewer after `UI-W3-HANDOFF`.

## Execution sequence

### UI-W0-T1 — Foundation shell

Build only presentation foundation:

- shared public/authenticated layouts where supported by the current app;
- shell, navigation, permission-aware menu rendering, typography, spacing,
  and tokens from `DESIGN.md`;
- skip link, focus state, semantic headings, field error association, and
  status badge glyph + text + color;
- shared loading, empty, validation, forbidden, not-found, session-expired,
  `409`, flash, and notification states;
- asset build and route smoke.

Do not implement login, Candidate, Jobs, Placement, Guest, or fake state
switchers in W0.

Required evidence: shell route smoke, accessibility basics, asset build, lint,
and focused view/component tests where applicable.

Commit: `ui(w0): add application shell`

### UI-W1-T1 — Authentication screens A1–A5

Build screens using the existing Auth contracts:

- A1 Login: email-only identifier and validation errors;
- A2 forced password change;
- A3 TOTP enrollment;
- A4 TOTP challenge;
- A5 lockout.

Server-side authentication, password, session, TOTP, recovery, and lockout
rules remain authoritative. The UI must not simulate successful authentication.

Required evidence: successful/failed login, non-active user, forced password
change, TOTP challenge/enrollment, recovery, lockout, authorization negatives,
and relevant feature tests.

Commit: `ui(w1): add authentication screens`

### UI-W1-T2 — Step-up and foundation admin screens

Build:

- A6 step-up re-auth modal;
- S4 account management;
- S5 audit viewer, using the existing Wave 1 contracts.

Step-up must use the existing `StepUpService` and final five-trigger rule.
Audit rendering must not expose passwords, TOTP, raw tokens, or secret PII.
If a required backend contract is absent, stop and report the gap; do not
invent fake actions or fake data.

Required evidence: step-up success/failure/expiry, permission negatives,
audit filtering/rendering, and no-secret log/audit checks.

Commit: `ui(w1): add step-up and admin foundation screens`

### UI-W2-T1 — Lookup screen S1

Build S1 bilingual lookup CRUD using the existing LookupService and Policy:

- ID/JA labels and safe fallback;
- create/edit/soft-disable states;
- inactive lookup rendering for old data;
- step-up, validation, authorization, loading, empty, and error states.

Do not make state-machine statuses editable lookup values.

Required evidence: bilingual rendering, permission negatives, step-up, code
immutability, soft-disable, and focused PostgreSQL/application tests.

Commit: `ui(w2): add lookup screens`

### UI-W2-T2 — Request queue S2 and company master S3

Build:

- S2 lookup/company request queue and decision states;
- S3 company master with bilingual company data and soft-disable behavior.

Use `lookup_request.status` and `company_request.status` as their respective
decision sources. Do not create or assume `pending_request` for these flows.
Super Admin mutations require existing step-up and audit behavior.

Required evidence: request → decision, maker/checker guard, double decision
`409`, company validation, soft-disable, and after-commit error handling.

Commit: `ui(w2): add request and company screens`

### UI-W3-T1 — Candidate list K1 and detail K2

Build:

- K1 candidate list with filters/pagination allowed by the contract;
- K2 read-only candidate detail;
- loading, empty, authorization, `409`, and server-error states;
- private photo/document presentation without exposing private URLs directly.

Use Candidates public services and Policies. Do not add edit or approval
actions to the read-only detail screen unless the authority contract allows it.

Required evidence: role-based visibility, list/detail manual smoke, pagination,
private file error/loading behavior, and focused tests.

Commit: `ui(w3): add candidate list and detail screens`

### UI-W3-T2 — Candidate form K3

Build K3 create/edit:

- Draft starts without NIK or pending;
- submit calls the existing Candidates service;
- NIK and similarity warning are rendered from the server response;
- validation, loading, duplicate warning, stale version, and `409` reload;
- photo/file fields follow the private R2 and private Drive contracts.

Do not generate NIK or calculate similarity in the browser.

Required evidence: Draft → submit, NIK-on-submit, similarity soft warning,
validation negatives, authorization negatives, and version conflict recovery.

Commit: `ui(w3): add candidate form flow`

### UI-W3-T3 — Candidate review K4 and revision K5

Build:

- K4 review queue and approve/reject actions;
- rejection note validation;
- K5 revision/diff and maker resubmission flow;
- maker-checker protection and server-side authorization;
- pending, loading, empty, stale, and `409` states.

Approver does not edit Candidate data directly. Revision merge must use the
existing service and preserve NIK, availability, and operational history.

Required evidence: Draft → submit → reject → edit/resubmit → approve, maker
self-approval denial, reject-note validation, one active revision, and
revision conflict behavior.

Commit: `ui(w3): add candidate review and revision screens`

### UI-W3-HANDOFF — Builder verification

After all UI tasks are complete:

1. Run the full relevant application test suite against PostgreSQL.
2. Run `composer lint`.
3. Run the repository-defined frontend asset build.
4. Run route/browser smoke for A1–A6, S1–S5, and K1–K5.
5. Check keyboard focus, labels, error association, status glyphs, ID/JP
   behavior, and the five step-up triggers.
6. Scan changed files for secrets, preview controls, and production URLs.
7. Review the complete diff and confirm no W4+ scope was added.
8. Add one `BUILD_LOG.md` row per UI task with Builder evidence and verdict
   `PENDING REVIEW`; Reviewer remains `PENDING REVIEW`.

Commit: `docs(build-log): record ui builder handoff`

## Final Builder report

Return:

- branch name and all commit hashes;
- changed files grouped by task;
- test/lint/build/browser-smoke commands and results;
- manual test data/setup instructions;
- known risks or deferred items;
- confirmation that K6, Jobs, Placement, Guest, and raw HTML bulk export were
  not implemented;
- final review request with verdict status `PENDING REVIEW`.

## Stop conditions

Stop and report before continuing if:

- repository snapshots conflict;
- a required domain/service/API contract is missing or ambiguous;
- UI logic would require bypassing a Policy or public Service;
- a screen needs a new product/domain decision;
- PostgreSQL cannot be used for relevant tests;
- a secret, credential, production URL, or unsafe preview control appears;
- the task expands into W4, Placement, Guest, or Wave 7 anonymization;
- the implementation requires a new framework or mass abstraction.
