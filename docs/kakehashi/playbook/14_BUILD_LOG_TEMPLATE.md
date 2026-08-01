---
title: "14 — Build Log & Checklist"
status: "TEMPLATE snapshot"
source_notion_title: "14 — Build Log & Checklist"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion when connected; repository root BUILD_LOG.md during DOC-SYNC-REPO-FIRST"
codex_edit_policy: "read-only"
template_export: "true"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

> [!WARNING]
> Template export only. Do not treat this file as the live progress source and never add secrets, credentials, or production evidence here.

# 14 — Build Log & Checklist

> [!NOTE]
> **Master Build Log Kakehashi.** Notion adalah master saat terhubung. Saat offline, repository root `BUILD_LOG.md` adalah master lokal; Notion menjadi mirror pada sinkronisasi berikutnya. Chapter wave hanya menaut ke master aktif.
>
## Status Halaman
**Penyusunan Playbook v1 + VPS Harian Ephemeral Addendum selesai.** Status wave build tetap `Belum dimulai` sampai operator benar-benar menjalankan coding.
## Aturan Master Log
1. Catat satu baris untuk setiap task Codex.
2. Builder dan Reviewer harus berasal dari percakapan terpisah.
3. Jangan tandai selesai sebelum bukti test dan verdict Reviewer tersedia.
4. Jangan menyimpan secret, nilai `.env`, token, password, recovery code, atau credential.
5. Jika verdict `FAIL` atau `BLOCKED`, status task tetap terbuka dan wave berikutnya tidak boleh dimulai.
6. Status wave hanya diperbarui di master aktif: halaman ini saat Notion terhubung, atau root `BUILD_LOG.md` saat offline.
## Status Pass Penyusunan Buku
<table fit-page-width="true" header-row="true">
<tr>
<td>Pass</td>
<td>Cakupan</td>
<td>Status</td>
</tr>
<tr>
<td>Pass 1</td>
<td>Kerangka buku</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 2</td>
<td>GitHub, Codex, operating model, prompt generik</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 3a</td>
<td>Wave 0–2</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 3b</td>
<td>Wave 3–5</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 3c</td>
<td>Wave 6–7</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 4</td>
<td>Deployment, backup, restore</td>
<td>Complete</td>
</tr>
<tr>
<td>Pass 5</td>
<td>Quality pass</td>
<td>Complete</td>
</tr>
</table>
## Template Log Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Tanggal</td>
<td>Wave/Task</td>
<td>Branch/Commit</td>
<td>Builder</td>
<td>Reviewer</td>
<td>Verdict</td>
<td>Bukti</td>
<td>Catatan</td>
</tr>
<tr>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
</table>
## Status Addendum VPS Harian
- [x] Pass A — model tiga lingkungan dan Wave integration
- [x] Pass B — SOP sewa/bootstrap/deploy/test/destroy
- [x] Pass C — prompt Codex dan session log
- [x] Pass D — quality check domain locks/security/restore gate
## Ephemeral VPS Test Session Log
Isi satu baris untuk setiap VPS harian. **Tidak ada secret pada tabel ini.**
<table fit-page-width="true" header-row="true">
<tr>
<td>Session ID</td>
<td>Mulai</td>
<td>Provider</td>
<td>Instance/Region</td>
<td>Spec</td>
<td>Commit/Tag</td>
<td>Data Mode</td>
<td>Smoke</td>
<td>Restore</td>
<td>Destroyed</td>
<td>Billing Stopped</td>
<td>Catatan</td>
</tr>
<tr>
<td>EVPS-YYYYMMDD-A</td>
<td>—</td>
<td>Octa Cube</td>
<td>label + region/IP</td>
<td>vCPU/RAM/disk</td>
<td>—</td>
<td>Empty/Synthetic/Test Restore</td>
<td>—</td>
<td>N/A/PASS/FAIL</td>
<td>Ya/Tidak</td>
<td>Ya/Tidak</td>
<td>Tanpa secret</td>
</tr>
</table>
### Aturan sesi VPS
- Catat tujuan sesi, operator, target waktu destroy, commit/tag, dan verdict Reviewer di catatan.
- Catat IP/hostname boleh, tetapi jangan bersama credential.
- Jika `Destroyed` atau `Billing Stopped` belum terverifikasi, sesi tetap terbuka.
- Data production/PII nyata dilarang secara default.
- VPS test tidak mengubah status wave; wave tetap berubah hanya setelah task build yang benar-benar dijalankan dan direview.
## Status Wave
<table fit-page-width="true" header-row="true">
<tr>
<td>Wave</td>
<td>Status</td>
<td>Gate utama</td>
<td>Snapshot</td>
</tr>
<tr>
<td>0 — Environment</td>
<td>Belum dimulai</td>
<td>Fresh setup dapat diulang</td>
<td>—</td>
</tr>
<tr>
<td>1 — Auth/Audit</td>
<td>Belum dimulai</td>
<td>Auth, pending, after-commit</td>
<td>—</td>
</tr>
<tr>
<td>2 — Lookup</td>
<td>Belum dimulai</td>
<td>Request→approve→dropdown</td>
<td>—</td>
</tr>
<tr>
<td>3 — Candidates</td>
<td>Belum dimulai</td>
<td>Draft→approval→revision</td>
<td>—</td>
</tr>
<tr>
<td>4 — Jobs</td>
<td>Belum dimulai</td>
<td>Pull tanpa duplikasi</td>
<td>—</td>
</tr>
<tr>
<td>5 — Placement</td>
<td>Belum dimulai</td>
<td>Transfer batch atomik</td>
<td>—</td>
</tr>
<tr>
<td>6 — Guest</td>
<td>Belum dimulai</td>
<td>Whitelist tanpa PII leak</td>
<td>—</td>
</tr>
<tr>
<td>7 — Hardening</td>
<td>Belum dimulai</td>
<td>Restore test lulus</td>
<td>—</td>
</tr>
</table>
## Verdict Reviewer
- **PASS:** boleh commit/merge jika semua gate lain lulus.
- **PASS WITH NON-BLOCKING NOTES:** boleh lanjut; catat pekerjaan kecil yang tidak mengubah kontrak.
- **FAIL — FIX REQUIRED:** jangan merge atau pindah task.
- **BLOCKED — AUTHORITY CONFLICT:** berhenti dan minta keputusan operator; jangan redesign sendiri.
## Bukti yang Boleh Disimpan
- Command dan hasil test tanpa secret.
- Screenshot hasil test tanpa credential.
- Commit hash dan branch.
- Reviewer verdict.
- Log restore yang sudah disanitasi.
---
## Cara Memulai Build
1. Buka Notion page reference bila terhubung; bila tidak, baca root `BUILD_LOG.md`.
2. Selesaikan checklist GitHub dan Codex.
3. Mulai W0-T1 Inspect-only.
4. Isi log task pertama pada master aktif.
5. Jangan mengubah status Wave 0 sebelum task benar-benar dijalankan.
---
**Status:** Playbook FINAL v1; build aplikasi belum dimulai.
