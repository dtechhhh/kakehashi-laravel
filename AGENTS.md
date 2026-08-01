# Kakehashi Codex Instructions

## Purpose

Build Kakehashi with ChatGPT Codex in small, reviewable tasks. Do not redesign locked product or domain decisions during coding.

## Authority order

1. `docs/kakehashi/authority/PRD_Kakehashi_v0_3_14.md`
2. `docs/kakehashi/authority/DECISIONS_LOG.md` — Batch A/B
3. Foundation, module, schema, API, security, and privacy documents
4. `docs/kakehashi/ui/UI_WIREFRAME_NOTES.md`
5. Approved HTML — visual reference only, never business authority

Notion remains canonical when connected. If Notion is unavailable, an explicit `DOC-SYNC-REPO-FIRST` commit makes the committed repository snapshots and root `BUILD_LOG.md` the operational source for build execution until a later Notion sync. If committed snapshots conflict, stop and report it.

## Required reading before every task

1. Read this file.
2. Read `docs/kakehashi/README.md`.
3. Read `docs/kakehashi/BUILD_INVARIANTS.md`.
4. Read the active wave guide.
5. Read the active module document.
6. Read `DATABASE_SCHEMA.md` when persistence is involved.
7. Read `SECURITY_CHECKLIST.md` when access, secrets, files, logs, or external surfaces are involved.

Use the routing table. Do not load every document for every task.

## Locked stack

- PHP 8.4
- Laravel 13
- PostgreSQL 18 with `pg_trgm`
- Redis
- Livewire 4
- Blade custom
- Tailwind CSS 4
- Modular monolith

Use PostgreSQL, not SQLite, for database-behavior tests.

## Mandatory invariants

- Email is the only login identifier.
- `pending_request` is the Checker decision source for approval domains other than `lookup_request` and `company_request`.
- `lookup_request.status` and `company_request.status` are their respective Checker decision sources; neither flow creates `pending_request` or adds a type to `PendingType`.
- One active pending exists per type and target for approval domains that use `pending_request`.
- Maker cannot approve their own request.
- Candidate starts as Draft.
- Candidate NIK is generated on first submit.
- Only one active Candidate revision exists.
- Candidate availability changes only through Candidates public service.
- One active interview participation exists per Candidate.
- Normal Placement starts from `Siap Dikirim + Sedang Dipakai`.
- Normal transfer never creates a `Tersedia` window.
- Force-Majeur does not require step-up.
- `FM_REJECTED` is canonical.
- Candidate soft-delete and restore are not exposed.
- Anonymized Candidates are excluded from Guest G2 and G3.
- Business, audit, and in-app notification commit before email dispatch.
- Redis email and queue dispatch happen after commit.
- Enqueue failure must not roll back business data.
- Candidate photos use private R2.
- Candidate documents are private Google Drive URLs.
- Restore test is a hard go-live gate.

## Environment model

- Local development and GitHub are the code source of truth.
- Ephemeral test VPS instances are for integration, smoke, deployment, and restore rehearsal only.
- Production uses a separate stable VPS.
- Nothing may exist only on an ephemeral VPS.
- Deploy only reviewed commits or tags to a test VPS.
- Use synthetic data and test-only credentials on test VPS instances.

## Offline repository mode

- The repository is the source of truth for code, tags, build status, and recorded decisions while `DOC-SYNC-REPO-FIRST` is active.
- `BUILD_LOG.md` is the tracked local Build Log; `docs/tmp/` is working material only and is not canonical.
- Builder and Reviewer remain separate conversations; a wave cannot pass without the separate Reviewer verdict and evidence.
- Notion is a later mirror when connectivity becomes available. Do not block a wave or request secrets for synchronization.
- If committed authority snapshots conflict with each other, stop; do not infer a product decision.

## Before editing

1. State wave and task ID.
2. Summarize applicable rules.
3. List expected files to change.
4. Propose three to seven steps.
5. List required tests.
6. Identify stop conditions.
7. Wait for operator approval.

## During and after editing

- Make the smallest complete change.
- Do not modify unrelated files.
- Do not edit `docs/kakehashi/` during a coding task.
- Do not create speculative abstractions or empty frameworks.
- Do not bypass Policies or public services.
- Never request secrets in chat.
- Run focused, negative, authorization, transaction, and concurrency tests as relevant.
- Run the broader affected suite, formatter, and PostgreSQL migration fresh when schema changed.
- Report files, commands, results, risks, and one commit message.

## Builder and Reviewer separation

Use separate Codex conversations for risk-sensitive work. Reviewer does not modify code. Verdicts: PASS, PASS WITH NON-BLOCKING NOTES, FAIL — FIX REQUIRED, or BLOCKED — AUTHORITY CONFLICT.

## Prohibited architecture

- Microservices
- Event bus
- Full CQRS
- Kubernetes
- HA cluster
- WebSocket
- Mandatory Docker-first workflow
- Filament as primary panel
- Mass repository or DTO abstractions
- Empty frameworks created for later

## Documentation sync

Documentation snapshots may change only in a dedicated `DOC-SYNC` task. Application coding is prohibited in DOC-SYNC. Update `MANIFEST.md`, review the diff, and never introduce new product decisions during export.
