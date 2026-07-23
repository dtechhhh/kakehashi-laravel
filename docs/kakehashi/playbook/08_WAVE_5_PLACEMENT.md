---
title: "08 — Wave 5: Placement"
status: "FINAL v1"
source_notion_title: "08 — Wave 5: Placement"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 08 — Wave 5: Placement

> [!NOTE]
> **Wave 5 — Placement.** Bangun transfer ownership Wawancara→Penempatan secara atomik, Force-Majeur, lifecycle kontrak, dan archive otomatis.
>
## Apa Artinya untuk Operator
Kandidat yang siap dikirim dapat ditempatkan tanpa pernah “lepas” menjadi Tersedia di tengah proses. Jika salah satu kandidat batch bermasalah, seluruh batch aman dibatalkan.
## Prasyarat
- [ ] Wave 4 lulus; source participation `Siap Dikirim` tersedia.
- [ ] Candidate availability dan InterviewPlacementTransferService tersedia.
- [ ] Pending, audit, step-up, lookup, dan after-commit foundation tersedia.
## Dokumen Wajib
- MODULE_PLACEMENT
- STATUS_STATE_MACHINE §2, §4, §7
- BUSINESS_RULES availability/Force-Majeur/concurrency
- DATABASE_SCHEMA Placement
- API_CONTRACTS
- MODULE_AUTH
- SECURITY_CHECKLIST
## Lingkup Boleh
- Kontainer Placement dan kode P.
- Batch normal max 50 dengan pending payload.
- Approval transfer atomik.
- Force-Majeur kategori + alasan.
- Selesai Kontrak, resign, expel, formula akhir kontrak, archive otomatis.
## Lingkup Dilarang
- Filter normal `Siap Dikirim + Tersedia`.
- Window availability Tersedia pada transfer normal.
- `markInUse()` untuk transfer normal.
- Partial success batch.
- Step-up Force-Majeur.
- Archive manual.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W5-T1 Container lifecycle</td>
<td>Draft/approve/cancel escape</td>
<td>Perusahaan immutable</td>
</tr>
<tr>
<td>W5-T2 Participant schema</td>
<td>FM CHECK dan one Bekerja unique</td>
<td>Migration test</td>
</tr>
<tr>
<td>W5-T3 Batch submit</td>
<td>Payload snapshot max 50</td>
<td>Belum ubah source</td>
</tr>
<tr>
<td>W5-T4 Batch approve</td>
<td>Transfer atomik</td>
<td>Tidak ada window Tersedia</td>
</tr>
<tr>
<td>W5-T5 Force-Majeur</td>
<td>Request/approve/reject</td>
<td>Tanpa step-up, FM_REJECTED</td>
</tr>
<tr>
<td>W5-T6 Status contract</td>
<td>Selesai/resign/expel</td>
<td>Formula inklusif dan step-up expel</td>
</tr>
<tr>
<td>W5-T7 Archive</td>
<td>Sinkron + sweeper safety</td>
<td>Setelah Bekerja terakhir terminal</td>
</tr>
<tr>
<td>W5-T8 Review</td>
<td>Atomicity/concurrency review</td>
<td>PASS sebelum Guest</td>
</tr>
</table>
## Prompt Builder — Batch Normal Atomik
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan W5-T3/W5-T4 batch normal.

Authority: PRD §6.4/§7.1/§7.10; MODULE_PLACEMENT; STATUS_STATE_MACHINE; BUSINESS_RULES availability; API_CONTRACTS; DATABASE_SCHEMA.

Wajib:
- eligible hanya source Siap Dikirim + availability Sedang Dipakai + ownership cocok + tanpa placement Bekerja;
- maksimum 50;
- submit membuat pending PLACEMENT_BATCH dengan payload snapshot dan belum mengubah source;
- approve merevalidasi pending/source/candidate dalam satu transaksi;
- insert placement Bekerja, source→Terkirim, availability tetap Sedang Dipakai;
- gunakan assertInUse, bukan markInUse, pada transfer normal;
- gagal satu kandidat rollback seluruh batch;
- audit BATCH_SENT dan notif in-app sesuai after-commit foundation.

Tambahkan test valid batch, invalid satu row rollback total, double approve, stale source, dan availability tidak berubah ke Tersedia.
```
## Prompt Builder — Force-Majeur dan Archive
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W5-T5 atau W5-T7].

Force-Majeur wajib: source null, kandidat Tersedia+Disetujui, kategori+alasan wajib, pending, approval rutin tanpa step-up, markInUse saat approve, audit FORCE_MAJEUR_ADDED atau FM_REJECTED.

Archive wajib: hanya Aktif→Arsip otomatis ketika Bekerja terakhir terminal, dicek setelah seluruh batch, sinkron in-transaction dengan sweeper harian sebagai safety, idempoten.

Tambahkan test guard, rollback, no step-up FM, dan archive tidak prematur.
```
## Prompt Reviewer — Placement
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah kode.

Tinjau transfer normal, pending/self-approval, lock/revalidation, payload snapshot, full rollback, availability tetap Sedang Dipakai, Force-Majeur tanpa step-up, FM_REJECTED, formula akhir kontrak inklusif, expel step-up, dan archive otomatis/idempoten.

Tolak jika ada Siap Dikirim+Tersedia, markInUse normal, partial batch, atau archive manual.

Berikan severity dan verdict.
```
## Definition of Done
- [ ] Kode P saat submit pertama; perusahaan immutable.
- [ ] One Bekerja partial unique dan FM CHECK lulus.
- [ ] Batch max 50 dan payload snapshot.
- [ ] Transfer normal atomik; source Terkirim, placement Bekerja, availability tetap Sedang Dipakai.
- [ ] Satu kandidat invalid me-rollback seluruh batch.
- [ ] Maker tidak self-approve dan double approve 409.
- [ ] Force-Majeur kategori+alasan, no step-up, audit kanonik.
- [ ] Akhir kontrak = mulai + durasi bulan − 1 hari.
- [ ] Expel butuh step-up; resign approval rutin.
- [ ] Archive otomatis setelah kandidat Bekerja terakhir terminal.
- [ ] Reviewer PASS; snapshot `wave-5-placement-complete` tercatat.
## Stop Condition
- Batch mengubah sebagian data saat ada error.
- Transfer normal memanggil markInUse atau membuat availability Tersedia.
- Force-Majeur meminta step-up.
- Archive terjadi manual atau sebelum kandidat aktif terakhir terminal.
## Bukti Sukses Minimum
1. Batch dua kandidat dengan satu invalid: tidak ada source/placement berubah.
2. Batch valid: source Terkirim, placement Bekerja, availability tetap Sedang Dipakai.
3. Kandidat Bekerja terakhir terminal: container Arsip otomatis.
---
**Status:** FINAL v1 — panduan operasional Wave 5 siap digunakan.
