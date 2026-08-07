# UI-W6 Manual Smoke — Agent Handoff (EXECUTE)

**Status:** READY — menunggu operator approval sebelum eksekusi
**Task ID:** `UI-W6-T9-MANUAL-SMOKE-01`
**Mode:** Agent manual browser (Vibium / visible Chrome) — **bukan** Reviewer, **bukan** Builder
**Plan acuan:** playbook `docs/kakehashi/playbook/09_WAVE_6_GUEST.md` + `docs/kakehashi/modules/MODULE_GUEST_ACCESS.md` (matriks perilaku) + konteks build `docs/tmp/UI-W6-ORCHESTRATION-FINAL-REPORT.md`
**Reviewer:** percakapan terpisah — membaca `UI-W6-MANUAL-SMOKE-RESULTS.md` + evidence setelah agent selesai, lalu memberi verdict final

Dokumen ini **standalone** untuk dieksekusi agent manual smoke. Baca seluruhnya
sebelum klik apa pun.

---

## 0. Perintah pertama (wajib)

1. Baca dokumen ini sampai habis.
2. Restate singkat di chat: urutan fase, akun, aturan secret/token, deliverable.
3. Jalankan **§3 Preflight**.
4. Jika preflight **BLOCKED** → tulis partial RESULTS, **stop** (jangan
   improvise schema/user/secret).
5. Jika preflight **OK** → mulai **§6** dari **Fase DP** (Data Prep), lalu S1.

**Jangan** mengubah `app/`, `app-modules/`, `routes/`, `docs/kakehashi/`,
Build Log, atau credential pack. Agent hanya menulis dua deliverable di §4.

---

## 1. Build & environment

| Item | Nilai |
| --- | --- |
| Branch | `ui-w6-guest` |
| HEAD expected | `22cf26a` (atau tip `ui-w6-guest` setara ke atas) |
| Base URL | `http://127.0.0.1:8000` — **agent menyalakan server sendiri** (lihat §3.1 PF-01) |
| Database | **`kakehashi_r3_manual` only** — bukan production, bukan `kakehashi_test` |
| Browser | Visible — operator harus bisa mengetik secret di window yang sama |
| Credential pack | Operator-only — **JANGAN DIBUKA AGEN**; **jangan rotate** |

### 1.1 Email synthetic (boleh diisi agen — non-secret)

| Label | Email (synthetic) | Role |
| --- | --- | --- |
| ASSISTANT-A | `assistant-a@r3-manual.example.com` | Asisten Manajer — **Maker** (request link Tamu) |
| JOB-MANAGER-A | `job-manager-a@r3-manual.example.com` | Manajer Job — **Checker** (approve/reject link); password/TOTP operator-only |
| ADMIN-A | `admin-a@r3-manual.example.com` | Super Admin — view-only opsional |

**Password & TOTP: SELALU operator.** Agen boleh isi **email** di atas saja.

---

## 2. ATURAN SECRET & TOKEN — tidak bisa dilanggar

### 2.1 Dilarang

- Mengisi / paste **password**, **TOTP**, **recovery code**
- Membuka / `cat` / attach credential pack
- Meminta secret di chat
- **Mencatat token guest mentah** (URL `/guest/{token}` mengandung token) ke
  chat, report, atau commit — cukup catat “URL publik tampil sekali”
- Screenshot saat password, TOTP, QR, recovery, atau **token guest mentah
  terlihat** (screenshot panel token hanya bila operator mask/blur, atau skip
  PNG dan tulis observasi teks)
- Bypass step-up / inject session elevation
- Memakai DB selain `kakehashi_r3_manual`

### 2.2 Protokol wajib: STOP FOR OPERATOR INPUT

Saat UI butuh secret (login password, TOTP, recovery):

```
STOP FOR OPERATOR INPUT — <LABEL AKUN> — <login password | TOTP | recovery>
```

Lalu:

1. **Jangan** `fill` field rahasia.
2. **Jangan** baca value field (no DOM eval pada password/totp).
3. Tunggu operator isi di browser + balasan **`LANJUT`** / **`CONTINUE`**.
4. Lanjut cek **hanya** URL, flash, badge, ada/tidaknya modal, teks non-rahasia.
5. Jika operator tidak bisa → scenario **BLOCKED**, catat, jangan tebak.

### 2.3 Yang boleh diisi agen

- Email dari §1.1
- Label link Tamu, tanggal kadaluarsa (masa depan), kode tambahan (teks bisnis
  seperti `W6TEST`), catatan approve/reject
- Navigasi, klik tombol non-secret, input kode tambahan di halaman Tamu
- **Membuka URL publik Tamu** di browser boleh — asal nilai URL (token) tidak
  ditulis ke chat/report/screenshot

---

## 3. Preflight (jalankan dulu)

### 3.1 Cek yang agen / operator konfirmasi (non-secret)

| ID | Cek | Jika gagal |
| --- | --- | --- |
| PF-01 | **Agent menyalakan server sendiri**: `DB_DATABASE=kakehashi_r3_manual php artisan serve --host=127.0.0.1 --port=8000` di background, lalu `GET /login` → 200 | BLOCKED env — cek log server |
| PF-02 | Operator konfirmasi app = branch `ui-w6-guest` + DB `kakehashi_r3_manual` | BLOCKED env |
| PF-03 | Tabel Guest ada (`guest_link`, `guest_access_log`) | BLOCKED GAP-C — minta operator migrate |
| PF-04 | User ASSISTANT-A, JOB-MANAGER-A (2FA), ADMIN-A ada + role benar | BLOCKED GAP-A |
| PF-05 | ≥1 kontainer Wawancara **Aktif** dengan ≥2 partisipan (dicapai lewat **Fase DP**) | BLOCKED sebelum S2/S3 |
| PF-06 | ≥2 kandidat `Disetujui` + `Tersedia` untuk pull bila kontainer kosong (id 26–29 tersedia dari smoke W5) | BLOCKED sebelum DP |
| PF-07 | Operator **konfirmasi** credential pack tersedia (jangan rotate) | BLOCKED — minta operator siap mengetik secret |
| PF-08 | `vibium is-installed` OK; base URL reachable | BLOCKED env |
| PF-09 | Header route Tamu: `curl -sI http://127.0.0.1:8000/guest/token-tidak-valid` → status 4xx + `Cache-Control: no-store` + security headers (HSTS/XFO/XCTO/Referrer-Policy/CSP) | Catat hasil; header diverifikasi ulang Reviewer |

### 3.2 Operator checklist (bukan tugas agen secret)

```text
□ Branch ui-w6-guest (agent yang menyalakan server; operator tidak perlu serve)
□ php artisan migrate --force (jangan fresh wipe tanpa re-seed R3 sadar)
□ Import TOTP JOB-MANAGER-A ke authenticator
□ Siap mengetik password/TOTP saat STOP (pack tersedia; jangan rotate)
□ Data Prep dikerjakan agent lewat UI (fase DP)
□ Siap di depan browser untuk setiap STOP
□ Beri agen: base URL + email akun (bukan password)
```

---

## 4. Deliverable (satu-satunya write yang diizinkan)

| Path | Isi |
| --- | --- |
| `docs/tmp/UI-W6-MANUAL-SMOKE-RESULTS.md` | Hasil per skenario + temuan + evidence map |
| `docs/tmp/ui-w6-manual-smoke-evidence/*.png` | Screenshot sanitized (tanpa secret/token) |

Buat folder evidence jika belum ada. **Jangan** commit secret. **Jangan** tulis
verdict final sendiri — verdict final dari Reviewer (percakapan terpisah).

### 4.1 Template RESULTS (salin ke file saat mulai)

```markdown
# UI-W6 Manual Smoke Results

- Task: UI-W6-T9-MANUAL-SMOKE-01
- Date:
- Branch / HEAD:
- Base URL:
- DB: kakehashi_r3_manual
- Verdict: (diisi Reviewer, bukan agent)

## Preflight
| ID | Result | Note |
| PF-01 | | |
| ... | | |

## Scenarios
| ID | Result | Evidence | Note |
| DP-1 | | | |
| ... | | | |

## Findings
| ID | Severity | Description | Evidence |

## Handoff
```

---

## 5. Data bisnis synthetic (aman diisi agen)

Gunakan string unik per run (ganti `R1` bila perlu):

| Field | Nilai contoh |
| --- | --- |
| Label link Tamu | `W6 Smoke Guest Link R1` |
| Kadaluarsa | tanggal **masa depan** |
| Kode tambahan (link berkode) | `W6TEST` |
| Catatan approve | `W6 smoke setuju link` |
| Catatan reject | `W6 smoke tolak link` |

---

## 6. Matriks eksekusi (urutan wajib)

**Hasil per baris:** `PASS` | `FAIL` | `BLOCKED` | `SKIP` (+ alasan).

### Fase DP — Data Prep (wajib sebelum S1)

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **DP-1** | ASSISTANT-A | Login → Jobs → cek ada kontainer Wawancara **Aktif** dengan ≥2 partisipan | Ada (atau tarik ≥2 kandidat `Disetujui + Tersedia` via UI bila kosong) | `DP-1-container-aktif.png` |
| **DP-2** | — | Catat nama/kode kontainer Aktif yang dipakai (non-secret) | — | — |

Catatan: jangan ubah data langsung di DB; semua lewat UI.

### Fase 0 — Login

| ID | Aktor | Langkah agen | Expected | Secret |
| --- | --- | --- | --- | --- |
| **P0** | — | `go` `/login` | Form login | — |
| **P1** | ASSISTANT-A | Isi email Maker → klik Masuk | — | **STOP — ASSISTANT-A — login password** (+ TOTP jika challenge) |
| **P1b** | — | Setelah `LANJUT` | Shell; nav **Wawancara** ada | — |
| **P2** | — | Logout | Kembali `/login` | — |
| **P3** | JOB-MANAGER-A | Isi email Checker → submit | — | **STOP — JOB-MANAGER-A — login password** (+ TOTP) |
| **P3b** | — | Setelah `LANJUT` | Shell; nav **Wawancara** ada | — |

### S1 — Link management internal (Maker request / Checker decide)

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S1-01** | Maker | Detail kontainer Aktif → request link Tamu (label + expiry future, **tanpa kode**) | Pending; **tidak ada token/URL** | `S1-01-request.png` |
| **S1-02** | Maker | Request link #2 (**dengan kode** `W6TEST`) | Pending | `S1-02-request-code.png` |
| **S1-03** | Checker | Approve link #1 + catatan | Link **Aktif**; panel menampilkan **URL publik sekali** (`/guest/{token}`) + tombol salin | `S1-03-approved-url.png` (mask/blur token, atau tulis observasi teks) |
| **S1-04** | — | Reload halaman | Token/URL **hilang** (tidak dirender ulang) | `S1-04-after-reload.png` |
| **S1-05** | Checker | Approve link #2 (berkode) | Aktif; URL sekali | catatan teks |
| **S1-06** | Checker | Reject link lain (request #3) + catatan | Tidak ada token/baris link aktif | `S1-06-rejected.png` |

**FAIL jika** token/URL muncul sebelum approve, atau tetap tampil setelah reload.

### S2 — Gate Tamu: token valid (tanpa kode & berkode)

> Buka URL Tamu di window/incognito visible (boleh dibuka agen; nilai URL tidak
> boleh masuk chat/report/screenshot). Untuk link berkode, agen boleh mengetik
> kode `W6TEST` (kode = teks bisnis, bukan secret login).

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S2-01** | Buka `/guest/{token}` link #1 (tanpa kode) | Langsung halaman list G2 (JP) | `S2-01-list-ok.png` |
| **S2-02** | Buka `/guest/{token}` link #2 (berkode) | Form kode JP tampil | `S2-02-code-form.png` |
| **S2-03** | Input kode salah (`WRONG`) | Tolak generik, tanpa alasan spesifik | `S2-03-code-wrong.png` |
| **S2-04** | Input kode benar (`W6TEST`) | Masuk list G2 | `S2-04-list-after-code.png` |

### S3 — G2 list pseudonim (JP-only, anti-PII)

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S3-01** | Periksa isi list | Identifier = **NIK `K-YYYY-NNNNN`**; umur `{n}歳`, `男/女`, kewarganegaraan, level JP — **tanpa nama, foto, email, telepon** | `S3-01-list-fields.png` |
| **S3-02** | Header halaman | Nama perusahaan tujuan (`nama_ja`), tanggal wawancara, jenis wawancara; bahasa **Jepang** | (termasuk S3-01) |
| **S3-03** | Coba sort/filter PII (nama/foto/perusahaan riwayat) | Tidak tersedia / diabaikan (allowlist aman saja) | `S3-03-no-pii-filter.png` |

**FAIL jika** G2 memuat nama/foto/email/telepon/alamat/field HIDE lain.

### S4 — G3 detail whitelist

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S4-01** | Klik salah satu kandidat | Detail JP: Nama Alphabet + Katakana, umur, gender, kewarganegaraan, level JP/Inggris, riwayat kerja (nama perusahaan + bidang + durasi), riwayat pendidikan (lembaga), dokumen **hanya `is_shareable`** | `S4-01-detail.png` |
| **S4-02** | Foto kandidat | Foto tampil via route `guest.photo` (bukan URL R2 mentah) | `S4-02-photo.png` |
| **S4-03** | Cek field HIDE | Tidak ada email/telepon/Line/alamat/tanggal lahir mentah/IQ/MTK/psikotes/data keluarga/imigrasi | (termasuk S4-01) |

**FAIL jika** field HIDE muncul, atau dokumen non-shareable terlihat.

### S5 — Keamanan & negatif

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S5-01** | Buka `/guest/<token-acak>` | 404 **generik** JP; tidak membedakan alasan | `S5-01-invalid.png` |
| **S5-02** | 11× token invalid cepat dari 1 IP | **429** (rate limit lapis 1) | `S5-02-throttled.png` |
| **S5-03** | Kode salah 5× pada link berkode (link #2) | **Lockout** — penolakan sampai 15 menit; pesan generik | `S5-03-lockout.png` |
| **S5-04** | Kode benar setelah lockout | Ditolak (masih lock) | catatan teks |
| **S5-05** | Token link #1 dipakai pada container lain (navigasi `/guest/candidates` tanpa scope) | Hanya list kontainer token; tidak ada pemilih kontainer; tidak bocor kontainer lain | `S5-05-scope.png` |

> Setelah S5-03, S2-04 tidak bisa diulang sampai lockout selesai — urutkan S2
> penuh dulu sebelum S5, atau gunakan link baru bila perlu.

### S6 — Token-once & konsistensi

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S6-01** | Buka kembali link #1 (reload) | Tetap bisa (sesi token valid), list sama | `S6-01-reload.png` |
| **S6-02** | Cek `Cache-Control: no-store` di response halaman Tamu | Header ada (via curl/devtools; diverifikasi ulang Reviewer) | catatan teks |

### S7 — Status link (kadaluarsa/ditutup)

| ID | Langkah | Expected | Evidence |
| --- | --- | --- | --- |
| **S7-01** | Link dengan expiry masa lalu (buat jika UI mengizinkan) atau container ditutup | Halaman tolak **generik JP**, body identik dengan token invalid setelah normalisasi | `S7-01-expired-closed.png` |
| **S7-02** | Tutup kontainer Aktif yang link-nya aktif (Maker request close → Checker approve, pola W4/W5) | Link mati; buka token → tolak generik | `S7-02-closed-denied.png` |

**FAIL jika** halaman tolak membedakan alasan (expired vs closed vs invalid) pada
response yang bisa diamati.

---

## 7. Urutan klik ringkas (happy path ideal)

```
DP-1 → P0 → STOP login ASSISTANT-A → P1b
→ S1-01 (request tanpa kode) → S1-02 (request berkode)
→ logout → STOP login JOB-MANAGER-A → S1-03 (approve #1, catat URL sekali)
→ S1-04 (reload → token hilang) → S1-05 (approve #2) → S1-06 (reject #3)
→ buka URL #1 di window Tamu → S2-01 → S3-01..S3-03
→ S4-01..S4-03 (detail + foto)
→ buka URL #2 → S2-02..S2-04 → (lockout test di S5-03/04 dengan link lain bila perlu)
→ S5-01..S5-02 (invalid + 429) → S5-05 (scope)
→ S6-01..S6-02 → S7-01..S7-02 (tutup kontainer → tolak generik)
→ tulis RESULTS + handoff Reviewer
```

Ganti session dengan **logout + clear cookies** antar role bila session campur.

---

## 8. Verdict rules (untuk Reviewer — agent hanya menyiapkan bahan)

| Verdict | Kapan |
| --- | --- |
| **PASS** | Semua skenario path inti PASS; 0 temuan material |
| **PASS WITH NON-BLOCKING NOTES** | Path inti PASS; UX/minor notes only |
| **FAIL** | G2 memuat nama/foto/PII; field HIDE di response; token mentah tampil/log; guest bisa keluar scope kontainer; link mati padahal aktif; lockout/rate limit tidak jalan |
| **BLOCKED** | Env/GAP; secret tidak bisa diisi operator; preflight gagal |

**Path inti minimum agar hasil “berguna” (non-BLOCKED):**

1. S1 request → approve → token-once (muncul sekali, hilang setelah reload)
2. S2/S3 gate valid + G2 pseudonim JP (NIK saja)
3. S4 G3 detail whitelist + foto scoped
4. S5 token invalid → 404 generik + rate limit 429
5. S7 link mati saat kontainer ditutup, tolak generik

---

## 9. Stop conditions (langsung henti + laporkan)

- Server mati di tengah tes → **agent nyalakan ulang sendiri** (perintah PF-01),
  lanjut dari langkah terakhir yang terekam; hanya BLOCKED jika server tidak
  mau hidup kembali
- Secret diminta di chat → tolak, pakai STOP protocol
- Token guest mentah terbaca/tercatat agen → hapus jejak, catat incident, stop
- DB / branch salah
- Tabel Guest hilang / migrasi belum jalan
- G2 memuat nama/foto/PII → **FAIL**, catat bukti, tetap lanjut skenario lain
- Field HIDE muncul di response → **FAIL**, catat bukti
- Guest bisa akses kontainer lain → **FAIL**, stop (temuan keamanan)
- Route Guest bocor ke locale selain JP / halaman internal → catat, lanjut

---

## 10. Setelah selesai

1. Isi `docs/tmp/UI-W6-MANUAL-SMOKE-RESULTS.md` lengkap (tanpa verdict final).
2. Pastikan evidence tanpa secret/token.
3. Serahkan ke Reviewer (percakapan terpisah):
   - `UI-W6-MANUAL-SMOKE-RESULTS.md`
   - `docs/tmp/ui-w6-manual-smoke-evidence/`
4. Reviewer memberi verdict final (PASS | PASS WITH NON-BLOCKING NOTES | FAIL |
   BLOCKED). Agent **tidak** membuat tag git, **tidak** edit BUILD_LOG,
   **tidak** edit authority/docs kakehashi.

---

## 11. Prompt salin-tempel (jika chat baru)

```text
APPROVED — START UI-W6 MANUAL SMOKE.

Anda adalah agent manual smoke. Ikuti PERSIS:
docs/tmp/UI-W6-MANUAL-SMOKE-AGENT-HANDOFF.md
(acuan: docs/kakehashi/playbook/09_WAVE_6_GUEST.md + MODULE_GUEST_ACCESS.md)

Secret: JANGAN isi password/TOTP/recovery; gunakan "STOP FOR OPERATOR INPUT — …";
tunggu operator + LANJUT. Jangan buka credential pack. Jangan screenshot
password/TOTP/token guest mentah; jangan tulis nilai URL /guest/{token} ke
chat/report.

DB: kakehashi_r3_manual. Branch: ui-w6-guest.
Maker email: assistant-a@r3-manual.example.com
Checker email: job-manager-a@r3-manual.example.com (password/TOTP operator-only)

Preflight §3 dulu. Tulis:
docs/tmp/UI-W6-MANUAL-SMOKE-RESULTS.md
docs/tmp/ui-w6-manual-smoke-evidence/

Jangan ubah app code, routes/, atau docs/kakehashi/. Jangan beri verdict final.
```

---

## 12. Referensi cepat

| Dokumen | Kapan dibaca |
| --- | --- |
| Dokumen **ini** | Eksekusi utama |
| `09_WAVE_6_GUEST.md` | DoD + stop condition |
| `MODULE_GUEST_ACCESS.md` | Perilaku gate/G2/G3/rate limit/header |
| `UI-W6-ORCHESTRATION-FINAL-REPORT.md` | Konteks build (route, non-blocking notes) |

---

**End handoff. Eksekusi dimulai setelah preflight §3.**
