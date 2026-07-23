---
title: "10 — Wave 7: Hardening & Go-Live"
status: "FINAL v1"
source_notion_title: "10 — Wave 7: Hardening & Go-Live"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 10 — Wave 7: Hardening & Go-Live

> [!NOTE]
> **Wave 7 — Hardening & Go-Live.** Membuktikan aplikasi aman, dapat dipulihkan, dan layak menerima data nyata.
>
## Apa Artinya untuk Operator
Wave ini bukan tempat menambah fitur. Ini adalah pemeriksaan akhir: izin, anonimisasi, file, Redis, backup, restore, staging ringan, dan go-live harus terbukti benar.
## Prasyarat
- [ ] Wave 6 lulus dan snapshot `wave-6-guest-complete` tercatat.
- [ ] VPS/domain/email/Drive policy/TOTP dipersiapkan sesuai tahap.
- [ ] Backup bucket R2 terpisah tersedia sebelum rehearsal go-live.
## Dokumen Wajib
- SECURITY_CHECKLIST
- DATA_RETENTION_AND_PRIVACY
- DEPLOYMENT
- BACKUP_AND_RECOVERY
- MODULE_AUTH/CANDIDATES/GUEST_ACCESS
- PRD §7.9, §9.1, §9.5, §9.6
## Lingkup Boleh
- RBAC regression, security hardening, anonimisasi UI/full flow, staging rehearsal, deployment, backup, restore, smoke test, dan go-live gate.
## Lingkup Dilarang
- Fitur bisnis baru.
- HA/Kubernetes/multi-region/PITR enterprise.
- WAF/SIEM/SOC wajib.
- Menganggap backup upload sebagai bukti restore.
- Mengubah domain lock demi cepat go-live.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W7-T1 RBAC regression</td>
<td>Policy/role negative suite</td>
<td>No bypass</td>
</tr>
<tr>
<td>W7-T2 Anonimisasi UI</td>
<td>Super Admin + step-up</td>
<td>Guard Wave 3 dipakai</td>
</tr>
<tr>
<td>W7-T3 Anonimisasi E2E</td>
<td>Tombstone/file cleanup/audit</td>
<td>Irreversible</td>
</tr>
<tr>
<td>W7-T4 Security hardening</td>
<td>Headers, HTTPS, Redis, logs</td>
<td>Checklist PASS</td>
</tr>
<tr>
<td>W7-T5 Staging rehearsal</td>
<td>Production-like smoke test</td>
<td>Bukan production DB</td>
</tr>
<tr>
<td>W7-T6 Backup/restore</td>
<td>Restore DB temporary</td>
<td>Hard gate</td>
</tr>
<tr>
<td>W7-T7 Go-live review</td>
<td>Decision record</td>
<td>PASS sebelum data nyata</td>
</tr>
</table>
## Prompt Builder — Anonimisasi Penuh
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W7-T2/W7-T3].

Authority: PRD §7.9; DATA_RETENTION_AND_PRIVACY; MODULE_CANDIDATES; MODULE_AUTH; DATABASE_SCHEMA; SECURITY_CHECKLIST.

Wajib:
- hanya Super Admin;
- step-up password+TOTP;
- gunakan guard Wave 3 dan revalidasi dalam transaksi;
- set pii_anonymized_at irreversible;
- kosongkan/scramble PII sesuai policy;
- hapus foto R2;
- kosongkan URL dokumen aplikasi dan lakukan prosedur Drive manual sesuai policy;
- audit CANDIDATE_ANONYMIZED;
- Candidate anonymized tidak bisa diedit/dipulihkan dan tidak muncul Guest.

Tambahkan test eligible, setiap guard gagal, step-up missing, rollback file failure sesuai desain, audit, dan Guest exclusion.

Jangan mengaktifkan soft-delete/restore.
```
## Prompt Builder — Staging, Backup, dan Restore
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W7-T5/W7-T6] sebagai plan dan rehearsal terkontrol.

Staging ringan boleh local production-like atau aplikasi/folder/VPS terpisah. Jangan memakai database production untuk rehearsal.

Wajib:
- backup PostgreSQL harian ke bucket R2 terpisah;
- jangan backup Redis sebagai data bisnis;
- restore dump ke database temporary;
- verifikasi aplikasi dapat login dan membaca data hasil restore;
- verifikasi Redis, dua worker, schedule, R2 photo, dan smoke test;
- sanitasi semua output; jangan minta secret ke chat.

Stop dan laporkan bila restore belum benar-benar berhasil. Production tidak boleh dibuka sebelum gate ini lulus.
```
## Prompt Reviewer — Go-Live Gate
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah kode atau server.

Periksa bukti Wave 7 terhadap SECURITY_CHECKLIST, DATA_RETENTION_AND_PRIVACY, DEPLOYMENT, BACKUP_AND_RECOVERY.

Wajib verifikasi:
- RBAC/step-up negative tests;
- Guest PII test;
- HTTPS/debug/firewall/Redis noeviction/local bind;
- audit immutable;
- anonimisasi eligible dan blocked cases;
- dua queue worker;
- backup artifact;
- restore ke DB temporary yang benar-benar berhasil;
- aplikasi membaca hasil restore;
- tidak ada secret di bukti.

Verdict hanya GO-LIVE PASS atau GO-LIVE BLOCKED dengan blocker minimum.
```
## Rehearsal Ephemeral Test VPS
Sebelum go-live, lakukan minimal satu rehearsal production-like pada VPS ephemeral yang dapat dihancurkan. Rehearsal memakai commit/tag PASS, test secret, dan data sintetis. Uji Nginx, PHP, PostgreSQL, Redis `noeviction` [localhost](http://localhost), dua worker, scheduler, Guest headers/whitelist, R2 test, serta restore ke DB temporary. Setelah bukti tersimpan, instance harus dihancurkan dan tercatat di Build Log. VPS ephemeral **bukan** production VPS.
## Definition of Done
- [ ] RBAC negative suite lulus.
- [ ] UI anonimisasi hanya Super Admin + step-up.
- [ ] Guard Wave 3 direvalidasi dalam transaksi.
- [ ] Tombstone PII irreversible dan audit tercatat.
- [ ] R2 photo cleanup dan proses Drive sesuai policy.
- [ ] Guest exclusion setelah anonimisasi lulus.
- [ ] HTTPS, debug off, firewall, headers, Redis noeviction/local bind lulus.
- [ ] Dua queue worker dan scheduler hidup.
- [ ] Staging rehearsal lulus tanpa memakai DB production.
- [ ] Backup dibuat ke bucket terpisah.
- [ ] **Restore ke database temporary berhasil dan aplikasi membaca hasilnya.**
- [ ] Go-live Reviewer memberi PASS.
## Hard Stop Condition
- Restore test belum berhasil.
- Guest PII test gagal.
- RBAC negative test gagal.
- Redis/PostgreSQL terbuka publik.
- APP_DEBUG production aktif.
- Anonimisasi dapat dijalankan tanpa step-up/guard.
- Secret muncul di repository, prompt, atau bukti.
## Bukti Sukses Minimum
1. Candidate eligible dianonimkan; foto R2 hilang, audit ada, Guest tidak dapat melihatnya.
2. Candidate dengan pending/placement aktif ditolak anonimisasi.
3. Dump dipulihkan ke DB temporary dan aplikasi melakukan login/smoke test sukses.
## Commit dan Snapshot
Commit per hardening capability. Setelah seluruh gate lulus, tag `wave-7-go-live-candidate` dan catat keputusan go-live di Build Log.
---
**Status:** FINAL v1 — panduan operasional Wave 7 dan go-live gate siap digunakan.
