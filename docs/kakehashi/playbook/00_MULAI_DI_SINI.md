---
title: "00 — Mulai di Sini"
status: "FINAL v1"
source_notion_title: "00 — Mulai di Sini"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 00 — Mulai di Sini

> [!NOTE]
> **Pintu masuk harian operator non-IT.** Gunakan halaman ini setiap kali menjalankan satu task Codex.
>
## Jalur Cepat Harian
1. Buka bab **wave aktif** dan pilih satu task kecil.
2. Tempel Prompt Builder ke percakapan Codex Builder.
3. Builder melakukan Inspect → Plan; jangan izinkan edit sebelum rencana dibaca.
4. Cek laporan Builder: file berubah, command, test, risiko, dan hal yang tidak dikerjakan.
5. Tempel Prompt Reviewer ke percakapan Codex **baru dan terpisah**.
6. Baca verdict:
	- ✅ `PASS` → lanjut ke commit.
	- ⚠️ `PASS WITH NON-BLOCKING NOTES` → catat notes, lalu commit bila gate lulus.
	- ⛔ `FAIL — FIX REQUIRED` → kembali ke Builder; jangan merge.
	- ⛔ `BLOCKED — AUTHORITY CONFLICT` → STOP dan minta keputusan operator.
7. Commit/snapshot hanya setelah semua gate task lulus.
8. Catat hasil di Notion page reference.
9. Lanjut ke task berikutnya atau STOP sesuai verdict.
## Mode Terminal Non-IT
### Mode A — Codex menjalankan command
- Codex menjelaskan command, dampak, dan risiko terlebih dahulu.
- Operator menyetujui command berisiko.
- Codex melaporkan output dan exit status.
### Mode B — Operator copy-paste
- Codex memberi satu command per langkah.
- Codex menjelaskan folder, output yang diharapkan, dan stop condition.
- Operator mengirim output yang sudah disanitasi.
> [!NOTE]
> Jangan pernah menempel password, token, recovery code, access key, isi `.env`, private key, atau credential ke prompt, Notion, screenshot, maupun Build Log.
>
## Persiapan Bertahap
- **Sebelum Wave 0:** GitHub, Codex, password manager, mesin development.
- **Sebelum fitur foto:** Cloudflare R2 privat.
- **Sebelum Guest/go-live:** VPS, domain/DNS, email, kebijakan permission Google Drive, aplikasi TOTP.
## Jalur VPS Test Day — Opsional
Gunakan hanya setelah task local sudah PASS dan tujuan sesi tertulis.
1. Catat tujuan VPS test day di Notion page reference.
2. Sewa instance **ephemeral** dengan label test; jangan gunakan sebagai source of truth.
3. Deploy hanya dari commit/tag GitHub yang sudah PASS—bukan folder laptop acak.
4. Isi test secret dari password manager; jangan masukkan ke prompt/Notion/GitHub.
5. Gunakan synthetic data, R2 test bucket, dan Drive test folder/placeholder.
6. Jalankan smoke test lalu Reviewer session terpisah.
7. Simpan bukti tersanitasi, revoke credential sementara bila ada, dan destroy instance.
8. Catat `Destroyed?` dan `Billing stopped?` pada Build Log.
**Jangan sewa VPS hanya untuk memulai Wave 0.** Local Dev tetap lingkungan utama.
## Aturan Satu Task
Task yang baik dapat dijelaskan dalam satu kalimat dan diuji sendiri. Jangan meminta Codex “membangun seluruh wave” dalam satu percakapan.
## Sumber Status
Semua status task dan wave hanya dicatat di Notion page reference.
---
**Status:** FINAL v1 — siap digunakan sebagai jalur harian operator.
