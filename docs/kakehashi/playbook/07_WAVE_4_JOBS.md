---
title: "07 — Wave 4: Jobs/Wawancara"
status: "FINAL v1"
source_notion_title: "07 — Wave 4: Jobs/Wawancara"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 07 — Wave 4: Jobs/Wawancara

> [!NOTE]
> **Wave 4 — Jobs/Wawancara.** Bangun kontainer Wawancara dan participation tanpa memungkinkan Kandidat masuk dua proses aktif.
>
## Apa Artinya untuk Operator
Asisten Manajer dapat membuat kontainer, menarik Kandidat yang tepat, memperbarui proses Wawancara, serta meminta expel/close dengan kontrol approval yang benar.
## Prasyarat
- [ ] Wave 3 lulus dan `CandidateAvailabilityService` tersedia.
- [ ] Pending, audit, step-up, dan lookup tersedia.
## Dokumen Wajib
- MODULE_JOBS
- STATUS_STATE_MACHINE §1 dan §3
- BUSINESS_RULES availability/approval/concurrency
- DATABASE_SCHEMA Wawancara
- API_CONTRACTS Candidate Availability dan Interview Placement Transfer
- MODULE_AUTH
- SECURITY_CHECKLIST
## Lingkup Boleh
- Kontainer Wawancara Draft→Menunggu Approval→Aktif.
- Kode W saat submit pertama.
- Participation, partial unique aktif, bulk pull dengan row lock.
- Status maju ketat, terminal alami, expel, close, dan request link Guest internal.
## Lingkup Dilarang
- Aksi manual `Terkirim`.
- Approval Candidate di module Jobs.
- Cancel kontainer Aktif.
- Direct model/table access ke Candidates.
- Membuat Guest public screen; itu Wave 6.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W4-T1 Container lifecycle</td>
<td>Draft/submit/approve/reject/cancel</td>
<td>Maker tidak self-approve</td>
</tr>
<tr>
<td>W4-T2 Participation schema</td>
<td>Partial unique aktif</td>
<td>Satu kandidat satu proses aktif</td>
</tr>
<tr>
<td>W4-T3 Bulk pull</td>
<td>FOR UPDATE + markInUse</td>
<td>Dua pull tidak lolos bersamaan</td>
</tr>
<tr>
<td>W4-T4 Status natural</td>
<td>Maju ketat hingga Siap Dikirim</td>
<td>Terkirim bukan aksi manual</td>
</tr>
<tr>
<td>W4-T5 Expel</td>
<td>Pending + step-up</td>
<td>Dua alasan dan markAvailable</td>
</tr>
<tr>
<td>W4-T6 Close</td>
<td>Pending + step-up + freeze</td>
<td>Availability kembali tersedia</td>
</tr>
<tr>
<td>W4-T7 Guest link request</td>
<td>Request internal</td>
<td>Token hanya setelah approval</td>
</tr>
<tr>
<td>W4-T8 Review</td>
<td>Concurrency/state review</td>
<td>PASS sebelum Placement</td>
</tr>
</table>
## Prompt Builder — Bulk Pull
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan W4-T3 bulk pull.

Authority: MODULE_JOBS; STATUS_STATE_MACHINE status_wawancara; BUSINESS_RULES availability/concurrency; DATABASE_SCHEMA participation; API_CONTRACTS CandidateAvailabilityService.

Wajib:
- hanya Kandidat Disetujui + Tersedia;
- kontainer Aktif;
- SELECT FOR UPDATE saat validasi;
- insert participation dan markInUse dalam transaksi;
- partial unique satu participation aktif per candidate;
- gagal satu kandidat menghasilkan hasil sesuai kontrak tanpa double-pull;
- audit CANDIDATE_PULLED.

Tambahkan test dua pull konkuren, candidate tidak eligible, kontainer nonaktif, dan unique constraint.
```
## Prompt Builder — Expel dan Close
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W4-T5 atau W4-T6].

Wajib:
- Maker request → pending; status belum berubah;
- Manajer Job approve/reject dengan pending foundation;
- Maker tidak self-approve;
- expel dan close approval memakai step-up password+TOTP;
- alasan maker dan catatan checker sesuai kontrak;
- expel markAvailable;
- close freeze non-terminal participation lalu markAvailable kandidat aktif;
- audit kanonik tercatat.

Tambahkan test tanpa step-up, self-approve, double approval, dan close dengan participation aktif.
```
## Prompt Reviewer — Jobs
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah kode.

Periksa lifecycle kontainer, kode W, pending/self-approval, partial unique participation, FOR UPDATE, availability service, status maju ketat, larangan Terkirim manual, expel/close step-up, freeze, audit, dan token Guest yang belum boleh lahir sebelum approval.

Berikan severity dan verdict.
```
## Definition of Done
- [ ] Kode W dibuat hanya pada submit pertama.
- [ ] Container approval memakai pending dan maker tidak self-approve.
- [ ] Partial unique participation aktif lulus di PostgreSQL.
- [ ] Bulk pull memakai row lock dan public service.
- [ ] Status maju ketat; `Terkirim` hanya efek batch Placement.
- [ ] Expel/close memerlukan pending, alasan, step-up, audit.
- [ ] Close membekukan partisipasi dan mengembalikan availability.
- [ ] Link ditolak tidak menghasilkan token.
- [ ] Reviewer PASS; snapshot `wave-4-jobs-complete` tercatat.
## Stop Condition
- Kandidat dapat masuk dua participation aktif.
- Jobs mengubah Candidate langsung tanpa service.
- Terkirim dapat dipilih manual.
- Expel/close berjalan tanpa step-up.
## Bukti Sukses Minimum
1. Dua pull bersamaan: hanya satu berhasil.
2. Close kontainer aktif membekukan participation dan mengembalikan availability.
3. Approve link Guest menghasilkan token; reject tidak.
---
**Status:** FINAL v1 — panduan operasional Wave 4 siap digunakan.
