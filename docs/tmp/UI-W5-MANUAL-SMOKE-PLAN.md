# UI-W5 Manual Smoke Plan (T8)

**Status:** PLAN — menunggu operator approve + prasyarat data  
**Tanggal:** 2026-08-06  
**Branch under test:** `ui-w5-placement`  
**Builder handoff:** `docs/tmp/UI-W5-BUILDER-FINAL-HANDOFF.md`  
**Working material only** — jangan edit `docs/kakehashi/`, jangan setup secret

---

## 1. Tujuan

Smoke browser end-to-end UI Placement (P1–P6 + queue Checker + GAP-4) memakai
**database + akun synthetic manual milik operator**, bukan production dan
bukan `kakehashi_test`.

| In scope | Out of scope |
| --- | --- |
| Login shell, list/detail Placement, Draft→submit→approve kontainer, GAP-4 cancel Aktif kosong, batch submit→approve (source Terkirim, tetap Sedang Dipakai), Force-Majeur, Selesai Kontrak/Resign/Expel (step-up), arsip otomatis | Guest public (Wave 6), domain rewrite, VPS deploy |
| Negative: self-approve, batch kandidat Tersedia, expel tanpa step-up, tombol archive | Re-audit W0–W4 penuh |
| Evidence + verdict smoke | Tag `wave-5-placement-complete` (domain, bukan UI) |

**Deliverable sesi smoke:**

1. `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md`
2. Evidence folder: `docs/tmp/ui-w5-manual-smoke-evidence/` (PNG sanitized)
3. Verdict: `PASS` | `PASS WITH NON-BLOCKING NOTES` | `FAIL` | `BLOCKED`

---

## 2. Aturan agen (WAJIB — secret & observation)

Pola sama dengan UI-W4 manual smoke. Agen manual browser **tidak pernah**
memegang secret.

### 2.1 Dilarang keras

| Larangan | Detail |
| --- | --- |
| Mengisi password | Field login, forced-password, step-up password |
| Mengisi TOTP / recovery | Field challenge 2FA, step-up TOTP, recovery code |
| Membaca credential pack | Jangan `cat`, attach, quote, paste ke chat |
| Screenshot sensitif | Jangan capture saat password/TOTP/QR/token terlihat |
| Meminta secret di chat | Jangan minta operator tempel secret di percakapan |
| Bypass 2FA / step-up | Jangan seed secret / inject token step-up |
| Production / DB non-manual | Hanya DB manual synthetic milik operator |

### 2.2 Protokol **STOP FOR OPERATOR INPUT**

Saat UI meminta **password**, **TOTP**, **recovery code**, atau secret lain:

1. **Hentikan** aksi agen (jangan `fill`/type/paste field rahasia).
2. Tulis di chat: `STOP FOR OPERATOR INPUT — <label akun> — <jenis>`.
3. Operator mengisi langsung di browser yang terlihat.
4. Agen tidak mengamati isi field.
5. Setelah `LANJUT`/`CONTINUE`, agen cek hanya hasil non-rahasia (URL, flash,
   badge, redirect).
6. Jika operator tidak bisa input → scenario **BLOCKED**, catat, jangan
   improvise.

---

## 3. Prasyarat data (operator)

- DB manual synthetic dengan: Asisten Manajer (Maker), Manajer Job (Checker),
  Super Admin (view-only), perusahaan aktif, kandidat `Disetujui` +
  `Sedang Dipakai` + source partisipasi wawancara `Siap Dikirim` pada kontainer
  wawancara **Aktif**, kandidat `Disetujui` + `Tersedia` untuk Force-Majeur,
  lookup `kategori_force_majeur` aktif.
- Kredensial login/TOTP dipegang operator — jangan pernah ditulis di repo.

---

## 4. Skenario smoke (P1–P6 + GAP-4)

### S1 — P1/P2 List & Detail (Maker, Checker, Super Admin)
1. Buka `/placements` sebagai ketiga peran → daftar kontainer + badge status.
2. Buka detail: perusahaan, partisipasi + badge `status_penempatan`.
3. Verifikasi tidak ada tombol mutasi pada kontainer Arsip/Dibatalkan.

### S2 — P3 Draft → submit → approve kontainer
1. Maker buat draft (`/placements/create`), simpan draft → tanpa kode/pending.
2. Ubah nama → perusahaan **tidak bisa** diubah (error `PC_COMPANY_IMMUTABLE`).
3. Submit → kode `P-YYYY-NNNNN`, status Menunggu Approval.
4. Checker approve di queue/detail → Aktif.

### S3 — GAP-4 cancel Aktif kosong
1. Kontainer Aktif tanpa partisipasi: Maker klik "Ajukan pembatalan kontainer".
2. Checker setujui → Dibatalkan. (Ulang dengan tolak + catatan → tetap Aktif.)
3. Kontainer yang pernah punya partisipan → tombol tidak muncul; bila dipaksa
   service menolak `PC_NOT_EMPTY`.

### S4 — P4 batch normal
1. Maker pada kontainer Aktif: picker hanya menampilkan
   **Siap Dikirim + Sedang Dipakai** (label eligible tidak menyebut Tersedia).
2. Isi visa/mulai/durasi per baris, submit → pending `PLACEMENT_BATCH`;
   source tetap Siap Dikirim.
3. Checker approve → partisipasi Bekerja, source Terkirim, availability tetap
   **Sedang Dipakai** (bukan Tersedia).
4. Negatif: kandidat `Tersedia` tidak muncul; batch >50 ditolak UI + service.

### S5 — P5 Force-Majeur
1. Maker buka "Tambah langsung / Force-Majeur" pada kontainer Aktif.
2. Pilih kandidat **Tersedia + Disetujui**, kategori + alasan **wajib**.
3. Submit → pending; kandidat tetap Tersedia.
4. Checker approve (tanpa step-up) → Bekerja + Sedang Dipakai; tolak →
   `FM_REJECTED` trail, kandidat tetap Tersedia.

### S6 — P6 status + arsip otomatis
1. Selesai Kontrak → langsung terminal, kandidat Tersedia.
2. Mengundurkan Diri → request + alasan; Checker approve/tolak tanpa step-up.
3. Dikeluarkan → request + alasan; Checker approve **wajib step-up**
   (password + TOTP), alasan 2 lapis.
4. Partisipan Bekerja terakhir terminal → kontainer otomatis **Arsip**
   (read-only, tanpa tombol archive manual).

### S7 — Negatif kunci
- Self-approve: Maker tidak bisa memutus pengajuannya sendiri (`APV_SELF`).
- Expel tanpa step-up: approve tidak berjalan (modal step-up muncul).
- 409 versi: buka 2 tab, mutasi tab basi → banner konflik + reload.

---

## 5. Bukti & verdict

- Screenshot sanitized per langkah (tanpa secret) ke
  `docs/tmp/ui-w5-manual-smoke-evidence/`.
- Ringkasan hasil per skenario di `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md`.
- Verdict: `PASS` | `PASS WITH NON-BLOCKING NOTES` | `FAIL` | `BLOCKED`.
