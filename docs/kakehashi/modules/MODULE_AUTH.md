---
title: "MODULE_AUTH"
status: "FINAL"
source_notion_title: "MODULE_AUTH"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_AUTH

> [!NOTE]
> **MODULE_**[**AUTH.md**](MODULE_AUTH.md)** — Kakehashi (Kelompok 2 · Modul).** Fondasi autentikasi: login + lockout, 2FA TOTP, recovery codes, step-up re-auth, password & session policy, audit. **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.** Dependency final: ROLES_AND_PERMISSIONS, GLOSSARY, BUSINESS_RULES (audit). Jika konflik → PRD berlaku.<br>**Status: FINAL — selaras PRD v0.3.13.** · Persona: Senior IAM Engineer
>
## 0. Scope & Dependency
**In-scope:** flow login (**email + password; email adalah satu-satunya login identifier MVP, tanpa username terpisah**) + lockout, enrollment & verifikasi TOTP 2FA (RFC 6238), recovery codes, step-up re-auth per-aksi, password policy, session management, audit event auth, kontrak integrasi RBAC.
**Out-of-scope (tegas):** SSO eksternal & OAuth pihak ketiga — **ditolak PRD** (§4.5: "BUKAN login berbasis akun Google/OAuth"). Detail token Tamu → MODULE_GUEST_ACCESS. Skema tabel rinci → DATABASE_SCHEMA. Matriks izin → ROLES_AND_PERMISSIONS.
**Dependency hulu (final):** PRD v0.3, ROLES_AND_PERMISSIONS (final), GLOSSARY (final), BUSINESS_RULES (audit), DECISIONS_LOG, ARCHITECTURE (D4 audit, D5 queue, D9 i18n).
## 1. Keputusan Terkunci (disetujui user 2026-06-29)
<table header-row="true">
<tr>
<td>Kode</td>
<td>Topik</td>
<td>Keputusan</td>
</tr>
<tr>
<td>A-1</td>
<td>Daftar step-up</td>
<td>**Ikut PRD §4.6/Lampiran D + ROLES §8.2 (final)**, BUKAN daftar di brief misi. Approve/Reject kandidat = approval rutin → TIDAK memicu step-up.</td>
</tr>
<tr>
<td>A-2</td>
<td>Library TOTP</td>
<td>**Laravel Fortify** (first-party), TOTP RFC 6238 + recovery codes bawaan</td>
</tr>
<tr>
<td>A-3</td>
<td>Hashing password</td>
<td>**bcrypt cost 12** (cukup & deterministik; tetap di VPS 4C/8G — argon2id opsional)</td>
</tr>
<tr>
<td>A-4</td>
<td>Lockout</td>
<td>**5 gagal berturut → lock 15 menit** (per email + IP)</td>
</tr>
<tr>
<td>A-5</td>
<td>Recovery codes</td>
<td>**8 kode**, single-use, terenkripsi at-rest, regen meng-invalidasi semua kode lama</td>
</tr>
<tr>
<td>A-6</td>
<td>Step-up TTL & scope</td>
<td>**5 menit, per-aksi** (re-entry password + TOTP setiap aksi sensitif)</td>
</tr>
<tr>
<td>A-7</td>
<td>Password policy</td>
<td>**min 12 char + wajib 3 dari 4 kelas** (huruf besar/kecil/angka/simbol)</td>
</tr>
</table>
> **Catatan koreksi rujukan PRD:** brief menyebut "TOTP §9.1" dan "step-up §9.2". Yang benar: TOTP wajib di **PRD §4.5** (dipertegas §9.1 Keamanan Data); step-up di **§4.6 + Lampiran D**. §9.2 PRD = Performa. Koreksi rujukan, bukan perubahan isi PRD.
## 2. Tabel Verifikasi Teknologi (browsing live 2026-06-29)
<table header-row="true">
<tr>
<td>Tech</td>
<td>Versi rekomen</td>
<td>Status maint</td>
<td>Caveat proyek</td>
<td>Sumber (akses 2026-06-29)</td>
</tr>
<tr>
<td>Laravel</td>
<td>13.x</td>
<td>✅ Aktif</td>
<td>Fortify 2FA kini bawaan starter kit</td>
<td>[laravel.com/docs/13.x](http://laravel.com/docs/13.x)</td>
</tr>
<tr>
<td>PHP</td>
<td>8.4</td>
<td>✅ Active s/d Des 2026</td>
<td>`PASSWORD_BCRYPT` native</td>
<td>[php.net](http://php.net)</td>
</tr>
<tr>
<td>Laravel Fortify</td>
<td>bawaan L13.x</td>
<td>✅ First-party</td>
<td>**Caveat lock-out** (issue #201): wajib pakai endpoint *confirm* agar user tak terkunci; `password.confirm` default **3 jam & password-only** → tak cukup utk step-up</td>
<td>[laravel.com/docs/13.x/fortify](http://laravel.com/docs/13.x/fortify)</td>
</tr>
<tr>
<td>spatie/laravel-permission</td>
<td>`^8.1`</td>
<td>✅ Kompatibel L13</td>
<td>RBAC saja; role hardcode di-seed</td>
<td>[spatie.be/docs/laravel-permission/v8](http://spatie.be/docs/laravel-permission/v8)</td>
</tr>
<tr>
<td>bcrypt (Hash)</td>
<td>cost 12</td>
<td>✅ native</td>
<td>Deterministik & cukup; VPS 4C/8G tidak memaksa ganti ke argon2id</td>
<td>[laravel.com/docs/13.x/hashing](http://laravel.com/docs/13.x/hashing)</td>
</tr>
<tr>
<td>RateLimiter</td>
<td>bawaan</td>
<td>✅</td>
<td>Driver cache **`redis`** (co-located; PRD §9.6 v0.3.12)</td>
<td>[laravel.com/docs/13.x/rate-limiting](http://laravel.com/docs/13.x/rate-limiting)</td>
</tr>
<tr>
<td>encrypted cast</td>
<td>bawaan</td>
<td>✅</td>
<td>AES-256-CBC + MAC; key rotation via `APP_PREVIOUS_KEYS`; dipakai utk `two_factor_secret` & recovery codes</td>
<td>[laravel.com/docs/13.x/encryption](http://laravel.com/docs/13.x/encryption)</td>
</tr>
</table>
## 3. Domain & Persistence (high-level)
Kolom auth menempel pada model `User` (tabel `users`) — Fortify standar + tambahan:
<table header-row="true">
<tr>
<td>Kolom</td>
<td>Tipe</td>
<td>Catatan</td>
</tr>
<tr>
<td>`email`</td>
<td>string unik case-insensitive secara logis</td>
<td>satu-satunya identitas login; simpan/normalisasi lowercase</td>
</tr>
<tr>
<td>`password`</td>
<td>string</td>
<td>bcrypt cost 12; `must_change_password` flag</td>
</tr>
<tr>
<td>`two_factor_secret`</td>
<td>text, **encrypted**</td>
<td>TOTP secret (cast `encrypted`)</td>
</tr>
<tr>
<td>`two_factor_recovery_codes`</td>
<td>text, **encrypted**</td>
<td>8 kode single-use (cast `encrypted`)</td>
</tr>
<tr>
<td>`two_factor_confirmed_at`</td>
<td>timestamp nullable</td>
<td>enrolment selesai (anti lock-out)</td>
</tr>
<tr>
<td>`must_change_password`</td>
<td>boolean</td>
<td>wajib ganti saat login pertama (PRD §6.1)</td>
</tr>
<tr>
<td>`status_akun`</td>
<td>enum `Aktif`/`Nonaktif`</td>
<td>user Nonaktif tak bisa login (PRD §5.1)</td>
</tr>
</table>
> Status akun & role bukan milik file ini — hanya referensi. Role/permission → spatie. Detail kolom & index → DATABASE_SCHEMA.
## 4. Flow
### 4.1 Login (email + password) + lockout
1. User submit email + password ke `POST /login`; email dinormalisasi lowercase. Username tidak didukung di MVP.
2. **Throttle** (RateLimiter, key = `lower(email)|ip`): **5 percobaan / 15 menit**. Saat ke-6 → tolak `429`, set lockout 15 menit, audit `LOGIN_LOCKED_OUT`.
3. Cek kredensial. Gagal → audit `LOGIN_FAILED`: bila user dikenal simpan `user_id`; bila anonim simpan email masked atau HMAC fingerprint—jangan email input mentah. IP hanya di kolom `ip`, tidak diduplikasi di JSONB.
4. Akun `Nonaktif` → tolak `403` (audit `LOGIN_FAILED`, reason=inactive).
5. Kredensial valid + 2FA aktif → arahkan ke **challenge TOTP** (`/two-factor-challenge`); sesi belum terautentikasi penuh.
6. Kredensial valid + 2FA tidak wajib & belum aktif → autentikasi; jika `must_change_password` → paksa ganti password → paksa enrol 2FA bila peran wajib.
7. Sukses → **`session()->regenerate()`**, audit `LOGIN_SUCCESS`.
### 4.2 Enrolment TOTP (RFC 6238)
- **Wajib** untuk **Approver Kandidat, Manajer Job, Super Admin** (PRD §4.5; ROLES §8.1). Opsional untuk Staf Input & Asisten Manajer (D-R6).
- Alur: aktifkan 2FA → tampilkan QR (otpauth URI) + secret → **user WAJIB konfirmasi 1 kode** via `POST /user/confirmed-two-factor-authentication` (mengatasi caveat lock-out issue #201) → `two_factor_confirmed_at` terisi → tampilkan **8 recovery codes sekali saja**. Audit `TWOFA_SETUP`.
- Gate enrolment: peran wajib-2FA yang belum `two_factor_confirmed_at` → middleware paksa ke halaman enrol sebelum akses modul.
### 4.3 Challenge TOTP saat login
- Input 6 digit TOTP (window ±1 step toleransi clock-skew). Valid → audit `TWOFA_VERIFIED`, lanjut regenerate sesi. Invalid → audit `TWOFA_FAILED`; throttle challenge 5/15 menit (key terpisah).
- Alternatif: input **recovery code** → bila cocok, kode dikonsumsi (single-use), audit `TWOFA_RECOVERY_USED`.
### 4.4 Recovery codes
- **8 kode**, dibuat saat enrol, **encrypted at-rest** (cast `encrypted`, AES-256 via APP_KEY — konsisten dengan `two_factor_secret`), **single-use** (dihapus dari array saat dipakai).
- **Regenerasi** (`POST /user/two-factor-recovery-codes`): meng-invalidasi seluruh 8 kode lama, terbitkan 8 baru. Audit `TWOFA_SETUP` (detail: regenerate=true).
- Defense-in-depth: recovery code adalah jalur fallback bukan primer; peringatkan user menyimpan offline.
### 4.5 Step-up Re-Auth (per-aksi, TTL 5 menit)
> **Step-up ≠ 2FA login.** Step-up = re-autentikasi **password + TOTP ulang** sebelum aksi sensitif diterapkan, terlepas dari sesi 2FA aktif (PRD §4.6). `password.confirm` Fortify TIDAK dipakai (hanya password & 3 jam) → **middleware step-up kustom**.
- **Mekanisme:** sebelum mengeksekusi aksi sensitif, sistem meminta `password + kode TOTP`. Sukses → set token elevasi ber-scope aksi (mis. `stepup.{action}.{entity_id}`) dengan **TTL 5 menit, sekali pakai** untuk aksi itu. Audit `STEPUP_REAUTH` (detail result=success, action). Gagal → tolak, audit `STEPUP_REAUTH` (result=fail) / `STEPUP_FAILED`; ikut throttle.
- **Daftar aksi pemicu step-up (FINAL — selaras PRD Lampiran D + ROLES §8.2):**
	1. Ubah role / nonaktifkan akun user — **Super Admin**
	2. Setujui penutupan kontainer wawancara (irreversible) — **Manajer Job**
	3. Setujui pengeluaran kandidat — **Manajer Job**. Konteks Penempatan: **Cabut Penempatan** (status → `Dikeluarkan`, event `PLACEMENT_EXPEL`) memicu step-up; konteks Wawancara: keluarkan kandidat dari kontainer wawancara.
	4. Kelola lookup/config + master perusahaan (termasuk approve request) — **Super Admin**
	5. Anonimisasi PII kandidat (PRD §7.9) — **Super Admin**
- **TIDAK memicu step-up:** setuju/tolak kandidat, buat kontainer baru, link tamu, batch kirim penempatan, Approve/Reject/Cancel partisipasi penempatan, tambah/approve force-majeur, mengundurkan diri dari penempatan → cukup 2FA login + sesi aktif (selaras MODULE_PLACEMENT D-1).
> Semua pelaku step-up adalah peran **wajib 2FA**, sehingga TOTP selalu tersedia → model konsisten.
### 4.6 Ganti password & logout
- Login pertama: `must_change_password=true` → paksa ganti (PRD §6.1). Audit `PASSWORD_CHANGED`.
- Logout `POST /logout`: invalidate sesi + regenerate token. Audit `LOGOUT`.
## 5. API / Routes (Fortify + tambahan)
<table header-row="true">
<tr>
<td>Method</td>
<td>Route</td>
<td>Fungsi</td>
<td>Catatan</td>
</tr>
<tr>
<td>POST</td>
<td>`/login`</td>
<td>login</td>
<td>middleware `throttle` 5/15 mnt</td>
</tr>
<tr>
<td>POST</td>
<td>`/logout`</td>
<td>logout</td>
<td>audit LOGOUT</td>
</tr>
<tr>
<td>GET/POST</td>
<td>`/two-factor-challenge`</td>
<td>challenge TOTP/recovery</td>
<td>throttle terpisah</td>
</tr>
<tr>
<td>POST</td>
<td>`/user/two-factor-authentication`</td>
<td>aktifkan 2FA</td>
<td>Fortify</td>
</tr>
<tr>
<td>POST</td>
<td>`/user/confirmed-two-factor-authentication`</td>
<td>**konfirmasi kode** (anti lock-out)</td>
<td>wajib</td>
</tr>
<tr>
<td>DELETE</td>
<td>`/user/two-factor-authentication`</td>
<td>nonaktifkan 2FA</td>
<td>terlarang utk peran wajib-2FA</td>
</tr>
<tr>
<td>GET</td>
<td>`/user/two-factor-recovery-codes`</td>
<td>lihat recovery codes</td>
<td></td>
</tr>
<tr>
<td>POST</td>
<td>`/user/two-factor-recovery-codes`</td>
<td>regenerasi</td>
<td>invalidasi lama</td>
</tr>
<tr>
<td>POST</td>
<td>`/user/confirm-password` *(diganti)*</td>
<td>—</td>
<td>**diganti middleware step-up kustom** `stepup` (password + TOTP)</td>
</tr>
<tr>
<td>POST</td>
<td>`/user/password`</td>
<td>ganti password</td>
<td>policy min 12 + 3/4 kelas</td>
</tr>
</table>
## 6. Invariants
- **INV-1** Peran wajib-2FA (Approver Kandidat, Manajer Job, Super Admin) TIDAK boleh mengakses modul operasional tanpa `two_factor_confirmed_at`.
- **INV-2** Sesi penuh hanya terbentuk setelah password valid **dan** (bila 2FA aktif) TOTP/recovery valid.
- **INV-3** Aksi step-up TIDAK dieksekusi tanpa token elevasi valid (≤5 menit, scope aksi, sekali pakai).
- **INV-4** Recovery code single-use; tak dapat dipakai dua kali.
- **INV-5** User `status_akun = Nonaktif` tak pernah lolos login (PRD §5.1).
- **INV-6** Tamu BUKAN akun auth — tak masuk flow ini (GLOSSARY §1; → MODULE_GUEST_ACCESS).
- **INV-7** `session.regenerate()` dipanggil pada login sukses & saat elevasi step-up (anti fixation).
- **INV-8** Approve/Reject kandidat tak pernah meminta step-up (selaras A-1).
## 7. Integrasi RBAC (spatie/laravel-permission) — kontrak, bukan implementasi
- Auth menetapkan **identitas** (siapa user + sesi). **Otorisasi** (boleh-apa) milik spatie + Policy (ROLES D-R1/D-R2).
- Setelah login, guard memuat role & permission user; middleware modul memakai `can`/Policy. Auth hanya menyediakan `Auth::user()` terverifikasi.
- Step-up adalah **gate keamanan tambahan**, ortogonal terhadap permission: aksi tetap dicek izin RBAC dahulu, lalu token step-up.
- 6 role hardcode di-seed; Auth tidak membuat tipe role (PRD §4.2).
## 8. Edge Cases
- **Lock-out enrolment:** tanpa langkah konfirmasi, user bisa terkunci (issue #201) → konfirmasi kode wajib sebelum 2FA dianggap aktif.
- **Clock skew TOTP:** toleransi ±1 step (±30 dtk).
- **Hilang device + habis recovery code:** hanya **Super Admin** dapat reset 2FA user (reset → `two_factor_confirmed_at=null`, paksa enrol ulang). Aksi ini memicu step-up Super Admin (kategori kelola user). *(usul ditegaskan di ROLES — lihat §12)*
- **Idle 30 menit (PRD §4.4):** auto-logout; permintaan berikutnya minta login.
- **Race double-login:** sesi terbaru regenerate; tak ada status agregat domain yang berubah di sini.
- **Key rotation APP_KEY:** pakai `APP_PREVIOUS_KEYS` agar `two_factor_secret`/recovery tetap terdekripsi.
## 9. Audit Events (selaras skema audit terpusat — PRD Lampiran A & BUSINESS_RULES)
Skema dasar: `actor_id (nullable), action_type, entity_type, entity_id, detail (JSONB), ip (nullable), created_at` (PRD §5.1).
<table header-row="true">
<tr>
<td>Event (brief)</td>
<td>`action_type` kanonik</td>
<td>Status enum PRD</td>
<td>`detail` JSONB</td>
</tr>
<tr>
<td>login_success</td>
<td>`LOGIN_SUCCESS`</td>
<td>✅ ada</td>
<td>`{user_id}`; IP di kolom `ip`, tanpa email mentah</td>
</tr>
<tr>
<td>login_fail</td>
<td>`LOGIN_FAILED`</td>
<td>✅ ada</td>
<td>`{user_id|null, email_masked_or_fingerprint, reason, attempts_left}`; IP hanya di kolom `ip`</td>
</tr>
<tr>
<td>lockout</td>
<td>`LOGIN_LOCKED_OUT`</td>
<td>✅ ada (v0.3.1)</td>
<td>`{user_id|null, email_masked_or_fingerprint, locked_until}`; IP hanya di kolom `ip`</td>
</tr>
<tr>
<td>2fa_enroll</td>
<td>`TWOFA_SETUP`</td>
<td>✅ ada</td>
<td>`{regenerate:bool}`</td>
</tr>
<tr>
<td>2fa_verify</td>
<td>`TWOFA_VERIFIED`</td>
<td>✅ ada (v0.3.1)</td>
<td>`{user_id}` (atau detail di LOGIN_SUCCESS)</td>
</tr>
<tr>
<td>2fa_fail</td>
<td>`TWOFA_FAILED`</td>
<td>✅ ada (v0.3.1)</td>
<td>`{user_id, ip}`</td>
</tr>
<tr>
<td>recovery_used</td>
<td>`TWOFA_RECOVERY_USED`</td>
<td>✅ ada (v0.3.1)</td>
<td>`{user_id, codes_left}`</td>
</tr>
<tr>
<td>stepup_success</td>
<td>`STEPUP_REAUTH`</td>
<td>✅ ada</td>
<td>`{action, entity_type, entity_id, result:"success"}`</td>
</tr>
<tr>
<td>stepup_fail</td>
<td>`STEPUP_REAUTH` (result:fail) atau `STEPUP_FAILED`</td>
<td>✅ ada (v0.3.1)</td>
<td>`{action, result:"fail"}`</td>
</tr>
<tr>
<td>logout</td>
<td>`LOGOUT`</td>
<td>✅ ada</td>
<td>`{user_id}`</td>
</tr>
<tr>
<td>password_changed</td>
<td>`PASSWORD_CHANGED`</td>
<td>✅ ada</td>
<td>`{user_id, forced:bool}`</td>
</tr>
</table>
> **GAP audit:** PRD Lampiran A domain Auth hanya punya `LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT, TWOFA_SETUP, PASSWORD_CHANGED, STEPUP_REAUTH`. Event granular (lockout, 2fa_verify/fail, recovery_used, stepup_fail) **kini SUDAH ADA di PRD v0.3.1** (diterapkan 29-06-2026): kelima type granular ditambahkan ke enum `action_type` domain Auth di Lampiran A. Lihat §12.
## 10. Tech Choices — Rationale
- **Fortify > google2fa:** first-party, recovery codes + challenge + endpoint konfirmasi bawaan → paling sedikit kode kustom utk tim kecil; selaras TECH_VERSION_SEED & ROLES D-R3.
- **bcrypt cost 12 > argon2id:** bcrypt cost 12 memberi margin keamanan kuat dengan beban CPU terprediksi; argon2id opsional di VPS 4C/8G, tidak wajib MVP.
- **Step-up kustom > ****`password.confirm`****:** PRD menuntut password **+ TOTP** per-aksi; `password.confirm` hanya password & berbasis sesi 3 jam.
- **Throttle via driver ****`redis`****:** Redis co-located (PRD §9.6 v0.3.12); cukup untuk ±15 user.
## 11. Test Plan (handoff QA)
- **Login/lockout:** email adalah identifier tunggal; variasi case tetap akun/key throttle yang sama; 5 gagal → lock 15 mnt; akun Nonaktif ditolak; regenerate sesi saat sukses.
- **2FA enrol:** tanpa konfirmasi kode → 2FA belum aktif (anti lock-out); peran wajib-2FA dipaksa enrol.
- **Challenge:** TOTP valid/invalid; clock-skew ±1; recovery code single-use & tak bisa dipakai ulang.
- **Step-up:** aksi di daftar §4.5 minta password+TOTP; token 5 mnt kedaluwarsa; approve/reject kandidat TIDAK minta step-up (INV-8).
- **Password policy:** tolak <12 char atau <3 kelas; bcrypt cost 12 terverifikasi.
- **Audit:** user dikenal memakai `user_id`; gagal anonim memakai masked email/HMAC fingerprint; tidak ada email input mentah dan IP tidak diduplikasi di JSONB.
- **Session:** idle 30 mnt → auto-logout.
## 12. GAP PRD & Pertanyaan Terbuka
- **\[GAP audit\]** Enum Auth Lampiran A perlu addendum (lockout, 2fa_verify/fail, recovery_used, stepup_fail). Usulan: tambah type baru ATAU pakai `detail.result`. → **SUDAH DITERAPKAN ke PRD v0.3.1 (29-06-2026)**: 5 type ditambahkan ke enum `action_type` domain Auth (Lampiran A).
- **\[GAP ROLES\]** "Reset 2FA user" (device hilang + recovery habis) belum eksplisit di matriks ROLES; diasumsikan kewenangan **Super Admin** di bawah kategori kelola user (memicu step-up). Usul tegaskan di ROLES_AND_PERMISSIONS.
- **\[Konfirmasi\]** Daftar step-up mengikuti PRD/ROLES final (bukan brief). Sudah disetujui user 2026-06-29.
- **\[GAP-P1 — selaras MODULE_PLACEMENT\]** Cakupan step-up domain Penempatan dikonfirmasi: **hanya Cabut Penempatan** (`PLACEMENT_EXPEL`, status `Dikeluarkan`) yang memicu step-up (di bawah trigger #3 "pengeluaran kandidat"). Approve/Reject/Cancel, tambah/approve force-majeur, dan mengundurkan diri = rutin tanpa step-up. Selaras MODULE_PLACEMENT (final 2026-06-29) — GAP-P1 ditutup.
---
*Status: FINAL — Batch B aligned; selaras PRD Kakehashi v0.3.14 + ROLES_AND_PERMISSIONS (final) + GLOSSARY (final) + BUSINESS_RULES (audit). Keputusan A-1..A-7 disetujui user 2026-06-29.*
