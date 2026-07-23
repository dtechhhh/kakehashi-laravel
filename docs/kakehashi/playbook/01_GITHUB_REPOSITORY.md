---
title: "01 — GitHub & Repository"
status: "FINAL v1"
source_notion_title: "01 — GitHub & Repository"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 01 — GitHub & Repository

> [!NOTE]
> Panduan menyiapkan repository GitHub privat yang dapat dilacak, direview, dan dipulihkan.
>
## Tujuan
Menyediakan tempat kode Kakehashi yang privat, aman, dan mudah dioperasikan non-IT.
## Langkah Inti
1. Buat repository GitHub dengan visibility **Private**.
2. Tambahkan README awal dan `.gitignore`.
3. Jangan unggah `.env`, credential, private key, atau dump database.
4. Gunakan `main` sebagai baseline stabil.
5. Buat branch per wave: `wave/0-environment` hingga `wave/7-hardening`.
6. Gunakan task branch untuk pekerjaan berisiko, misalnya `task/w1-pending-request`.
7. Satu commit harus menjelaskan satu perilaku.
## Aturan Merge
Jangan merge jika Reviewer memberi FAIL/BLOCKED, test gagal, ada secret/debug code, atau perubahan keluar scope.
## Snapshot Wave
`wave-0-baseline`, `wave-1-auth-complete`, `wave-2-lookup-complete`, `wave-3-candidates-complete`, `wave-4-jobs-complete`, `wave-5-placement-complete`, `wave-6-guest-complete`, `wave-7-go-live-candidate`.
## Prompt Builder — Inspect Repository
```plain text
Anda adalah Builder Agent Kakehashi. Lakukan INSPECT-ONLY terhadap repository ini. Jangan mengubah file dan jangan menjalankan command destruktif.

Periksa repository visibility, branch aktif, status Git, file yang berpotensi berisi secret, README, .gitignore, AGENTS.md, docs authority, dan risiko sebelum Wave 0.

Laporkan kondisi saat ini, temuan Critical/High/Medium/Low, rencana patch minimum, file yang diperkirakan berubah, command yang nantinya perlu dijalankan, dan stop condition.

Jangan edit sebelum operator menyetujui rencana.
```
## Prompt Reviewer — Repository Safety
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah file atau menjalankan perbaikan.

Tinjau laporan Builder dan diff repository. Pastikan repository private, tidak ada .env/secret/token/private key/dump database, perubahan sesuai scope setup, dan tidak ada Docker-first wajib atau abstraksi aplikasi pada tahap ini.

Berikan temuan per severity, gate lulus/gagal, dan verdict: PASS, PASS WITH NON-BLOCKING NOTES, FAIL — FIX REQUIRED, atau BLOCKED — AUTHORITY CONFLICT.
```
## Checklist Siap Wave 0
- [ ] Repository Private.
- [ ] `.gitignore` melindungi `.env` dan file lokal.
- [ ] Tidak ada secret di history saat ini.
- [ ] `main` menjadi baseline stabil.
- [ ] README dapat diikuti tanpa secret.
- [ ] Reviewer memberi PASS.
- [ ] Hasil dicatat di Notion page reference.
---
**Status:** FINAL v1 — siap digunakan sebelum memulai Wave 0.
