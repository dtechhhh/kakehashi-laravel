---
title: "05 — Wave 2: Lookup & Master Perusahaan"
status: "FINAL v1"
source_notion_title: "05 — Wave 2: Lookup & Master Perusahaan"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 05 — Wave 2: Lookup & Master Perusahaan

> [!NOTE]
> **Wave 2 — Lookup & Master Perusahaan.** Menyediakan dropdown bilingual dan perusahaan yang konsisten sebelum form domain dibuat.
>
## Apa Artinya untuk Operator
Operator dapat mengelola nilai referensi dan perusahaan tanpa membuat data lama rusak. Nilai baru bisa diminta, ditinjau, lalu dipakai pada form.
## Prasyarat
- [ ] Wave 1 lulus dan snapshot `wave-1-auth-complete` tercatat.
- [ ] Step-up, audit, `pending_request`, dan after-commit foundation tersedia.
- [ ] Role Super Admin tersedia.
## Dokumen Wajib untuk Builder
- MODULE_LOOKUP_DATA
- PRD §5.1, §5.4, §7.8, §9.4
- ROLES_AND_PERMISSIONS §5.5
- DATABASE_SCHEMA lookup/master
- API_CONTRACTS LookupService
- MODULE_AUTH Step-up
- SECURITY_CHECKLIST
## Lingkup Boleh
- Lookup tables, schema, seed idempotent, and bilingual labels.
- LookupService read/options/assertActive.
- Redis cache per tabel/bahasa dengan invalidasi on write.
- Soft-disable.
- Lookup request dan company request.
- Master Perusahaan.
- Step-up/audit untuk mutasi Super Admin.
## Lingkup Dilarang
- Tidak menjadikan status state machine sebagai lookup editable.
- Tidak hard-delete nilai yang pernah dirujuk.
- Tidak menyimpan label sebagai nilai domain utama.
- Tidak membuat semua UI domain Kandidat/Jobs/Placement.
- Tidak mengubah `code` setelah dibuat.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W2-T1 Lookup schema</td>
<td>25 lookup dan master perusahaan sesuai schema</td>
<td>Code unique/immutable</td>
</tr>
<tr>
<td>W2-T2 Seed idempotent</td>
<td>Seed dapat dijalankan berulang</td>
<td>Tidak ada duplikasi</td>
</tr>
<tr>
<td>W2-T3 Lookup service/cache</td>
<td>Label/options bilingual</td>
<td>Invalidate setelah write</td>
</tr>
<tr>
<td>W2-T4 Lookup CRUD</td>
<td>Super Admin + step-up + audit</td>
<td>Soft-disable</td>
</tr>
<tr>
<td>W2-T5 Request flow</td>
<td>Lookup/company request → keputusan</td>
<td>Gunakan fondasi Wave 1</td>
</tr>
<tr>
<td>W2-T6 Company master</td>
<td>nama_ja wajib, soft-disable</td>
<td>Audit mutasi</td>
</tr>
<tr>
<td>W2-T7 Review akhir</td>
<td>Security/seed/cache review</td>
<td>PASS sebelum Wave 3</td>
</tr>
</table>
## Prompt Builder — Seed dan Lookup Service
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W2-T1/W2-T2/W2-T3].

Authority: MODULE_LOOKUP_DATA; DATABASE_SCHEMA lookup; API_CONTRACTS LookupService; PRD §5.1/§7.8/§9.4; ARCHITECTURE; SECURITY_CHECKLIST.

Wajib:
- code unik, tidak kosong, dan immutable;
- label_id dan label_ja wajib;
- seed idempotent berdasarkan code;
- cache Redis per tabel/per bahasa dengan invalidasi setelah write;
- cache bukan sumber kebenaran;
- enum/status state machine tidak dibuat lookup editable;
- kategori_force_majeur dan jenis_dokumen tercakup.

Tambahkan test seed dua kali, code duplikat/kosong, fallback label, cache invalidation, dan value nonaktif.
```
## Prompt Builder — Request dan Master Perusahaan
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W2-T4/W2-T5/W2-T6].

Wajib:
- Super Admin saja yang mutasi lookup/perusahaan;
- step-up untuk seluruh mutasi;
- audit action type sesuai kontrak;
- nilai yang sudah dipakai soft-disable, bukan hard-delete;
- code tidak dapat diubah;
- Staf Input/Asisten Manajer hanya mengajukan request sesuai role;
- request/approval memakai fondasi Wave 1, bukan pola baru;
- nama_ja perusahaan wajib.

Tambahkan negative authorization, self-action yang relevan, audit, dan test soft-disable.
```
## Prompt Reviewer — Lookup
```plain text
Anda adalah Reviewer Agent Kakehashi terpisah. Jangan mengubah kode.

Tinjau [DIFF/COMMIT dan LAPORAN BUILDER]. Pastikan:
- seed idempotent;
- code immutable/unique;
- label bilingual wajib;
- soft-disable menjaga data lama;
- request memakai foundation Wave 1;
- cache invalidation benar dan Redis bukan source of truth;
- mutasi Super Admin membutuhkan step-up dan audit;
- enum state machine tidak menjadi lookup editable;
- tidak ada scope Kandidat/Jobs/Placement yang ikut dibuat.

Berikan temuan severity dan verdict.
```
## Definition of Done
- [ ] 25 lookup dan master perusahaan tersedia sesuai schema final.
- [ ] Seeder aman dijalankan dua kali.
- [ ] `code` unique, non-empty, immutable.
- [ ] Label ID/JA wajib dan fallback aman.
- [ ] Lookup nonaktif tidak dapat dipilih untuk data baru.
- [ ] Data lama tetap dapat dirender.
- [ ] Request lookup/perusahaan mengikuti role dan approval foundation.
- [ ] Semua mutasi Super Admin memakai step-up dan audit.
- [ ] Cache invalidation lulus test.
- [ ] Reviewer PASS dan snapshot `wave-2-lookup-complete` tercatat.
## Stop Condition
- Status state machine dapat diedit dari dashboard.
- Hard-delete digunakan untuk nilai yang sudah dirujuk.
- Cache menjadi sumber kebenaran.
- Code dapat diubah setelah digunakan.
- Approval request mengabaikan Wave 1 foundation.
## Bukti Sukses Minimum
1. Seed dua kali tidak membuat duplikasi.
2. Request lookup disetujui lalu tampil sebagai opsi bilingual.
3. Lookup nonaktif tidak bisa dipilih tetapi masih tampil pada data lama.
## Commit dan Snapshot
Commit per kemampuan: schema, seed, service/cache, CRUD/request, company. Setelah review akhir lulus, tag `wave-2-lookup-complete` dan catat di Build Log.
---
**Status:** FINAL v1 — panduan operasional Wave 2 siap digunakan.
