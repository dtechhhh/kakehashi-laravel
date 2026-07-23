---
title: "06 — Wave 3: Candidates"
status: "FINAL v1"
source_notion_title: "06 — Wave 3: Candidates"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 06 — Wave 3: Candidates

> [!NOTE]
> **Wave 3 — Candidates.** Bangun Kandidat dari Draft sampai approval dan revision, dengan availability service yang nanti dipakai Jobs dan Placement.
>
## Apa Artinya untuk Operator
Setelah wave ini, data Kandidat sudah dapat masuk secara aman, ditinjau, disetujui, direvisi tanpa merusak data aktif, dan siap dipakai modul operasional.
## Prasyarat
- [ ] Wave 2 lulus dan tag `wave-2-lookup-complete` tercatat.
- [ ] Lookup, user/role, audit, pending, dan after-commit foundation tersedia.
- [ ] Bucket R2 belum diperlukan sampai task foto dimulai.
## Dokumen Wajib
- MODULE_CANDIDATES
- PRD §5.2, §6.2, §7.1, §7.9, §7.10, Lampiran A
- STATUS_STATE_MACHINE §5
- BUSINESS_RULES Candidate/PII/NIK/DUP
- DATABASE_SCHEMA Candidate
- API_CONTRACTS Candidates
- DATA_RETENTION_AND_PRIVACY
- SECURITY_CHECKLIST
## Lingkup Boleh
- Candidate dan child collections sesuai schema final.
- Draft, submit, Nomor Induk, similarity warning, approval/reject, revision, availability public service.
- Foto R2 privat; dokumen peserta URL Google Drive privat.
- Audit `IDENTITY_DOC_VIEWED`, optimistic lock, dan skeleton guard anonimisasi.
## Lingkup Dilarang
- Soft-delete/restore Kandidat.
- Tabel generik `candidate_participation`.
- Upload dokumen peserta ke R2/aplikasi.
- Approver mengedit Candidate.
- UI anonimisasi penuh; itu Wave 7.
- Akses langsung modul lain ke model/table Candidate.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W3-T1 Schema core</td>
<td>Candidate + child tables final</td>
<td>Migration PostgreSQL fresh</td>
</tr>
<tr>
<td>W3-T2 Draft/form core</td>
<td>Draft tanpa NIK/pending</td>
<td>Draft tidak operasional</td>
</tr>
<tr>
<td>W3-T3 NIK + similarity</td>
<td>Submit atomik dan warning</td>
<td>NIK saat submit saja</td>
</tr>
<tr>
<td>W3-T4 Approval</td>
<td>Pending foundation dipakai</td>
<td>Maker tidak self-approve</td>
</tr>
<tr>
<td>W3-T5 Revision</td>
<td>Satu revision aktif dan merge atomik</td>
<td>Main tetap aktif sampai approve</td>
</tr>
<tr>
<td>W3-T6 Availability service</td>
<td>Public service Candidates</td>
<td>Tidak ada update lintas-modul langsung</td>
</tr>
<tr>
<td>W3-T7 File split</td>
<td>Foto R2, dokumen Drive</td>
<td>Audit link dokumen</td>
</tr>
<tr>
<td>W3-T8 Guard anonimisasi</td>
<td>Eligibility skeleton transaksional</td>
<td>Belum UI penuh</td>
</tr>
<tr>
<td>W3-T9 Review</td>
<td>Concurrency/security review</td>
<td>PASS sebelum Jobs</td>
</tr>
</table>
## Prompt Builder — Submit, NIK, dan Approval
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W3-T3 atau W3-T4].

Authority: PRD v0.3.14; MODULE_CANDIDATES; BUSINESS_RULES NIK/DUP/APV; STATUS_STATE_MACHINE Candidate; DATABASE_SCHEMA; API_CONTRACTS; SECURITY_CHECKLIST.

Wajib:
- Draft tidak memiliki NIK atau pending;
- submit menjalankan validasi, similarity soft warning, assign NIK JST, status Menunggu Tinjauan, pending, dan audit dalam satu transaksi;
- similarity memakai similarity() >= 0.4 eksplisit dan tidak memblokir;
- Approver hanya approve/reject, tidak edit;
- Maker tidak self-approve;
- pending Wave 1 dipakai;
- konflik version menghasilkan 409.

Tambahkan test draft, submit, duplicate warning, self-approve, reject note, NIK uniqueness/JST, dan rollback.
```
## Prompt Builder — Revision dan Guard Anonimisasi
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W3-T5 atau W3-T8].

Wajib revision:
- main Candidate Disetujui tetap aktif;
- revision mulai Draft dan NIK null;
- maksimum satu revision Draft/menunggu aktif;
- approve merge field mutable dan child collections secara atomik;
- NIK, availability, dan history operasional main tidak berubah.

Wajib skeleton guard anonimisasi:
- blok bila availability bukan Tersedia;
- blok bila participation aktif, placement Bekerja, pending terbuka, atau revision aktif;
- revalidasi seluruh guard dalam transaksi;
- belum membuat UI/full delete flow Wave 7.

Tambahkan test untuk setiap guard dan rollback.
```
## Prompt Reviewer — Candidates
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah kode.

Tinjau Candidate task terhadap authority. Periksa Draft/NIK/pending atomik, self-approval, revision merge, availability public service, PostgreSQL partial unique, optimistic lock, foto R2 vs dokumen Drive, audit link Drive, dan skeleton guard anonimisasi.

Tolak bila ada soft-delete route, cross-module model access, upload dokumen ke R2, atau UI anonimisasi penuh sebelum Wave 7.

Berikan severity dan verdict.
```
## Definition of Done
- [ ] Draft tidak punya NIK/pending dan belum operasional.
- [ ] Submit Candidate atomik; NIK unique dan tahun JST.
- [ ] Similarity warning tidak block.
- [ ] Maker tidak dapat approve sendiri.
- [ ] Revision aktif maksimal satu dan merge atomik.
- [ ] Availability hanya lewat public service.
- [ ] Foto R2 privat; dokumen URL Drive privat.
- [ ] `IDENTITY_DOC_VIEWED` tercatat saat link diungkap.
- [ ] Version conflict menghasilkan 409.
- [ ] Skeleton guard anonimisasi dan test-nya tersedia.
- [ ] Reviewer PASS; snapshot `wave-3-candidates-complete` tercatat.
## Stop Condition
- NIK dibuat saat Draft.
- Revision menimpa main sebelum approval.
- Availability menjadi input form biasa.
- Dokumen peserta di-upload ke R2.
- Guard anonimisasi dapat dilewati karena check di luar transaksi.
## Bukti Sukses Minimum
1. Draft → submit → reject → perbaiki → approve.
2. Revision approved mengganti child collections tanpa mengubah NIK.
3. Kandidat dengan pending/placement aktif ditolak oleh guard anonimisasi.
## Commit dan Snapshot
Commit per kemampuan. Setelah review akhir lulus, tag `wave-3-candidates-complete` dan catat di Build Log.
---
**Status:** FINAL v1 — panduan operasional Wave 3 siap digunakan.
