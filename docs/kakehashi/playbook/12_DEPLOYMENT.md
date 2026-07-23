---
title: "12 — Deployment"
status: "FINAL v1 + VPS Addendum"
source_notion_title: "12 — Deployment"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 12 — Deployment

> [!NOTE]
> **Deployment untuk operator non-IT.** Dari staging ringan sampai production single VPS 4 vCPU/8 GB, tanpa memaksa multi-server atau Docker-first.
>
## Apa Artinya untuk Operator
Deployment berarti memasang aplikasi yang sudah lulus wave build pada server nyata. Ini bukan tempat memperbaiki fitur. Jika test aplikasi belum lulus, kembali ke wave terkait—jangan “memperbaiki di server”.
## Model Tiga Lingkungan
<table fit-page-width="true" header-row="true">
<tr>
<td>Lingkungan</td>
<td>Tujuan</td>
<td>Data yang diizinkan</td>
<td>Larangan utama</td>
</tr>
<tr>
<td>Local Dev</td>
<td>Coding, unit/feature test, iterasi harian</td>
<td>Data dummy/sintetis</td>
<td>Secret dan PII production</td>
</tr>
<tr>
<td>Ephemeral Test VPS</td>
<td>Integration, smoke, deploy/rehearsal production-like</td>
<td>Data sintetis + test secret</td>
<td>Data production, source of truth, disk sebagai backup</td>
</tr>
<tr>
<td>Production VPS</td>
<td>Data nyata dan go-live</td>
<td>Data/secret production resmi</td>
<td>Eksperimen, branch belum PASS, debug aktif</td>
</tr>
</table>
**Aturan:** Local + GitHub tetap source of truth. VPS test hanya deploy commit/tag PASS dan dapat diganti kapan saja. Production tetap single VPS stabil, bukan instance harian.
## Staging Ringan
Staging adalah latihan sebelum production. Pilih salah satu:
1. **Local production-like:** konfigurasi lokal yang meniru production sebanyak mungkin.
2. **Aplikasi/folder terpisah:** pada server yang sama hanya bila aman dan resource cukup.
3. **VPS terpisah:** jika tersedia kemudian.
Tidak perlu multi-server, cluster, atau HA untuk MVP. Jangan gunakan database production untuk rehearsal.
## Persiapan Bertahap
### Sebelum Wave 0
- [ ] GitHub, Codex, password manager, dan mesin development.
### Sebelum fitur foto
- [ ] R2 bucket privat dan credential tersimpan di password manager.
### Sebelum staging/go-live
- [ ] VPS 4 vCPU/8 GB, Ubuntu 24.04.
- [ ] Domain dan DNS.
- [ ] Akses SSH key.
- [ ] Email production.
- [ ] R2 backup bucket terpisah.
- [ ] Kebijakan permission Google Drive.
- [ ] Aplikasi TOTP Super Admin.
## Urutan Deployment
<table fit-page-width="true" header-row="true">
<tr>
<td>Tahap</td>
<td>Hasil</td>
<td>Stop condition</td>
</tr>
<tr>
<td>D1 Rehearsal plan</td>
<td>Rencana staging disetujui</td>
<td>Tidak ada command tanpa review</td>
</tr>
<tr>
<td>D2 Server hardening</td>
<td>SSH/UFW/user non-root</td>
<td>DB/Redis publik</td>
</tr>
<tr>
<td>D3 Stack install</td>
<td>Nginx, PHP, PG, Redis, Supervisor</td>
<td>Versi tidak sesuai baseline</td>
</tr>
<tr>
<td>D4 Config</td>
<td>.env dari password manager</td>
<td>Secret di repo/chat</td>
</tr>
<tr>
<td>D5 Deploy first</td>
<td>Clone, install, migrate, build, cache</td>
<td>Migration/test gagal</td>
</tr>
<tr>
<td>D6 Services</td>
<td>SSL, 2 worker, schedule, backup cron</td>
<td>Worker/cron tidak hidup</td>
</tr>
<tr>
<td>D7 Smoke test</td>
<td>Login, R2, queue, core flows</td>
<td>Security/restore gate gagal</td>
</tr>
</table>
## SOP VPS Harian Octa — Sewa sampai Destroy
> [!NOTE]
> VPS ini **ephemeral**: dapat berganti setiap sesi dan boleh hilang kapan saja. Jangan menyimpan satu-satunya copy kode, data, script, atau secret di VPS.
>
### 1. Gate sebelum sewa
- [ ] Task local sudah lulus test dan sudah mendapat verdict Reviewer `PASS` atau `PASS WITH NON-BLOCKING NOTES`.
- [ ] Commit/tag yang akan diuji sudah ada di GitHub.
- [ ] Tujuan sesi jelas, misalnya “uji deploy README Wave 5”.
- [ ] Test secret tersedia di password manager.
- [ ] Data sintetis/seeder test tersedia.
- [ ] Operator sudah membuat baris sesi pada Build Log.
Jangan sewa VPS hanya untuk memulai Wave 0 atau menjalankan unit test harian.
### 2. Kriteria pilih node Octa
<table fit-page-width="true" header-row="true">
<tr>
<td>Komponen</td>
<td>Rekomendasi</td>
<td>Catatan</td>
</tr>
<tr>
<td>Profil utama</td>
<td>4 vCPU / 8 GB RAM</td>
<td>Paling dekat dengan production untuk rehearsal Wave 5–7</td>
</tr>
<tr>
<td>Profil minimum</td>
<td>2 vCPU / 4 GB RAM</td>
<td>Hanya functional smoke test; bukan bukti kapasitas production</td>
</tr>
<tr>
<td>Disk</td>
<td>≥100 GB bila tersedia</td>
<td>Headroom untuk PostgreSQL, logs, dan restore test; tidak mutlak untuk data sintetis</td>
</tr>
<tr>
<td>OS</td>
<td>Ubuntu 24.04 LTS fresh image</td>
<td>Wajib agar selaras runbook</td>
</tr>
<tr>
<td>Region</td>
<td>Tokyo, lalu Singapore, lalu Asia lain</td>
<td>Catat region; jangan jadikan VPS acak sebagai benchmark performa</td>
</tr>
<tr>
<td>Wajib operasional</td>
<td>SSH + public IP + tombol destroy</td>
<td>Pastikan status instance dan billing terlihat di dashboard</td>
</tr>
</table>
### 3. Catat sebelum bootstrap
Catat di Build Log: Session ID, provider `Octa Cube`, instance label, region, IP/hostname, vCPU/RAM/disk, OS, tujuan sesi, commit/tag, data mode, target waktu destroy. **Jangan catat password, private key, APP_KEY, token, atau isi ****`.env`****.**
### 4. Bootstrap minimum
1. Login SSH dengan key.
2. Update OS.
3. Terapkan firewall dan buat user deploy non-root.
4. Install Nginx, PHP 8.4, PostgreSQL 18 + `pg_trgm`, Redis, Supervisor, Composer, dan Node build-only.
5. Konfigurasikan PostgreSQL dan Redis hanya di [localhost](http://localhost).
6. Set Redis `maxmemory-policy noeviction`.
7. Verifikasi versi sebelum deploy aplikasi.
Builder harus membuat rencana command Mode A/B sebelum command dijalankan. Jangan gunakan VPS test sebagai tempat menulis code permanen.
### 5. Deploy commit/tag yang sudah PASS
1. Clone repository GitHub.
2. Checkout **commit/tag PASS yang tercatat**—bukan branch atau folder laptop acak.
3. Isi `.env` test dari password manager.
4. Jalankan install dependency, build asset, migration, dan seeder synthetic data.
5. Cache config/route/view sesuai kebutuhan.
6. Nyalakan Supervisor dan scheduler.
Jika ada bug, perbaiki di local → test → review → commit → deploy ulang. Jangan memperbaiki source langsung di VPS.
### 6. Smoke test production-like
- [ ] Nginx/PHP merespons.
- [ ] PostgreSQL + `pg_trgm` aktif.
- [ ] Redis `PING`, bind [localhost](http://localhost), `noeviction`.
- [ ] Dua worker hidup saat rehearsal 4C/8G.
- [ ] Scheduler berjalan.
- [ ] Login/TOTP test berhasil.
- [ ] R2 **test bucket** dapat memakai foto dummy.
- [ ] Guest memakai synthetic Candidate dan tidak bocor PII.
- [ ] Tidak ada stack trace atau secret dalam log.
- [ ] Hasil dicatat dan direview.
### 7. Teardown sebelum destroy
1. Simpan hanya log/screenshot yang sudah disanitasi.
2. Pastikan tidak ada code/patch yang hanya hidup di VPS.
3. Revoke deploy credential sementara atau rotasi test credential bila perlu.
4. Hapus test DNS record bila dipakai.
5. Pastikan tidak ada backup penting hanya di disk VPS.
6. Destroy instance melalui dashboard Octa.
7. Verifikasi instance terminated dan billing berhenti.
8. Isi `Destroyed?=Ya` serta waktu verifikasi di Build Log.
### 8. Recreate sesi berikutnya
Ulangi dari GitHub commit/tag PASS + password manager + synthetic seed. Targetnya: instance baru dapat siap diuji kembali dalam **≤10 langkah tingkat tinggi** tanpa bergantung pada VPS lama.
## DNS dan SSL untuk VPS Berganti-ganti
### Opsi default — IP + akses terbatas
Gunakan untuk sesi harian: akses lewat IP, firewall dibatasi bila bisa, dan self-signed HTTPS bila perlu. Ini cukup untuk bootstrap/internal smoke test, tetapi bukan bukti SSL production.
### Opsi milestone — subdomain test
Gunakan subdomain test dengan DNS diarahkan ke IP VPS saat menguji Guest, HTTPS, secure cookie, redirect, dan Certbot pada Wave 6–7. Hapus atau ubah record setelah sesi. Jangan menerbitkan certificate berulang tanpa mempertimbangkan rate limit.
### Opsi tertunda — tunnel
Pertimbangkan hanya bila masalah DNS berulang sudah terbukti. Bukan default MVP dan bukan alasan menambah platform kompleks.
## Mode A — Codex Menjalankan Command
Codex wajib menjelaskan command, folder, dampak, rollback, dan output yang diharapkan. Operator menyetujui command yang mengubah server. Jangan memberi Codex secret melalui chat.
## Mode B — Operator Copy-Paste
Codex wajib memberi satu command per langkah, lokasi command, output berhasil, dan stop condition. Operator tidak melanjutkan saat output berbeda.
## Checklist Server Aman
- [ ] Ubuntu 24.04 pada VPS 4C/8G.
- [ ] SSH key; password login dinonaktifkan bila siap.
- [ ] UFW hanya 22/80/443.
- [ ] PostgreSQL tidak expose publik.
- [ ] Redis bind [localhost](http://localhost) dan `maxmemory-policy noeviction`.
- [ ] HTTPS dan redirect HTTP→HTTPS.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `.env` permission ketat dan tidak di Git.
- [ ] Dua worker Supervisor hidup.
- [ ] Cron scheduler dan backup hidup.
## Prompt Builder — Deployment Plan Only
```plain text
Anda adalah Builder/DevOps Agent Kakehashi. Buat rencana deployment atau staging saja. Jangan menjalankan command server sebelum operator menyetujui setiap tahap.

Authority: DEPLOYMENT; BACKUP_AND_RECOVERY; SECURITY_CHECKLIST; PRD §9.5/§9.6; ARCHITECTURE.

Baseline: single VPS Ubuntu 24.04, 4 vCPU/8 GB, PostgreSQL 18, PHP 8.4, Laravel 13, Redis localhost noeviction, 2 Supervisor workers, Nginx, HTTPS.

Berikan per tahap:
1. tujuan;
2. command Mode A dan Mode B;
3. lokasi command;
4. output yang diharapkan;
5. rollback/stop condition;
6. bukti yang harus disimpan tanpa secret.

Jangan meminta atau mencetak secret. Jangan mengusulkan HA, Kubernetes, Docker-first, atau multi-server wajib.
```
## Prompt Reviewer — Security Deployment
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah server.

Tinjau rencana/hasil deployment terhadap DEPLOYMENT, SECURITY_CHECKLIST, BACKUP_AND_RECOVERY.

Periksa Ubuntu/version, firewall, SSH, Postgres/Redis public exposure, Redis noeviction, APP_DEBUG, .env permission, HTTPS, two workers, scheduler, backup cron, R2 private, dan tidak ada secret di bukti.

Berikan severity dan verdict: STAGING PASS/STAGING BLOCKED atau GO-LIVE PASS/GO-LIVE BLOCKED.
```
## Deploy Pertama — Checklist Operator
1. Buat staging ringan dan lakukan rehearsal.
2. Siapkan server dan hardening dasar.
3. Clone repository branch/tag stabil.
4. Isi `.env` dari password manager—jangan dari chat.
5. Install dependency production dan build asset.
6. Jalankan migration/seeder yang sudah lulus test.
7. Cache config/route/view.
8. Pasang Nginx dan SSL.
9. Nyalakan 2 queue worker dan schedule cron.
10. Jalankan smoke test dan restore gate sebelum production dibuka.
## Deploy Update
- Backup sebelum migration berisiko.
- Masuk maintenance mode bila diperlukan.
- Pull tag/commit yang sudah direview.
- Install dependency, build asset bila berubah, migrate, cache ulang, restart queue.
- Smoke test lalu buka aplikasi.
- Jangan pull branch yang belum mendapat Reviewer PASS.
## Stop Condition
- Redis/PostgreSQL terbuka publik.
- `APP_DEBUG=true` di production.
- Secret muncul di repo/prompt/log.
- Worker/cron gagal hidup.
- Smoke test gagal.
- Restore test belum lulus.
## Bukti Minimum
1. HTTPS aktif dan HTTP redirect.
2. Redis/PG hanya lokal.
3. Dua worker dan scheduler aktif.
4. Login 2FA dan foto R2 bekerja.
5. Bukti backup/restore tercatat di Build Log.
---
**Status:** FINAL v1 — panduan staging dan deployment siap digunakan.
