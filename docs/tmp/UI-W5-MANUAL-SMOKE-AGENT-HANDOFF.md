# UI-W5 Manual Smoke — Agent Handoff (EXECUTE)

**Status:** READY — menunggu operator approval sebelum eksekusi
**Task ID:** `UI-W5-T9-MANUAL-SMOKE-01`
**Mode:** Agent manual browser (Vibium / visible Chrome) — **bukan** Reviewer, **bukan** Builder
**Plan acuan:** `docs/tmp/UI-W5-VIBIUM-EXECUTION-PLAN.md` (matriks utama), `docs/tmp/UI-W5-MANUAL-SMOKE-PLAN.md` (detail)
**Reviewer:** percakapan terpisah — membaca `UI-W5-MANUAL-SMOKE-RESULTS.md` + evidence setelah agent selesai, lalu memberi verdict final

Dokumen ini **standalone** untuk dieksekusi agent manual smoke. Baca seluruhnya
sebelum klik apa pun.

---

## 0. Perintah pertama (wajib)

1. Baca dokumen ini sampai habis.
2. Restate singkat di chat: urutan fase, akun, aturan secret, deliverable.
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
| Branch | `ui-w5-placement` |
| HEAD expected | `c86ff67` (atau tip `ui-w5-placement` setara ke atas) |
| Base URL | `http://127.0.0.1:8000` — **agent menyalakan server sendiri** (lihat §3.1 PF-01) |
| Database | **`kakehashi_r3_manual` only** — bukan production, bukan `kakehashi_test` |
| Browser | Visible — operator harus bisa mengetik secret di window yang sama |
| Credential pack | Operator-only: `/tmp/kakehashi-r3-manual-fixture/credentials.txt` — **JANGAN DIBUKA AGEN** |

> [!WARNING]
> Credential pack **sudah disimpan operator** — **JANGAN rotate**, jangan
> re-generate, jangan ubah akun/password. File pack boleh tidak ada di mesin
> ini; yang penting operator **siap mengetik** password/TOTP saat STOP.
> Agen tidak pernah membuka isi pack.

### 1.1 Email synthetic (boleh diisi agen — non-secret)

| Label | Email (synthetic) | Role |
| --- | --- | --- |
| ASSISTANT-A | `assistant-a@r3-manual.example.com` | Asisten Manajer — **Maker** |
| JOB-MANAGER-A | `job-manager-a@r3-manual.example.com` | Manajer Job — **Checker**; password/TOTP tetap operator-only |
| ADMIN-A | `admin-a@r3-manual.example.com` | Super Admin — view-only P1/P2 |
| STAFF-A | `staff-a@r3-manual.example.com` | Staf Input — hanya Data Prep DP-1 (submit kandidat Draft) |
| APPROVER-A | `approver-a@r3-manual.example.com` | Approver Kandidat — hanya Data Prep DP-1 (approve) |

**Password & TOTP: SELALU operator.** Agen boleh isi **email** di atas saja.
Jangan pakai akun STAFF-FORCED / STAFF-LOCK / STAFF-INACTIVE.

---

## 2. ATURAN SECRET — tidak bisa dilanggar

### 2.1 Dilarang

- Mengisi / paste **password**, **TOTP**, **recovery code**
- Membuka / `cat` / attach credential pack
- Meminta secret di chat
- Screenshot saat password, TOTP, QR, recovery, atau token mentah terlihat
- Bypass step-up / inject session elevation
- Memakai DB selain `kakehashi_r3_manual`

### 2.2 Protokol wajib: STOP FOR OPERATOR INPUT

Saat UI butuh secret:

```
STOP FOR OPERATOR INPUT — <LABEL AKUN> — <login password | TOTP | step-up password+TOTP | recovery>
```

Lalu:

1. **Jangan** `fill` field rahasia.
2. **Jangan** baca value field (no DOM eval pada password/totp).
3. Tunggu operator isi di browser + balasan **`LANJUT`** / **`CONTINUE`**.
4. Lanjut cek **hanya** URL, flash, badge, ada/tidaknya modal, teks non-rahasia.
5. Jika operator tidak bisa → scenario **BLOCKED**, catat, jangan tebak.

### 2.3 Yang boleh diisi agen

- Email dari §1.1
- Nama kontainer, deskripsi, perusahaan (dropdown), visa/mulai/durasi per baris
- Kategori Force-Majeur + alasan (teks bisnis)
- Alasan resign/expel, catatan reject/approve (teks bisnis)
- Navigasi, search kandidat, klik tombol non-secret

---

## 3. Preflight (jalankan dulu)

### 3.1 Cek yang agen / operator konfirmasi (non-secret)

| ID | Cek | Jika gagal |
| --- | --- | --- |
| PF-01 | **Agent menyalakan server sendiri**: `DB_DATABASE=kakehashi_r3_manual php artisan serve --host=127.0.0.1 --port=8000` di background, lalu `GET /login` → 200 | BLOCKED env — cek log server |
| PF-02 | Operator konfirmasi app = branch `ui-w5-placement` + DB `kakehashi_r3_manual` | BLOCKED env |
| PF-03 | Tabel Placement ada (`placement_containers`, `placement_participants`) | BLOCKED GAP-C — minta operator `php artisan migrate` (2 file Placement pending, non-destruktif) |
| PF-04 | User ASSISTANT-A, JOB-MANAGER-A (2FA enrolled), ADMIN-A ada + role benar | BLOCKED GAP-A |
| PF-05 | ≥2 partisipasi wawancara **unfrozen** `Siap Dikirim` pada kontainer Jobs Aktif (dicapai lewat **fase DP-2** — agent promosi via UI Jobs) | BLOCKED sebelum S4 jika DP-2 tidak menghasilkan 2 |
| PF-06 | ≥2 kandidat `Disetujui + Sedang Dipakai` (sudah ada: id 28, 29) + ≥1 kandidat `Disetujui + Tersedia` (dicapai lewat **fase DP-1** — agent approve kandidat Draft via UI) | BLOCKED sebelum S4/S5 jika DP-1 tidak menghasilkan 1 |
| PF-07 | Lookup `kategori_force_majeur` aktif + ≥1 perusahaan aktif | BLOCKED form S2/S5 |
| PF-08 | Operator **konfirmasi** credential pack tersedia (di lokasi simpanan operator; agen tidak melihat file, **jangan rotate**) | BLOCKED — minta operator siap mengetik secret |
| PF-09 | `vibium is-installed` OK + base URL reachable | BLOCKED env |

### 3.2 Operator checklist (bukan tugas agen secret)

```text
□ Branch ui-w5-placement (agent yang menyalakan server; operator tidak perlu serve)
□ php artisan migrate --force (jangan fresh wipe tanpa re-seed R3 sadar)
□ Import TOTP JOB-MANAGER-A (dan APPROVER-A bila 2FA) ke authenticator
□ Siap mengetik password/TOTP saat STOP (pack tersedia; jangan rotate)
□ Data Prep dikerjakan **agent** lewat UI (fase DP); operator cukup login support
□ Siap di depan browser untuk setiap STOP
□ Beri agen: base URL + email akun (bukan password)
```

---

## 4. Deliverable (satu-satunya write yang diizinkan)

| Path | Isi |
| --- | --- |
| `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md` | Hasil per skenario + temuan + evidence map |
| `docs/tmp/ui-w5-manual-smoke-evidence/*.png` | Screenshot sanitized (tanpa secret) |

Buat folder evidence jika belum ada. **Jangan** commit secret. **Jangan** tulis
verdict final sendiri — verdict final dari Reviewer (percakapan terpisah).

### 4.1 Template RESULTS (salin ke file saat mulai)

```markdown
# UI-W5 Manual Smoke Results

- Task: UI-W5-T9-MANUAL-SMOKE-01
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
| S1 | | | |
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
| Nama kontainer | `W5 Smoke Kontainer R1` |
| Perusahaan | dropdown aktif (opsi yang tersedia) |
| Mulai kontrak | tanggal ≥ hari ini |
| Durasi kontrak | `6` bulan |
| Alasan resign | `W5 smoke resign alasan uji` |
| Alasan expel | `W5 smoke expel alasan uji` |
| Catatan approve expel | `W5 smoke setuju expel` |
| Catatan tolak | `W5 smoke tolak uji` |
| Kategori Force-Majeur | dari lookup aktif |
| Alasan Force-Majeur | `W5 smoke FM alasan uji` |

---

## 6. Matriks eksekusi (urutan wajib)

**Hasil per baris:** `PASS` | `FAIL` | `BLOCKED` | `SKIP` (+ alasan).

### Fase DP — Data Prep (wajib sebelum S1; hasilnya dicatat di RESULTS)

> State DB 2026-08-06 (cek ulang kalau berubah): kandidat Disetujui hanya id 28
> & 29, keduanya `SEDANG_DIPAKAI`; 27 Draft `TERSEDIA`; partisipasi unfrozen di
> kontainer Jobs Aktif = 2 (candidate 28 & 29, status `Menunggu Wawancara`).
> Partisipasi frozen di kontainer Ditutup **jangan** dipakai.

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **DP-1** | STAFF-A | Login → pilih kandidat Draft lengkap → submit | Menunggu Approval; NIK `K-…` terbentuk | `DP-1-submitted.png` |
| **DP-1b** | APPROVER-A | Login → approve kandidat itu | **Disetujui + Tersedia** | `DP-1-approved.png` |
| **DP-1c** | — | Negatif: jika submit Draft ditolak validasi | Coba Draft lain yang lebih lengkap; semua gagal → **BLOCKED data**, stop | — |
| **DP-2** | ASSISTANT-A | Login → Jobs detail kontainer **Aktif** → majukan 2 partisipasi (candidate 28 & 29): Menunggu Wawancara → (status legal) → Lulus → Proses Dokumen → **Siap Dikirim** | 2 partisipasi `Siap Dikirim`, unfrozen | `DP-2-siap-dikirim.png` |
| **DP-2b** | — | Verifikasi: tidak ada aksi `Terkirim` manual di UI Maker | `Terkirim` bukan opsi | `DP-2b-no-terkirim.png` |

Catatan DP: jangan ubah availability langsung di DB; semua lewat UI (Candidates
public service / Jobs service). Jika status yang dibutuhkan tidak tersedia di
UI → tulis apa yang terlihat, jangan paksa.

### Fase 0 — Login

| ID | Aktor | Langkah agen | Expected | Secret |
| --- | --- | --- | --- | --- |
| **P0** | — | `go` `/login` | Form login | — |
| **P1** | ASSISTANT-A | Isi email Maker → klik Masuk | — | **STOP — ASSISTANT-A — login password** (+ TOTP jika challenge) |
| **P1b** | — | Setelah `LANJUT` | Shell; nav **Penempatan** ada | — |
| **P2** | — | Logout | Kembali `/login` | — |
| **P3** | JOB-MANAGER-A | Isi email Checker → submit | — | **STOP — JOB-MANAGER-A — login password** (+ TOTP) |
| **P3b** | — | Setelah `LANJUT` | Shell; nav **Penempatan** + **Antrian Penempatan** ada | — |

### S1 — P1/P2 List & Detail

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S1-01** | Maker | `/placements` | List kontainer + badge status load | `S1-01-list-maker.png` |
| **S1-02** | Maker | Buka detail kontainer | Perusahaan + partisipasi + badge `status_penempatan` | `S1-02-detail.png` |
| **S1-03** | ADMIN-A | `/placements` + detail | Read-only: tanpa tombol mutasi | `S1-03-admin-readonly.png` |

### S2 — P3 Draft → submit → approve kontainer

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S2-01** | Maker | `/placements/create` → isi form §5 → Simpan Draft | Status **Draft**, tanpa kode `P-…`, tanpa pending | `S2-01-draft.png` |
| **S2-02** | Maker | Edit nama (draft) → simpan | Nama berubah | `S2-02-edited.png` |
| **S2-03** | Maker | Coba ubah perusahaan | Error `PC_COMPANY_IMMUTABLE`, perusahaan tetap | `S2-03-immutable.png` |
| **S2-04** | Maker | Submit | Kode **P-YYYY-NNNNN**, status Menunggu Approval, pending PC_CREATE | `S2-04-submitted.png` |
| **S2-05** | Maker | Coba approve sendiri di queue/detail | Ditolak / tombol tidak ada / error SoD | `S2-05-no-self-approve.png` |
| **S2-06** | Checker | `/placements/review` | Pending PC_CREATE terlihat | `S2-06-queue.png` |
| **S2-07** | Checker | Approve | → **Aktif**; **modal step-up TIDAK muncul** | `S2-07-aktif.png` |

**FAIL jika** step-up muncul di S2-07, atau self-approve bisa dilakukan.

### S3 — GAP-4 cancel Aktif kosong

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S3-01** | Maker | Detail kontainer Aktif kosong (hasil S2) | Tombol “Ajukan pembatalan kontainer” ada | `S3-01-cancel-request.png` |
| **S3-02** | Maker | Ajukan + alasan | Pending PC_CANCEL_ACTIVE; kontainer tetap Aktif | `S3-02-pending.png` |
| **S3-03** | Checker | Approve | → **Dibatalkan** | `S3-03-cancelled.png` |
| **S3-04** | Maker+Checker | Buat kontainer #2 (draft→submit→approve, tanpa step-up) | Aktif #2 untuk S4 | `S3-04-container-b.png` |
| **S3-05** | Checker | Uji tolak pada pengajuan cancel kontainer #3 (buat jika perlu) | Tolak + catatan → tetap **Aktif** | `S3-05-rejected.png` |
| **S3-06** | Maker | Kontainer berpartisipan (setelah S4/S6) | Tombol cancel **tidak muncul** | `S3-06-no-cancel.png` |

### S4 — P4 batch normal

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S4-01** | Maker | Detail Aktif → panel batch | Picker **hanya** menampilkan Siap Dikirim + Sedang Dipakai; label tidak menyebut Tersedia | `S4-01-picker.png` |
| **S4-02** | Maker | Isi visa/mulai/durasi per baris → submit | Pending PLACEMENT_BATCH; source **tetap** Siap Dikirim | `S4-02-submitted.png` |
| **S4-03** | Checker | Approve | Partisipasi **Bekerja**, source **Terkirim**, availability **tetap Sedang Dipakai** (bukan Tersedia) | `S4-03-approved.png` |
| **S4-04** | Maker | Negatif: pilih kandidat `Tersedia` (jika terlihat) | Tidak bisa dipilih / tidak tampil | `S4-04-tersedia-excluded.png` |
| **S4-05** | Maker | Negatif: batch >50 (isian duplikat/loop) | Ditolak UI + service | `S4-05-max50.png` |

**FAIL jika** ada window availability `Tersedia`, source tidak Terkirim, atau
partial batch (satu kandidat invalid tidak me-rollback semua).

### S5 — P5 Force-Majeur

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S5-01** | Maker | Panel “Tambah langsung / Force-Majeur” pada kontainer Aktif | Panel tampil | `S5-01-panel.png` |
| **S5-02** | Maker | Pilih kandidat **Tersedia + Disetujui**, isi kategori + alasan → submit | Pending; kandidat **tetap Tersedia** | `S5-02-submitted.png` |
| **S5-03** | Maker | Submit tanpa kategori/alasan (negatif) | Validasi wajib, tidak submit | `S5-03-required.png` |
| **S5-04** | Checker | Approve | → **Bekerja** + availability **Sedang Dipakai**; **tanpa step-up** | `S5-04-approved.png` |
| **S5-05** | Checker | Tolak FM lain (buat jika perlu) | Trail `FM_REJECTED`; kandidat tetap Tersedia | `S5-05-rejected.png` |

**FAIL jika** step-up muncul di S5-04, atau approve FM mengubah kandidat ke
status selain Bekerja/Sedang Dipakai.

### S6 — P6 status + arsip otomatis

| ID | Aktor | Langkah | Expected | Evidence |
| --- | --- | --- | --- | --- |
| **S6-01** | Maker | Aksi “Selesai Kontrak” pada partisipan Bekerja | Langsung terminal; kandidat **Tersedia** | `S6-01-selesai.png` |
| **S6-02** | Maker | “Mengundurkan Diri” + alasan | Pending; status belum terminal | `S6-02-resign-pending.png` |
| **S6-03** | Checker | Approve resign | **Tanpa step-up** → terminal | `S6-03-resign-approved.png` |
| **S6-04** | Maker | “Dikeluarkan” + alasan | Pending PLACEMENT_EXPEL | `S6-04-expel-request.png` |
| **S6-05** | Checker | Approve expel + catatan | **Wajib step-up** (modal muncul, belum terminal) | **STOP — JOB-MANAGER-A — step-up password+TOTP** |
| **S6-06** | — | Setelah `LANJUT` | → **Dikeluarkan**; kandidat Tersedia | `S6-06-expelled.png` |
| **S6-07** | Maker | Tolak expel + catatan (uji pada request lain) | Tetap Bekerja, catatan tampil | `S6-07-expel-rejected.png` |
| **S6-08** | — | Partisipan Bekerja terakhir terminal (S6-01/03/06) | Kontainer otomatis **Arsip**, read-only, **tanpa tombol archive manual** | `S6-08-arsip.png` |

**FAIL jika** expel approve tanpa step-up, archive manual ada, atau archive
terjadi sebelum kandidat Bekerja terakhir terminal.

### S7 — Negatif kunci

| ID | Langkah | Expected |
| --- | --- | --- |
| **S7-01** | Maker di queue/detail punya pengajuan sendiri: coba decide | Blocked / tidak ada kontrol (APV_SELF) |
| **S7-02** | Expel tanpa step-up: coba bypass / approve langsung | Modal step-up muncul, tidak ada mutasi |
| **S7-03** | 409 versi: dua tab pada detail yang sama, mutasi tab basi | Banner konflik + reload |
| **S7-04** | Kontainer Arsip/Dibatalkan: aksi mutasi | Tidak ada tombol / error |

---

## 7. Urutan klik ringkas (happy path ideal)

```
P0 → STOP login ASSISTANT-A → P1b
→ DP-2 (Jobs Aktif: 2× Siap Dikirim) → DP-2b
→ logout → STOP login STAFF-A → DP-1 → logout → STOP login APPROVER-A → DP-1b
→ S1-01 → S1-02
→ S2-01..S2-05 (draft/edit/immutable/submit/self-approve negatif)
→ logout → STOP login JOB-MANAGER-A → S2-06 → S2-07 (no step-up)
→ logout → STOP login ADMIN-A → S1-03
→ logout → STOP login ASSISTANT-A
→ S3-01..S3-03 (cancel Aktif kosong #1 → Dibatalkan)
→ S3-04 (kontainer #2 Aktif) → S4-01..S4-05 (batch)
→ logout → STOP login JOB-MANAGER-A → S4-03
→ logout → STOP login ASSISTANT-A
→ S5-01..S5-05 (FM)
→ logout → STOP login JOB-MANAGER-A → S5-04/S5-05
→ logout → STOP login ASSISTANT-A
→ S6-01..S6-08 (S6-05 STOP step-up Checker)
→ S7-01..S7-04
→ tulis RESULTS + handoff Reviewer
```

Ganti session dengan **logout + clear cookies** antar role bila session campur.

---

## 8. Verdict rules (untuk Reviewer — agent hanya menyiapkan bahan)

| Verdict | Kapan |
| --- | --- |
| **PASS** | Semua skenario path inti PASS; 0 temuan material |
| **PASS WITH NON-BLOCKING NOTES** | Path inti PASS; UX/minor notes only |
| **FAIL** | Invariant rusak: window Tersedia / markInUse transfer normal, PC_COMPANY mutable, partial batch, self-approve bisa, expel tanpa step-up, archive manual/prematur, authz broken |
| **BLOCKED** | Env/GAP A–C/lookup; secret tidak bisa diisi operator; preflight gagal |

**Path inti minimum agar hasil “berguna” (non-BLOCKED):**

1. S2 draft → submit → approve kontainer (tanpa step-up)
2. S4 batch normal: Bekerja + Terkirim + availability tetap Sedang Dipakai
3. S5 Force-Majeur tanpa step-up
4. S6 expel dengan step-up + arsip otomatis setelah Bekerja terakhir terminal
5. S7 self-approve diblokir + 409 versi

---

## 9. Stop conditions (langsung henti + laporkan)

- Server mati di tengah tes → **agent nyalakan ulang sendiri** (perintah PF-01),
  lanjut dari langkah terakhir yang terekam; hanya BLOCKED jika server tidak
  mau hidup kembali
- Secret diminta di chat → tolak, pakai STOP protocol
- DB / branch salah
- Tabel Placement hilang / migrasi belum jalan
- Step-up muncul pada IC_CREATE/PC_CREATE/batch/FM/resign approve
- Step-up **tidak** muncul pada approve expel
- Window availability `Tersedia` muncul pada transfer normal
- Aksi `Terkirim` manual di UI Maker
- Archive manual / prematur
- Route Guest public (Wave 6) muncul di scope ini
- Credential/token terbaca agen → hapus jejak, catat incident, stop

---

## 10. Setelah selesai

1. Isi `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md` lengkap (tanpa verdict final).
2. Pastikan evidence tanpa secret.
3. Serahkan ke Reviewer (percakapan terpisah):
   - `UI-W5-MANUAL-SMOKE-RESULTS.md`
   - `docs/tmp/ui-w5-manual-smoke-evidence/`
4. Reviewer memberi verdict final (PASS | PASS WITH NON-BLOCKING NOTES | FAIL |
   BLOCKED). Agent **tidak** membuat tag git, **tidak** edit BUILD_LOG,
   **tidak** edit authority/docs kakehashi.

---

## 11. Prompt salin-tempel (jika chat baru)

```text
APPROVED — START UI-W5 MANUAL SMOKE.

Anda adalah agent manual smoke. Ikuti PERSIS:
docs/tmp/UI-W5-MANUAL-SMOKE-AGENT-HANDOFF.md
(acuan: docs/tmp/UI-W5-VIBIUM-EXECUTION-PLAN.md)

Secret: JANGAN isi password/TOTP/recovery; gunakan "STOP FOR OPERATOR INPUT — …";
tunggu operator + LANJUT. Jangan buka credential pack. Jangan screenshot secret.

DB: kakehashi_r3_manual. Branch: ui-w5-placement.
Maker email: assistant-a@r3-manual.example.com
Checker email: job-manager-a@r3-manual.example.com (password/TOTP operator-only)

Preflight §3 dulu. Tulis:
docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md
docs/tmp/ui-w5-manual-smoke-evidence/

Jangan ubah app code, routes/, atau docs/kakehashi/. Jangan beri verdict final.
```

---

## 12. Referensi cepat

| Dokumen | Kapan dibaca |
| --- | --- |
| Dokumen **ini** | Eksekusi utama |
| `UI-W5-VIBIUM-EXECUTION-PLAN.md` | Matriks + environment |
| `UI-W5-MANUAL-SMOKE-PLAN.md` | Detail skenario / DoD |
| `UI-W5-BUILDER-FINAL-HANDOFF.md` | Konteks build UI-W5 |
| `UI-W5-ORCHESTRATION-FINAL-REPORT.md` | Konteks domain W5 (notes N-1..N-4) |

---

**End handoff. Eksekusi dimulai setelah preflight §3.**
