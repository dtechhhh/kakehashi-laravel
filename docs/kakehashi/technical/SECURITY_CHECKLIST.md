---
title: "SECURITY_CHECKLIST"
status: "FINAL v1.2"
source_notion_title: "SECURITY_CHECKLIST"
exported_at: "2026-07-28"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# SECURITY_CHECKLIST

> [!NOTE]
> **SECURITY_**[**CHECKLIST.md**](SECURITY_CHECKLIST.md)** — Kakehashi (Kelompok 4 · Operasional).** Status: **FINAL v1.2 — Batch B aligned**. Checklist keamanan lintas-modul yang bisa dicentang saat development & sebelum go-live. Bukan esai prinsip. Detail flow dirujuk ke modul final (AUTH, GUEST, ROLES, DEPLOYMENT). Persona: AppSec. Tgl: 2026-07-13.
>
## 0. Cara pakai
1. Centang saat implementasi / review PR / pre-production.
2. Yang sudah punya rumah di modul lain **dirujuk**, tidak diulang panjang.
3. Prinsip: solo developer, tidak over-engineering, data kandidat prioritas tertinggi.
**Rujukan:**
- Auth/2FA/step-up/lockout → `MODULE_AUTH`
- Tamu whitelist & throttle → `MODULE_GUEST_ACCESS`
- RBAC → `ROLES_AND_PERMISSIONS`
- Server/SSL/Redis bind → `DEPLOYMENT`
- Backup bucket → `BACKUP_AND_RECOVERY`
- PII/retensi → `DATA_RETENTION_AND_PRIVACY`
---
## 1. Secrets & konfigurasi
- [ ] `.env` **tidak pernah** di-commit ke Git
- [ ] `APP_KEY` unik per environment production
- [ ] `APP_DEBUG=false` dan `APP_ENV=production` di production
- [ ] Secret hanya di password manager + file `.env` server
- [ ] Permission `.env` ketat (mis. `600`, owner deploy/www)
- [ ] Tidak ada credential hardcode di kode, seeder, atau dokumentasi publik
- [ ] Template KEY `.env` mengikuti `DEPLOYMENT.md` (tanpa nilai secret)
---
## 2. Transport & server
- [ ] HTTPS wajib di staging/production; HTTP → HTTPS redirect
- [ ] Firewall hanya **22 / 80 / 443**
- [ ] Redis bind [localhost](http://localhost) saja + `maxmemory-policy noeviction`; cache TTL; memory dimonitor
- [ ] PostgreSQL tidak expose ke internet publik
- [ ] Security headers aktif minimal:
	- `Strict-Transport-Security`
	- `X-Content-Type-Options: nosniff`
	- `X-Frame-Options: DENY` (atau CSP `frame-ancestors`)
	- `Referrer-Policy` ketat (`no-referrer` untuk surface Tamu)
	- CSP dasar yang kompatibel Livewire/Blade (tidak longgar `unsafe-inline` sembarangan tanpa alasan)
- [ ] SSH key-based; password login dimatikan bila sudah siap
---
## 3. Autentikasi & sesi
*(Sumber angka: MODULE_AUTH FINAL)*
- [ ] Password policy: min **12** karakter + **3 dari 4** kelas (besar/kecil/angka/simbol)
- [ ] Hash password **bcrypt cost 12**
- [ ] Session idle **30 menit**; driver production = **redis**
- [ ] `session()->regenerate()` pada login sukses (anti fixation)
- [ ] 2FA TOTP wajib: Approver Kandidat, Manajer Job, Super Admin
- [ ] Recovery codes: **8**, single-use, encrypted at-rest
- [ ] Enrol 2FA wajib konfirmasi kode sebelum aktif (anti lock-out)
- [ ] Lockout login: **5 gagal / 15 menit** (key email+IP)
- [ ] Email adalah satu-satunya login identifier; dinormalisasi lowercase untuk login/throttle; tidak ada username
- [ ] User `Nonaktif` tidak bisa login
- [ ] Step-up re-auth (password + TOTP, TTL 5 menit, per-aksi) hanya untuk pemicu final:
	1. ubah role / nonaktifkan akun
	2. setujui tutup kontainer wawancara
	3. setujui keluarkan/cabut kandidat
	4. kelola lookup/master perusahaan
	5. anonimisasi PII
---
## 4. Otorisasi (RBAC)
*(Sumber: ROLES_AND_PERMISSIONS FINAL)*
- [ ] Semua route internal di belakang auth + Policy/permission server-side
- [ ] UI hide tombol **bukan** satu-satunya kontrol
- [ ] Super Admin read-only di modul operasional
- [ ] SoD hard-block kombinasi role terlarang
- [ ] Default 1 user = 1 role di MVP
- [ ] Tamu **bukan** akun RBAC; guard token terpisah
- [ ] Hanya Super Admin lihat audit log pusat
---
## 5. Rate limiting
*(Production driver: Redis)*
<table header-row="true">
<tr>
<td>Surface</td>
<td>Aturan</td>
</tr>
<tr>
<td>Login</td>
<td>5 gagal / 15 menit per email+IP</td>
</tr>
<tr>
<td>Guest token invalid</td>
<td>10 / menit / IP</td>
</tr>
<tr>
<td>Guest buka link sah</td>
<td>60 / menit / token</td>
</tr>
<tr>
<td>Kode tambahan guest</td>
<td>5 gagal → lock 15 menit</td>
</tr>
<tr>
<td>Endpoint internal umum</td>
<td>Throttle wajar per user (default Laravel ketat cukup; tidak perlu WAF enterprise)</td>
</tr>
</table>
- [ ] Semua throttle di atas diimplementasikan server-side
- [ ] Token mentah **tidak** ditulis ke log
- [ ] Approval domain selain `lookup_request` dan `company_request` memiliki tepat satu `pending_request` aktif; pending+status submission dibuat satu transaksi; keputusan memakai conditional pending→approved/rejected
- [ ] `lookup_request`/`company_request` memakai status tabel masing-masing sebagai sumber keputusan, tidak membuat `pending_request`, dan tidak menambah tipe ke `PendingType`; keduanya tetap memakai RBAC, `StepUpService`, `AuditLogger`, `NotificationService`, self-decision guard, transaksi/rollback, dan email/queue after-commit
---
## 6. Validasi input & output
- [ ] Setiap write memakai Form Request / validasi server-side
- [ ] Blade auto-escape (` `); `{!! !!}` hanya jika sadar aman
- [ ] Upload foto: validasi MIME asli, max **5MB**, `jpg/png/webp`
- [ ] Tolak mass-assignment field sensitif
- [ ] Status/enum hanya dari whitelist kanonik (backed enum + CHECK bila ada)
- [ ] Pesan error production tidak membocorkan stack trace / query SQL
---
## 7. Database & query
- [ ] Eloquent / query builder sebagai default
- [ ] Raw SQL hanya bila perlu + **parameter binding**
- [ ] Optimistic locking `version` → HTTP **409**
- [ ] Anti double-decision: cek sumber keputusan masih `pending` di dalam transaksi (`pending_request.status`, `lookup_request.status`, atau `company_request.status`)
- [ ] Partial unique satu participation Wawancara aktif per kandidat
- [ ] Partial unique satu revision Draft/menunggu aktif per main candidate
- [ ] Batch normal Placement memakai transfer ownership: source `Siap Dikirim` + availability `Sedang Dipakai`; tidak memanggil `markInUse()` untuk flip
- [ ] Bulk pull pakai `FOR UPDATE` hanya di skenario yang disepakati
- [ ] Tidak ada hard delete operasional untuk jejak bisnis
- [ ] Soft-delete/restore Kandidat tidak memiliki route/button/Policy aktif di MVP (reserved/deferred)
- [ ] Anonimisasi ditolak bila ada participation aktif, placement `Bekerja`, pending terbuka, atau revision Draft/menunggu; guard direvalidasi dalam transaksi
---
## 8. File & storage
- [ ] Foto wajah: R2 **privat** + SSE at-rest + signed URL pendek (5–15 menit)
- [ ] Dokumen peserta: link Google Drive privat; hanya akun/grup staf berwenang; review permission saat onboarding/offboarding
- [ ] Tamu tidak bisa akses dokumen identitas / imigrasi / keluarga / kesehatan
- [ ] Sertifikat ke Tamu hanya bila lookup `is_shareable=true`
- [ ] Caveat R2 checksum `WHEN_REQUIRED` + `retain_visibility=false` diuji
- [ ] Custom domain R2 / proxy untuk presigned URL **wajib sebelum go-live foto Tamu** (hindari bocor account-id/bucket)
- [ ] Saat anonimisasi: hapus foto R2; URL dokumen dikosongkan; Drive dibersihkan manual (DATA_RETENTION)
---
## 9. Akses Tamu
- [ ] Token acak panjang; simpan **hash**, bukan plain token
- [ ] Validasi urut: token ada → belum kadaluarsa → kontainer Aktif → kode tambahan (bila ada)
- [ ] Read-model whitelist **G2/G3** saja (server-side terpusat)
- [ ] G2 pseudonim (Nomor Induk); G3 boleh nama/foto/riwayat penuh sesuai PRD v0.3.11+
- [ ] Field HIDE tetap HIDE (kesehatan, keluarga, imigrasi, kontak, dokumen, IQ/psikotes, dll.)
- [ ] Nama/foto/nama lembaga/nama perusahaan **bukan** parameter sort/filter Tamu
- [ ] Halaman Tamu `Cache-Control: no-store`
- [ ] Audit `GUEST_ACCESS` + `GUEST_DETAIL_VIEWED`
- [ ] Percobaan gagal → log keamanan app (bukan spam `audit_log`)
---
## 10. Audit & logging
- [ ] Aksi sensitif lewat `AuditLogger` terpusat
- [ ] `audit_log` immutable (no UPDATE/DELETE oleh role app)
- [ ] `actor_role_snapshot` diisi saat event (bukan join live role)
- [ ] Hindari PII mentah berlebihan di `detail` JSONB (cukup ID + metadata)
- [ ] Audit Auth: user dikenal→user_id; anonim→masked email/HMAC fingerprint; email input mentah dilarang; IP hanya kolom `ip`
- [ ] Production log level `error` (atau setara); log dirotasi
- [ ] Bisnis+audit DB commit dahulu; email/queue Redis after-commit; gagal enqueue tidak rollback transaksi bisnis
- [ ] `guest_access_log.ip` tidak disimpan selamanya (retensi \~180 hari — DATA_RETENTION)
---
## 11. Dependencies & supply chain
- [ ] Production: `composer install --no-dev --optimize-autoloader`
- [ ] Pin versi mayor sesuai TECH_VERSION_SEED / PRD
- [ ] Package baru (auth/file/security) di-review sebelum dipakai
- [ ] Tidak commit `vendor/` secret atau file debug
---
## 12. Checklist pre-production (go-live security)
Sebelum user nyata:
- [ ] HTTPS + firewall OK
- [ ] Debug off; secret aman
- [ ] Super Admin password diganti + 2FA aktif
- [ ] Redis [localhost](http://localhost) + 2 queue worker hidup
- [ ] Rate limit login & guest aktif
- [ ] RBAC + Policy diuji untuk tiap role inti
- [ ] R2 privat + signed URL OK; proxy/custom domain bila Tamu lihat foto
- [ ] Guest whitelist ditegakkan server-side (uji field HIDE)
- [ ] Audit event sensitif muncul di viewer Super Admin
- [ ] Backup cron pernah sukses minimal 1× + restore test DB temporary pernah berhasil
- [ ] Tidak ada credential di repo
---
## 13. Yang ditunda pasca-MVP
- WAF cloud berbayar / SIEM / SOC
- Secret manager cloud (Vault, dll.)
- Column-level encryption nomor paspor/zairyu
- App-level envelope encryption dokumen (sudah diganti model Drive privat)
- Pen-test formal / bug bounty
- mTLS internal
---
## 14. Handoff
- **Hulu:** MODULE_AUTH, MODULE_GUEST_ACCESS, ROLES, ARCHITECTURE, DEPLOYMENT, DATA_RETENTION, BACKUP.
- **Hilir coding:** centang bagian 1–11 selama dev; bagian 12 sebelum production.
- **API_CONTRACTS:** kontrak public service tidak menggantikan otorisasi di checklist ini.
---
## 15. Definisi Selesai (FINAL)
- [x] Format checklist actionable
- [x] Angka auth/guest/rate-limit selaras modul final
- [x] Server/Redis/HTTPS selaras DEPLOYMENT 4C/8G
- [x] Tanpa over-engineering enterprise
- [x] Pre-production checklist satu halaman
---
*Status: FINAL v1.2 — Batch B 2026-07-14. Selaras PRD_Kakehashi_v0_3_14 + modul keamanan final.*
