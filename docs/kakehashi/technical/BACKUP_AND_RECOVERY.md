---
title: "BACKUP_AND_RECOVERY"
status: "FINAL v1.1"
source_notion_title: "BACKUP_AND_RECOVERY"
exported_at: "2026-07-15"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# BACKUP_AND_RECOVERY

> [!NOTE]
> **BACKUP_AND_**[**RECOVERY.md**](BACKUP_AND_RECOVERY.md)** — Kakehashi (Kelompok 4 · Operasional).** Status: **FINAL v1.1 — Batch B aligned**. Runbook backup & recovery untuk solo developer. Baseline infra: **VPS 4 vCPU / 8 GB** + Redis co-located (PRD v0.3.14). Persona: DevOps/SRE. Tgl: 2026-07-13.
>
## 0. Tujuan & prinsip
Dokumen ini menjawab: *kalau data rusak, server hilang, atau backup gagal — apa yang dilakukan?*
Prinsip:
1. **Solo developer** — prosedur harus bisa diikuti sendiri saat panik.
2. **Manual-terkendali** lebih aman daripada otomasi rapuh.
3. **Tidak over-engineering** — single VPS, no HA, no multi-region.
4. **Database = source of truth.** Redis **tidak** di-backup sebagai data bisnis.
5. Selaras PRD §9.5: **RPO ≤ 24 jam**, **RTO beberapa jam**.
---
## 1. Aset yang dilindungi
<table header-row="true">
<tr>
<td>#</td>
<td>Aset</td>
<td>Tempat</td>
<td>Prioritas</td>
<td>Cara backup</td>
</tr>
<tr>
<td>1</td>
<td>PostgreSQL</td>
<td>VPS</td>
<td>**Kritis**</td>
<td>`pg_dump` harian → R2 bucket **terpisah**</td>
</tr>
<tr>
<td>2</td>
<td>Foto kandidat</td>
<td>R2 privat</td>
<td>Tinggi</td>
<td>**R2 versioning**  • lifecycle</td>
</tr>
<tr>
<td>3</td>
<td>Kode aplikasi</td>
<td>GitHub</td>
<td>Tinggi</td>
<td>`git clone`</td>
</tr>
<tr>
<td>4</td>
<td>`.env` / secret</td>
<td>Password manager (bukan Git)</td>
<td>Kritis</td>
<td>Cadangan manual</td>
</tr>
<tr>
<td>5</td>
<td>Dokumen kandidat</td>
<td>Google Drive</td>
<td>Operasional</td>
<td>Di luar sistem; prosedur manual</td>
</tr>
<tr>
<td>6</td>
<td>Redis</td>
<td>VPS [localhost](http://localhost)</td>
<td>**Tidak di-backup**</td>
<td>Cache/session/queue — rebuild otomatis</td>
</tr>
</table>
---
## 2. Strategi backup
### 2.1 Database PostgreSQL (utama)
- **Alat:** `pg_dump` + `gzip` + upload ke R2 (`rclone` atau `aws s3 cp`).
- **Jadwal:** harian pukul **02:00 JST** (cron).
- **Retensi:**
	- harian: **14 versi terakhir**
	- mingguan (snapshot hari Minggu): **12 minggu (\~3 bulan)**
- **Nama file:** `kakehashi_db_YYYYMMDD_HHMMSS.sql.gz`
- **Bucket:** R2 **terpisah** dari bucket foto (mis. `kakehashi-backup`).
- **Setelah dump:** cek ukuran file > 0; log ke `/var/log/kakehashi-backup.log`.
### 2.2 Foto kandidat (R2)
- Aktifkan **versioning** di bucket foto.
- Lifecycle: hapus noncurrent version setelah **90 hari**.
- Mirror harian penuh **tidak** wajib di MVP.
### 2.3 Kode aplikasi
- Source of truth = GitHub.
- Recovery = `git clone` + checkout tag/release yang dipakai produksi.
### 2.4 Config / secret
- Daftar KEY `.env` (tanpa nilai) → `DEPLOYMENT.md`.
- Nilai secret → password manager + salinan offline aman.
- Perubahan secret besar → catat tanggal di checklist internal.
### 2.5 Redis
- **Tidak di-backup.**
- Setelah restore: restart/flush Redis; user login ulang; queue kosong (email kritis boleh di-retry manual bila perlu).
---
## 3. Dampak baseline VPS 4 vCPU / 8 GB
<table header-row="true">
<tr>
<td>Aspek</td>
<td>Dulu (2C/2G)</td>
<td>Sekarang (4C/8G)</td>
</tr>
<tr>
<td>Headroom saat dump</td>
<td>Ketat, risiko OOM</td>
<td>Lebih aman; dump + app bisa jalan bersamaan</td>
</tr>
<tr>
<td>Redis</td>
<td>Tidak ada</td>
<td>Ada — **jangan** diandalkan untuk recovery data</td>
</tr>
<tr>
<td>Queue</td>
<td>DB 1 worker</td>
<td>Redis 2 worker — restore wajib pastikan Supervisor hidup</td>
</tr>
<tr>
<td>Restore test</td>
<td>Susah di mesin kecil</td>
<td>Bisa di VPS (DB temporary) atau laptop</td>
</tr>
<tr>
<td>RTO target</td>
<td>\~4–8 jam</td>
<td>**3–6 jam**</td>
</tr>
</table>
---
## 4. RPO & RTO
<table header-row="true">
<tr>
<td>Metrik</td>
<td>Nilai</td>
<td>Arti</td>
</tr>
<tr>
<td>**RPO**</td>
<td>**≤ 24 jam**</td>
<td>Maksimal kehilangan data sejak backup terakhir</td>
</tr>
<tr>
<td>**RTO**</td>
<td>**3–6 jam**</td>
<td>Waktu rebuild + restore + verifikasi go-live</td>
</tr>
</table>
WAL/PITR **ditunda pasca-MVP** (overkill solo). Catat sebagai opsi jika bisnis butuh RPO lebih ketat.
---
## 5. Skrip backup (konsep MVP)
```bash
#!/usr/bin/env bash
set -euo pipefail
STAMP=$(TZ=Asia/Tokyo date +%Y%m%d_%H%M%S)
DIR=/var/backups/kakehashi
FILE="${DIR}/kakehashi_db_${STAMP}.sql.gz"
mkdir -p "$DIR"
# Sesuaikan user/db; detail final di DEPLOYMENT.md
pg_dump -U kakehashi -d kakehashi --no-owner --no-acl | gzip > "$FILE"
# Upload ke R2 backup bucket
rclone copy "$FILE" r2:kakehashi-backup/db/
# Retensi lokal 3 hari; retensi R2 diurus lifecycle/script hapus
find "$DIR" -name '*.sql.gz' -mtime +3 -delete
echo "$(date -Is) backup ok $FILE size=$(stat -c%s "$FILE")" >> /var/log/kakehashi-backup.log
```
Cron contoh:
```javascript
0 2 * * * /usr/local/bin/kakehashi-backup.sh
```
---
## 6. Recovery — Skenario 1: VPS hilang / rebuild
Tujuan: bangun ulang dari nol.
1. Buat VPS baru **4 vCPU / 8 GB**, Ubuntu 24.04 LTS (detail → `DEPLOYMENT.md`).
2. Install stack: Nginx, PHP 8.4, PostgreSQL 18, Composer, Node, **Redis**, Supervisor.
3. Clone kode dari GitHub.
4. Isi `.env` dari password manager.
5. Buat database kosong + user DB.
6. Restore dump:
```bash
gunzip -c kakehashi_db_YYYYMMDD_HHMMSS.sql.gz | psql -U kakehashi -d kakehashi
```
1. `composer install --no-dev` · `php artisan migrate --force` (cek/no-op jika dump sudah full schema).
2. Pastikan Redis jalan · `php artisan config:cache` · `route:cache`.
3. Nyalakan **2 queue worker** + schedule (Supervisor).
4. Verifikasi checklist §9.
5. Notifikasi internal: server sudah pulih.
---
## 7. Recovery — Skenario 2: data terhapus / bug
Tujuan: kembalikan ke titik sebelum kecelakaan.
1. Hentikan write berbahaya (`php artisan down` bila perlu).
2. Ambil backup **sebelum** waktu insiden.
3. **Jangan langsung overwrite production** bila hanya sebagian data hilang:
	- restore ke DB temporary `kakehashi_restore`
	- bandingkan / salin baris yang hilang
4. Jika kerusakan luas → overwrite production dari dump bersih.
5. Restart Redis (opsional flush) agar cache tidak menampilkan data usang.
6. Catat insiden singkat: kapan, apa, dump mana yang dipakai.
---
## 8. Recovery — Skenario 3: backup korup
Tujuan: deteksi sebelum dibutuhkan.
**Deteksi proaktif (wajib):**
- cek ukuran file dump harian (waspadai 0 byte / mengecil drastis)
- **minimal satu restore test sukses ke DB temporary sebelum go-live**
- setelah go-live, restore test bulanan ke DB temporary
Jika korup:
1. coba dump harian sebelumnya (14 hari)
2. lalu dump mingguan (3 bulan)
3. perbaiki cron/kredensial R2 segera
Jangan menunggu insiden untuk pertama kali mencoba restore.
---
## 9. Checklist verifikasi pasca-restore
- [ ] Login Super Admin + 2FA
- [ ] Jumlah kandidat masuk akal
- [ ] Foto kandidat tampil (R2)
- [ ] Lookup bilingual jalan
- [ ] Queue worker **2 proses** hidup
- [ ] Redis `PING` ok
- [ ] `php artisan schedule:list` ok
- [ ] Link Tamu aktif dicek / di-revoke bila perlu
- [ ] Tidak ada error 500 di log 15 menit pertama
---
## 10. Wewenang & komunikasi
<table header-row="true">
<tr>
<td>Aksi</td>
<td>Siapa</td>
</tr>
<tr>
<td>Jalankan restore</td>
<td>Super Admin / owner teknis</td>
</tr>
<tr>
<td>Putuskan overwrite production</td>
<td>Owner</td>
</tr>
<tr>
<td>Notifikasi tim internal</td>
<td>Owner</td>
</tr>
<tr>
<td>Libatkan VPS / Cloudflare</td>
<td>Owner</td>
</tr>
</table>
Template singkat:
> Insiden: \[ringkas\]. Status: recovery berjalan / selesai. Dampak: \[data hilang maks 24 jam / tidak ada\]. ETA: \[jam\].
---
## 11. Interaksi dengan DATA_RETENTION_AND_PRIVACY
- Backup lama **boleh** masih berisi PII yang sudah dianonimkan di production.
- Solusi MVP: biarkan hilang alami saat backup kedaluwarsa (14 hari / 3 bulan).
- Setelah restore dari dump lama: periksa kandidat yang seharusnya `pii_anonymized_at` terisi — bila perlu jalankan ulang anonimisasi manual sebelum buka ke user.
- Menghapus URL di aplikasi **tidak** menghapus file Google Drive; ikuti checklist anonimisasi di DATA_RETENTION_AND_PRIVACY.
---
## 12. Yang sengaja tidak dilakukan di MVP
- Streaming WAL / PITR otomatis
- Replica PostgreSQL
- Multi-region DR
- Backup Redis
- Tool backup enterprise
- Backup real-time
---
## 13. Handoff
- **Hulu:** PRD v0.3.14 §9.5/§9.6, ARCHITECTURE (Redis + 2 worker), DATA_RETENTION_AND_PRIVACY (FINAL).
- **Hilir:** `DEPLOYMENT.md` (install stack, cron, Supervisor, template `.env`, path skrip).
- **SECURITY_**[**CHECKLIST.md**](SECURITY_CHECKLIST.md)**:** akses R2 backup bucket, secret management, Redis bind [localhost](http://localhost).
---
## 14. Definisi Selesai (FINAL)
- [x] Strategi backup DB harian + retensi 14 hari / 3 bulan
- [x] R2 versioning untuk foto; bucket backup terpisah
- [x] Redis tidak di-backup (disadari eksplisit)
- [x] Tiga skenario recovery + restore test bulanan
- [x] RPO ≤ 24 jam · RTO 3–6 jam
- [x] Realistis solo developer di VPS 4C/8G
---
*Status: FINAL v1.1 — Batch B 2026-07-14. Selaras PRD_Kakehashi_v0_3_14.*
