---
title: "DEPLOYMENT"
status: "FINAL v1.2"
source_notion_title: "DEPLOYMENT"
exported_at: "2026-07-15"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# DEPLOYMENT

> [!NOTE]
> [**DEPLOYMENT.md**](DEPLOYMENT.md)** — Kakehashi (Kelompok 4 · Operasional).** Status: **FINAL v1.2 — Batch B aligned**. Runbook bangun/pindah server untuk solo developer. Baseline: **VPS 4 vCPU / 8 GB**, Ubuntu 24.04 LTS, Redis co-located, queue 2 worker (PRD v0.3.14). Beda dari BACKUP_AND_RECOVERY: dokumen ini = *bagaimana membangun server*; recovery = *bagaimana pulih data*. Persona: DevOps/Infra. Tgl: 2026-07-13.
>
## 0. Tujuan & prinsip
1. **Solo developer** — langkah harus bisa diikuti sendiri tanpa tim ops.
2. **Manual-terkendali** lebih aman daripada otomasi rapuh.
3. **Tidak over-engineering** — single VPS, no HA, no K8s, no CI/CD wajib.
4. **Secret tidak di Git/Notion** — hanya daftar KEY; nilai di password manager.
5. Selaras PRD v0.3.12 §9.5/§9.6 + ARCHITECTURE + BACKUP_AND_RECOVERY.
---
## 1. Target server
<table header-row="true">
<tr>
<td>Item</td>
<td>Keputusan</td>
</tr>
<tr>
<td>Spec</td>
<td>**4 vCPU / 8 GB RAM / 100–120 GB SSD**</td>
</tr>
<tr>
<td>OS</td>
<td>**Ubuntu 24.04 LTS**</td>
</tr>
<tr>
<td>Pola</td>
<td>Single VPS, no HA</td>
</tr>
<tr>
<td>Provider / region</td>
<td>**Belum dikunci** — isi saat beli (Tokyo / Singapore / ID). Spek 4C/8G wajib.</td>
</tr>
<tr>
<td>Domain</td>
<td>HTTPS wajib (Let's Encrypt / Certbot)</td>
</tr>
</table>
### 1.1 Alokasi RAM kasar (8 GB)
- PostgreSQL \~2.0–2.5 GB
- PHP-FPM \~1.5–2.0 GB
- Redis ≤1.0 GB
- Queue worker ×2 + Nginx + OS \~ sisa
---
## 2. Stack yang diinstall
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi / catatan</td>
</tr>
<tr>
<td>Nginx</td>
<td>Reverse proxy → PHP-FPM; root ke `public/`</td>
</tr>
<tr>
<td>PHP</td>
<td>**8.4**  • ext: `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`, `redis`, `opcache`</td>
</tr>
<tr>
<td>Composer</td>
<td>2.x</td>
</tr>
<tr>
<td>Node.js</td>
<td>LTS — **hanya build asset**, bukan runtime app</td>
</tr>
<tr>
<td>PostgreSQL</td>
<td>**18.x**  • extension `pg_trgm`</td>
</tr>
<tr>
<td>Redis</td>
<td>Co-located; **bind 127.0.0.1 only**</td>
</tr>
<tr>
<td>Supervisor</td>
<td>**2** proses `queue:work`</td>
</tr>
<tr>
<td>Git</td>
<td>Clone dari GitHub</td>
</tr>
<tr>
<td>Certbot</td>
<td>SSL Let's Encrypt</td>
</tr>
<tr>
<td>rclone / AWS CLI</td>
<td>Opsional — upload backup ke R2</td>
</tr>
</table>
---
## 3. Struktur direktori (MVP)
**Keputusan:** single directory (bukan multi-release zero-downtime).
```plain text
/var/www/kakehashi/     # kode aplikasi (git clone)
/var/www/kakehashi/.env # secret — permission ketat, bukan di Git
/var/backups/kakehashi/ # dump lokal sementara sebelum upload R2
/usr/local/bin/kakehashi-backup.sh
```
---
## 4. Persiapan akun (sebelum install)
- [ ] Repo GitHub siap (private)
- [ ] Domain + DNS mengarah ke VPS (A/AAAA)
- [ ] Bucket R2 foto (privat + versioning)
- [ ] Bucket R2 **backup** (terpisah dari foto)
- [ ] Kredensial R2 (access key / secret)
- [ ] Password manager untuk `.env` production
- [ ] SSH key (disable password login bila siap)
---
## 5. Install OS & firewall
1. Buat VPS 4C/8G, Ubuntu 24.04, login SSH.
2. Update OS:
```bash
sudo apt update && sudo apt upgrade -y
```
1. Firewall (UFW):
```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```
1. Buat user deploy non-root (opsional tapi disarankan) + sudo.
---
## 6. Install stack (ringkas)
Urutan konsep (perintah paket mengikuti repo Ubuntu/PPA resmi saat implementasi):
1. **Nginx**
2. **PHP 8.4-FPM** + extensions di §2
3. **Composer 2**
4. **Node.js LTS** (NodeSource atau paket distro)
5. **PostgreSQL 18** + buat role/database app
6. `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
7. **Redis** — pastikan `bind 127.0.0.1` / tidak expose publik
8. **Supervisor**
9. **Certbot** (`python3-certbot-nginx`)
10. **rclone** atau AWS CLI (backup)
### 6.1 PostgreSQL tuning ringan (MVP)
- `shared_buffers` \~2 GB
- `effective_cache_size` \~6 GB
- `work_mem` 16–32 MB
- autovacuum default cukup
### 6.2 PHP-FPM (titik awal)
- `pm = dynamic`
- `pm.max_children` \~10–20 (tune setelah live)
- OPcache aktif
### 6.3 Redis
- bind [localhost](http://localhost) only
- `maxmemory` ≤1 GB
- **`maxmemory-policy noeviction`** — jangan evict session, rate-limit, atau queue; cache aplikasi wajib TTL
- pantau memory (`INFO memory`); alarm operasional sederhana sebelum mendekati batas; password opsional bila tetap [localhost](http://localhost)
---
## 7. Template `.env` (KEY only — tanpa nilai secret)
Salin ke password manager; **jangan** commit ke Git.
### App
```javascript
APP_NAME=Kakehashi
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://example.com
APP_TIMEZONE=UTC
```
### Database
```javascript
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```
### Redis / cache / session / queue
```javascript
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=30
QUEUE_CONNECTION=redis
```
### Mail (minimal)
```javascript
MAIL_MAILER=
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```
### R2 (foto) — S3-compatible
```javascript
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=true
```
**Caveat R2:** aws-sdk-php checksum crc32 — set integrity `WHEN_REQUIRED` + `retain_visibility=false` di config disk (ARCHITECTURE / MODULE_CANDIDATES).
### Lain
```javascript
LOG_CHANNEL=stack
LOG_LEVEL=error
```
---
## 8. Deploy pertama (fresh)
1. Clone repo:
```bash
sudo mkdir -p /var/www
sudo git clone <REPO_URL> /var/www/kakehashi
cd /var/www/kakehashi
```
1. Salin `.env` production dari password manager.
2. Install dependensi:
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```
1. Key & permission:
```bash
php artisan key:generate   # hanya jika APP_KEY masih kosong
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```
1. Database:
```bash
php artisan migrate --force
php artisan db:seed --force   # role hardcode + lookup dasar
```
1. Cache config:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
1. Nginx site → `root /var/www/kakehashi/public;` + `try_files` Laravel.
2. SSL:
```bash
sudo certbot --nginx -d example.com
```
1. Supervisor (2 worker) — lihat §10.
2. Cron schedule + backup — lihat §10.
3. Checklist go-live §13.
---
## 9. Nginx (poin wajib)
- `root` ke `.../public`
- PHP via `php8.4-fpm.sock`
- HTTPS only + redirect HTTP→HTTPS
- Jangan serve `.env`
- Header keamanan detail → `SECURITY_CHECKLIST.md`
---
## 10. Supervisor, cron, backup
### 10.1 Queue worker (2 proses)
Contoh program Supervisor (dua proses identik atau `numprocs=2`):
```plain text
[program:kakehashi-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/kakehashi/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --max-jobs=1000
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/kakehashi-worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start kakehashi-worker:*
```
### 10.2 Cron
```javascript
* * * * * www-data php /var/www/kakehashi/artisan schedule:run >> /dev/null 2>&1
0 2 * * * root /usr/local/bin/kakehashi-backup.sh
```
Detail skrip backup → `BACKUP_AND_RECOVERY.md`.
---
## 11. Deploy update (rilis berikutnya)
```bash
cd /var/www/kakehashi
php artisan down
git pull
composer install --no-dev --optimize-autoloader
# jika ada perubahan frontend:
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan up
```
Catatan:
- Backup DB disarankan sebelum migrate berisiko.
- Zero-downtime **tidak** dijanjikan di MVP (`artisan down` sebentar OK).
- Jangan commit `.env`.
---
## 12. Pindah VPS (urutan benar)
1. Setup server baru (install stack §5–§6).
2. Restore database dari backup R2 (`BACKUP_AND_RECOVERY` skenario 1).
3. Clone code + pasang `.env`.
4. `composer install --no-dev`, build asset, `migrate --force` (cek/no-op).
5. Nyalakan Redis, Supervisor (2 worker), cron, Nginx+SSL.
6. Tes internal (login, kandidat, foto R2, queue).
7. Switch DNS ke IP baru.
8. Pantau; matikan VPS lama setelah stabil.
---
## 13. Checklist go-live
Sebelum pengguna pertama:
- [ ] HTTPS aktif; HTTP redirect
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] Password Super Admin default diganti
- [ ] 2FA Super Admin aktif
- [ ] Migrasi + seeder role/lookup OK
- [ ] Login + 2FA jalan
- [ ] Redis `PING` OK ([localhost](http://localhost)) + policy `noeviction` terverifikasi + memory sehat
- [ ] 2 queue worker hidup
- [ ] Upload/tampil foto R2 OK
- [ ] Backup cron pernah sukses minimal 1× **dan satu restore test ke DB temporary berhasil** sebelum go-live
- [ ] Firewall hanya 22/80/443
- [ ] `.env` tidak di Git; permission file ketat
- [ ] `pg_trgm` extension aktif
- [ ] Tidak ada error 500 di log 15 menit pertama
---
## 14. Troubleshooting singkat
<table header-row="true">
<tr>
<td>Gejala</td>
<td>Cek</td>
</tr>
<tr>
<td>500 setelah deploy</td>
<td>`storage/logs`, permission `storage/`, `APP_KEY`, `config:clear` lalu cache ulang</td>
</tr>
<tr>
<td>Queue tidak jalan</td>
<td>Supervisor status, `QUEUE_CONNECTION=redis`, Redis hidup</td>
</tr>
<tr>
<td>Foto gagal upload</td>
<td>Kredensial R2, endpoint, caveat checksum SDK</td>
</tr>
<tr>
<td>Migrate gagal</td>
<td>Koneksi PG, extension `pg_trgm`, user DB privilege</td>
</tr>
<tr>
<td>SSL gagal</td>
<td>DNS sudah mengarah, port 80 terbuka</td>
</tr>
</table>
---
## 15. Yang sengaja tidak dilakukan di MVP
- Docker Compose/Swarm/K8s sebagai syarat
- CI/CD GitHub Actions wajib
- Blue/green / multi-release
- Managed Redis / managed PostgreSQL
- Ansible/Terraform
- Zero-downtime deploy
---
## 16. Handoff
- **Hulu:** PRD v0.3.14 §9.5/§9.6, ARCHITECTURE, TECH_VERSION_SEED, BACKUP_AND_RECOVERY (FINAL).
- **Hilir:** `SECURITY_CHECKLIST.md` (headers, secrets, Redis, rate limit, hardening).
- **Coding lokal:** boleh mulai tanpa menunggu production VPS final; dokumen ini dipakai saat staging/production.
---
## 17. Definisi Selesai (FINAL)
- [x] Spek VPS 4C/8G + Ubuntu 24.04
- [x] Stack install lengkap termasuk Redis + 2 worker
- [x] Template `.env` KEY only
- [x] Deploy pertama + deploy update + pindah VPS
- [x] Checklist go-live
- [x] Integrasi backup cron (rujuk BACKUP_AND_RECOVERY)
- [x] Realistis solo developer
---
*Status: FINAL v1.2 — Batch B 2026-07-14. Selaras PRD_Kakehashi_v0_3_14 + BACKUP_AND_RECOVERY.*
