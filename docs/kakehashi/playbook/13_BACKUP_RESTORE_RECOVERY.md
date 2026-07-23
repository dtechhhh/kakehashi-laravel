---
title: "13 — Backup, Restore & Recovery"
status: "FINAL v1"
source_notion_title: "13 — Backup, Restore & Recovery"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 13 — Backup, Restore & Recovery

> [!NOTE]
> **Backup, Restore & Recovery.** Backup baru dianggap berguna setelah berhasil dipulihkan ke database temporary dan aplikasi dapat membacanya.
>
## Apa Artinya untuk Operator
Database adalah sumber data bisnis. Jika server hilang atau data rusak, Anda perlu dump yang sudah terbukti dapat dipulihkan—bukan hanya file yang terlihat ada di bucket.
## Aset yang Dilindungi
<table fit-page-width="true" header-row="true">
<tr>
<td>Aset</td>
<td>Prioritas</td>
<td>Strategi</td>
</tr>
<tr>
<td>PostgreSQL</td>
<td>Kritis</td>
<td>pg_dump harian → R2 bucket backup terpisah</td>
</tr>
<tr>
<td>Foto R2</td>
<td>Tinggi</td>
<td>Bucket privat + versioning/lifecycle</td>
</tr>
<tr>
<td>Kode aplikasi</td>
<td>Tinggi</td>
<td>GitHub dan tag release</td>
</tr>
<tr>
<td>.env/secret</td>
<td>Kritis</td>
<td>Password manager, bukan Git</td>
</tr>
<tr>
<td>Dokumen Drive</td>
<td>Operasional</td>
<td>Permission/prosedur manual Drive</td>
</tr>
<tr>
<td>Redis</td>
<td>Bukan data bisnis</td>
<td>Tidak dibackup; rebuild/relogin setelah restore</td>
</tr>
</table>
## Rehearsal di VPS Ephemeral
VPS test harian boleh dipakai untuk restore rehearsal menggunakan **database test temporary** dan dump test/sintetis. Ia tidak menggantikan backup production dan tidak boleh memegang satu-satunya salinan data/kode/secret. Sebelum destroy instance, simpan hanya bukti tersanitasi; data test dapat ikut hilang bersama VPS.
## Kebijakan MVP
- Backup DB harian pukul 02:00 JST.
- Retensi harian 14 versi; snapshot mingguan 12 minggu.
- RPO ≤24 jam; RTO target 3–6 jam.
- Backup memakai bucket R2 terpisah dari foto.
- Restore test sebelum go-live dan bulanan setelah go-live.
## Urutan Rehearsal Restore
1. Pilih dump terbaru yang diketahui sukses upload.
2. Buat database **temporary** baru—jangan overwrite production.
3. Restore dump ke database temporary.
4. Jalankan pemeriksaan schema dan jumlah data yang masuk akal.
5. Arahkan aplikasi/rehearsal environment ke database temporary secara aman.
6. Uji login Super Admin + 2FA, lookup, Kandidat, foto R2, Redis, worker, dan schedule.
7. Catat hasil disanitasi di Build Log.
8. Jika gagal, perbaiki backup/restore dan ulangi. Jangan go-live.
## Prompt Builder — Backup dan Restore Rehearsal
```plain text
Anda adalah Builder/DevOps Agent Kakehashi. Buat atau jalankan rehearsal backup/restore terkontrol. Jangan overwrite database production dan jangan meminta secret di chat.

Authority: BACKUP_AND_RECOVERY; DEPLOYMENT; SECURITY_CHECKLIST; DATA_RETENTION_AND_PRIVACY.

Wajib:
- pg_dump harian ke bucket R2 backup terpisah;
- cek file dump tidak kosong;
- Redis tidak dianggap data bisnis;
- restore ke database temporary;
- aplikasi/rehearsal dapat membaca hasil restore;
- verifikasi login 2FA, jumlah data, lookup, R2 photo, Redis, 2 worker, dan schedule;
- semua output disanitasi;
- catat command, waktu, dump yang dipakai, hasil, dan rollback/cleanup.

Berikan command Mode A dan Mode B satu per satu beserta output dan stop condition. Jika restore gagal, STOP dan laporkan—jangan lanjut go-live.
```
## Prompt Reviewer — Restore Gate
```plain text
Anda adalah Reviewer Agent terpisah. Jangan menjalankan perubahan.

Tinjau bukti backup/restore Kakehashi. Pastikan:
- bucket backup terpisah;
- dump tidak kosong;
- restore dilakukan ke database temporary, bukan production;
- aplikasi berhasil membaca hasil restore;
- Redis tidak diperlakukan sebagai source of truth;
- tidak ada secret di bukti;
- hasil login/lookup/foto/worker/schedule terdokumentasi;
- retensi dan cron sesuai policy.

Verdict hanya RESTORE PASS atau RESTORE BLOCKED. Jika blocked, sebutkan blocker minimum dan jangan izinkan go-live.
```
## Recovery Skenario
### Server hilang
1. Buat VPS baru sesuai DEPLOYMENT.
2. Install stack dan clone tag aplikasi stabil.
3. Pulihkan `.env` dari password manager.
4. Restore dump database.
5. Nyalakan Redis, worker, cron, Nginx/SSL.
6. Jalankan checklist pasca-restore.
### Data rusak/terhapus
1. Hentikan write berbahaya bila perlu.
2. Restore terlebih dahulu ke DB temporary.
3. Bandingkan dan ambil data yang diperlukan bila kerusakan parsial.
4. Overwrite production hanya bila owner memutuskan kerusakan luas.
### Backup korup
1. Coba dump harian sebelumnya.
2. Coba snapshot mingguan.
3. Perbaiki cron/kredensial/bucket.
4. Ulangi restore test.
## Checklist Pasca-Restore
- [ ] Login Super Admin + 2FA.
- [ ] Jumlah Kandidat masuk akal.
- [ ] Lookup bilingual berjalan.
- [ ] Foto R2 tampil.
- [ ] Redis ping sehat.
- [ ] Dua worker hidup.
- [ ] Scheduler berjalan.
- [ ] Tidak ada error 500 awal.
- [ ] Guest link diuji/revoke bila perlu.
## Hard Stop Condition
> [!NOTE]
> **Production dilarang go-live jika restore ke database temporary belum berhasil dan aplikasi belum terbukti membaca hasil restore.**
>
## Catatan Privasi
Backup historis dapat memuat PII yang kemudian dianonimkan di production. Pada MVP, backup kedaluwarsa sesuai retention; setelah restore dari dump lama, periksa kembali status anonimisasi sebelum aplikasi dibuka ke pengguna.
## Bukti Minimum
1. File dump non-kosong di bucket backup terpisah.
2. Restore DB temporary sukses.
3. Login 2FA dan smoke test terhadap hasil restore sukses.
4. Reviewer memberi `RESTORE PASS`.
5. Hasil dicatat di Notion page reference.
---
**Status:** FINAL v1 — panduan backup, restore, dan recovery siap digunakan.
