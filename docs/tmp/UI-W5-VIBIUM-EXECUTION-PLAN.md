# UI-W5 Vibium Execution Plan (Manual Smoke — DB R3 existing)

**Status:** PLAN — menunggu operator approve + input secret di browser  
**Tanggal:** 2026-08-06  
**Branch under test:** `ui-w5-placement` @ `bc4f83b`  
**Mode:** Vibium (Chrome for Testing visible) — agen + operator kolaborasi  
**Database:** **`kakehashi_r3_manual` only** (bukan production, bukan `kakehashi_test`)  
**Akun:** R3 existing (synthetic) — lihat §3  
**Working material only** — jangan edit `docs/kakehashi/`, jangan setup secret

---

## 1. Tujuan

Smoke browser end-to-end UI Placement (P1–P6 + queue Checker + GAP-4) memakai
akun yang **sudah ada** di DB `kakehashi_r3_manual`, dengan Chrome terlihat
supaya operator bisa mengetik password/TOTP langsung di browser.

| In scope | Out of scope |
| --- | --- |
| Login, P1 list, P2 detail, P3 draft→submit→approve, GAP-4 cancel Aktif kosong, P4 batch submit→approve, P5 Force-Majeur, P6 Selesai/Resign/Expel + arsip otomatis | Guest public (Wave 6), domain rewrite, VPS deploy |
| Negatif: self-approve, batch kandidat Tersedia, expel tanpa step-up, 409 versi | Re-audit W0–W4 penuh; re-audit code UI-W5 (sudah Builder → Reviewer) |

**Deliverable sesi:** `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md` + evidence
folder `docs/tmp/ui-w5-manual-smoke-evidence/` + verdict
(PASS | PASS WITH NON-BLOCKING NOTES | FAIL | BLOCKED).

---

## 2. Lingkungan

| Item | Nilai |
| --- | --- |
| Base URL | `http://127.0.0.1:8000` (perlu `php artisan serve` jalan) |
| DB | `kakehashi_r3_manual` (PostgreSQL lokal, user migrator via `.env.migrator`) |
| Browser | Visible — operator wajib bisa mengetik secret di window yang sama |
| Credential pack | `/tmp/kakehashi-r3-manual-fixture/credentials.txt` — **operator-only, agen JANGAN buka** |

## 3. Akun R3 existing (email synthetic — boleh diisi agen)

| Label | Email | Role | Dipakai untuk |
| --- | --- | --- | --- |
| ASSISTANT-A | `assistant-a@r3-manual.example.com` | Asisten Manajer | Maker (placement.execute) |
| JOB-MANAGER-A | `job-manager-a@r3-manual.example.com` | Manajer Job | Checker (placement.review) |
| ADMIN-A | `admin-a@r3-manual.example.com` | Super Admin | view-only P1/P2 |

Password & TOTP: **SELALU operator**. Agen hanya mengisi email.

## 4. Aturan secret (tidak bisa dilanggar)

1. Agen **tidak pernah** mengisi/paste/membaca password, TOTP, recovery.
2. Saat UI butuh secret → tulis `STOP FOR OPERATOR INPUT — <LABEL> — <jenis>`,
   tunggu operator isi di browser + `LANJUT`/`CONTINUE`.
3. Agen cek hanya hasil non-rahasia: URL, flash, badge, modal, redirect.
4. Screenshot dilarang saat password/TOTP/QR/token mentah terlihat.
5. Jangan bypass step-up (tanpa inject session token).
6. DB hanya `kakehashi_r3_manual`; jangan buka credential pack.

---

## 5. Preflight (P0) — perlu operator approve dulu

1. **Migrasi pending** pada `kakehashi_r3_manual` (2 file placement):
   `php artisan migrate` dengan `DB_DATABASE=kakehashi_r3_manual`.
   Non-destruktif (hanya menambah tabel), perlu `DB_MIGRATOR_*` dari
   `.env.migrator`. → **minta OK operator** (DB manual milik operator).
2. **Data batch ready**: belum ada partisipasi `Siap Dikirim`. Promosi
   synthetic via UI Jobs (Lulus → Proses Dokumen → Siap Dikirim) memakai
   Maker, atau operator setujui seeder fixture. Kandidat `SEDANG_DIPAKAI`
   sudah ada (2).
3. `php artisan serve` jalan di `127.0.0.1:8000`.
4. `vibium is-installed` OK; base URL reachable (`GET /login` → 200).
5. Akun status `Aktif` diverifikasi (sudah: semua R3 `Aktif` kecuali
   staff-inactive — jangan dipakai).

Jika preflight BLOCKED → tulis partial RESULTS, stop, jangan improvise
schema/user/secret.

---

## 6. Skenario smoke (Vibium)

### S1 — P1/P2 List & Detail
- Login ASSISTANT-A (email agen, password/TOTP operator).
- `/placements` → daftar + badge status; buat detail dari kontainer yang ada.
- Admin-A view-only: list + detail OK, tanpa tombol mutasi.

### S2 — P3 Draft → submit → approve kontainer
- Maker: `/placements/create`, isi nama + perusahaan (dropdown), simpan draft.
- Edit nama; coba ubah perusahaan → error `PC_COMPANY_IMMUTABLE`.
- Submit → kode `P-YYYY-NNNNN`, status Menunggu Approval.
- Checker (JOB-MANAGER-A): queue `/placements/review` → approve → Aktif.

### S3 — GAP-4 cancel Aktif kosong
- Kontainer Aktif tanpa partisipan: Maker "Ajukan pembatalan kontainer".
- Checker setujui → Dibatalkan. Ulang dengan tolak + catatan → tetap Aktif.
- Kontainer berpartisipan → tombol tidak muncul.

### S4 — P4 batch normal
- Maker pada kontainer Aktif: picker eligible (label Siap Dikirim +
  Sedang Dipakai; kandidat Tersedia tidak tampil).
- Isi visa/mulai/durasi, submit → pending; source tetap Siap Dikirim.
- Checker approve → Bekerja + Terkirim, availability tetap Sedang Dipakai.
- Negatif: >50 ditolak; kandidat Tersedia tidak bisa dipilih.

### S5 — P5 Force-Majeur
- Maker panel "Tambah langsung / Force-Majeur": kandidat Tersedia+Disetujui,
  kategori + alasan wajib; submit → pending, kandidat tetap Tersedia.
- Checker approve (tanpa step-up) → Bekerja + Sedang Dipakai.

### S6 — P6 status + arsip otomatis
- Selesai Kontrak → terminal langsung.
- Mengundurkan Diri → request + alasan; Checker approve/tolak tanpa step-up.
- Dikeluarkan → request + alasan; Checker approve **wajib step-up**
  (password+TOTP operator); tolak + catatan OK.
- Partisipan Bekerja terakhir terminal → kontainer **Arsip** read-only,
  tanpa tombol archive manual.

### S7 — Negatif kunci
- Self-approve: Maker tidak bisa decide pengajuannya (`APV_SELF`).
- Expel tanpa step-up: modal step-up muncul, tidak ada mutasi.
- 409 versi: dua tab, mutasi tab basi → banner konflik + reload.

---

## 7. Evidence & verdict

- Screenshot sanitized per langkah ke `docs/tmp/ui-w5-manual-smoke-evidence/`.
- Ringkasan hasil per skenario di `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md`.
- Verdict: `PASS` | `PASS WITH NON-BLOCKING NOTES` | `FAIL` | `BLOCKED`.

## 8. Stop conditions

- Butuh secret pack / operator tidak bisa input → BLOCKED.
- DB bukan `kakehashi_r3_manual`, server mati, preflight gagal → BLOCKED.
- Ingin ubah domain/docs/kakehashi → stop, lapor.

---

## 9. Yang saya butuhkan dari operator untuk mulai

1. OK untuk menjalankan **2 migrasi placement pending** di
   `kakehashi_r3_manual` (+ promosi data `Siap Dikirim` synthetic).
2. Browser Vibium visible dibuka, operator siap mengetik password/TOTP
   saat `STOP FOR OPERATOR INPUT`.
3. Server `php artisan serve` di `127.0.0.1:8000` (boleh saya nyalakan).
