---
title: "02 — Menyiapkan Codex"
status: "FINAL v1"
source_notion_title: "02 — Menyiapkan Codex"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 02 — Menyiapkan Codex

> [!NOTE]
> Panduan mengoperasikan ChatGPT Codex sebagai Builder dan Reviewer yang disiplin terhadap authority, scope, dan bukti test.
>
## Operating Model
```plain text
Inspect → Plan → Persetujuan operator → Implement → Verify → Laporan Builder → Review terpisah → Commit → Build Log
```
## Authority di Repository
Simpan salinan read-only dokumen final pada folder `docs/`: PRD, DECISIONS_LOG Batch A/B, Architecture, Database Schema, Status State Machine, Business Rules, Roles, API Contracts, Security, modul, privacy, deployment, backup/recovery, dan UI Notes.
Approved HTML hanya disertakan bila task mengerjakan layar dan wajib diberi label **NON-AUTHORITATIVE VISUAL REFERENCE**.
## Aturan Builder
1. Baca authority dan kode/test relevan sebelum edit.
2. Laporkan scope, file berubah, test, transaction, authorization, audit, dan concurrency requirement.
3. Buat patch terkecil yang lengkap.
4. Jalankan focused test, negative test, broader suite, formatter, serta migration fresh bila schema berubah.
5. Laporkan hasil, risiko, dan commit message.
## Aturan Reviewer
- Reviewer berasal dari percakapan Codex lain.
- Reviewer tidak mengubah kode.
- Reviewer memeriksa scope, authority, security, transaction, audit, concurrency, test, dan overengineering.
- Verdict hanya PASS, PASS WITH NON-BLOCKING NOTES, FAIL — FIX REQUIRED, atau BLOCKED — AUTHORITY CONFLICT.
## Root `AGENTS.md` — Siap Tempel
```markdown
# Kakehashi Coding Instructions

## Authority
Follow this order:
1. docs/authority/PRD_Kakehashi_v0_3_14.md
2. docs/authority/DECISIONS_LOG.md — Batch A/B
3. Domain, schema, module, API, security, and privacy documents
4. docs/ui/UI_WIREFRAME_NOTES.md
5. Approved HTML only as non-authoritative visual reference

Never redesign locked domain decisions.

## Stack
- PHP 8.4
- Laravel 13
- PostgreSQL 18
- Redis
- Livewire 4
- Blade custom
- Tailwind CSS 4
- Modular monolith

Use PostgreSQL—not SQLite—for database behavior tests.

## Mandatory invariants
- Email is the only login identifier.
- pending_request is the Checker decision source.
- One active pending per type/target.
- Maker cannot approve their own request.
- Candidate starts as Draft.
- Candidate NIK is generated on first submit.
- Only one active Candidate revision.
- Candidate availability changes only through Candidates public service.
- One active interview participation per Candidate.
- Normal Placement starts from Siap Dikirim + Sedang Dipakai.
- Normal transfer never creates a Tersedia window.
- Force-Majeur does not require step-up.
- FM_REJECTED is canonical.
- Candidate soft-delete/restore is not exposed.
- Anonymized Candidates are excluded from Guest G2/G3.
- Business, audit, and in-app notification commit before email dispatch.
- Redis email/queue dispatch is after-commit.
- Enqueue failure must not roll back business data.
- Candidate photos use private R2.
- Candidate documents are private Google Drive URLs.
- Approved HTML is not business authority.

## Before editing
1. Read relevant documents and existing files.
2. State task scope.
3. List expected files to change.
4. State tests to add or run.
5. Identify transaction, authorization, audit, and concurrency requirements.

## During editing
- Make the smallest complete change.
- Do not modify unrelated files.
- Do not create speculative abstractions.
- Do not add packages without explaining why Laravel features are insufficient.
- Do not use cross-module Eloquent models directly.
- Do not bypass Policies or public services.
- Do not run destructive production commands.

## After editing
1. Run focused tests.
2. Run relevant broader tests.
3. Report files changed.
4. Report commands and results.
5. Report remaining risks.
6. Suggest one commit message.

## Prohibited architecture
- Microservices
- Event bus
- Full CQRS
- Kubernetes
- HA cluster
- WebSocket
- Mandatory Docker-first workflow
- Filament as primary panel
- Mass repository/DTO abstractions
- Empty frameworks created for later
```
> [!NOTE]
> Builder tetap wajib membaca dokumen task. `AGENTS.md` adalah pagar global, bukan pengganti PRD atau modul final.
>
## Stop Condition
- Codex masuk wave lain.
- Codex meminta secret di chat/repository.
- Test database diganti SQLite.
- Dependency besar tidak mempunyai alasan langsung.
- Command production/destruktif belum disetujui.
---
**Status:** FINAL v1 — operating model dan `AGENTS.md` siap dipakai.
