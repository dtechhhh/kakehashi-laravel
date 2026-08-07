# UI-W6 Manual Smoke Results

- Task: UI-W6-T9-MANUAL-SMOKE-01
- Date: 2026-08-07
- Branch / HEAD: `ui-w6-guest` / `22cf26a`
- Base URL: http://127.0.0.1:8000
- DB: kakehashi_r3_manual
- Verdict: **PASS WITH NON-BLOCKING NOTES** — Reviewer 2026-08-07
  (`docs/tmp/UI-W6-MANUAL-SMOKE-REVIEW.md`)

## Preflight

| ID | Result | Note |
| --- | --- | --- |
| PF-01 | PASS | Server dinyalakan agen: `DB_DATABASE=kakehashi_r3_manual php artisan serve --host=127.0.0.1 --port=8000`; `GET /login` → 200 |
| PF-02 | PASS | Branch `ui-w6-guest`, HEAD `22cf26a` (sesuai expected); DB `kakehashi_r3_manual` diverifikasi via psql + env server |
| PF-03 | PASS | Migrasi `2026_08_07_000000_create_guest_access_log_table` dijalankan via `--database=pgsql_migrator` (owner); `guest_link` + `guest_access_log` ada; runtime user `kakehashi` dapat SELECT (`count(*)` → 0) |
| PF-04 | PASS | ASSISTANT-A (Asisten Manajer), JOB-MANAGER-A (Manajer Job, TOTP confirmed), ADMIN-A (Super Admin) ada |
| PF-05 | PASS (data) | Kontainer `W-2026-00002` status Aktif dengan 2 partisipan; kontainer `W-2026-00001` Ditutup (2 partisipan). Verifikasi final via UI di DP-1 |
| PF-06 | PASS | 4 kandidat `Disetujui` + `TERSEDIA` (≥2, cukup untuk pull bila kontainer kosong) |
| PF-07 | MENUNGGU OPERATOR | Konfirmasi credential pack tersedia (jangan rotate) |
| PF-08 | PASS | `vibium is-installed` exit 0; DISPLAY=:0 tersedia; base URL reachable |
| PF-09 | PASS | `GET /guest/token-tidak-valid` → 404; header: `Cache-Control: no-store, private`, HSTS, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy no-referrer, Permissions-Policy, CSP |

## Scenarios

| ID | Result | Evidence | Note |
| --- | --- | --- | --- |
| DP-1 | PASS | `DP-1-container-aktif.png` | Kontainer `W-2026-00002` (W4 Smoke Kontainer B R1) status **Aktif**; 2 partisipan (K-2026-00001, K-2026-00002, Terkirim) — via UI |
| DP-2 | PASS | — | Kontainer dipakai: `W-2026-00002`, kode `W-2026-00002` (non-secret) |
| P0 | PASS | — | `go /login` → form login tampil (title "Masuk · Kakehashi") |
| P1 | PASS | — | Email ASSISTANT-A diisi agen; password/TOTP diisi operator di window visible; login sukses → `/home` |
| P1b | PASS | — | Shell tampil; nav **Wawancara** ada (juga Beranda, Penempatan) |
| P2 | PASS | — | Logout ASSISTANT-A → kembali `/login` |
| P3 | PASS | — | Email JOB-MANAGER-A diisi agen; password+TOTP diisi operator; login sukses → `/home` |
| P3b | PASS | — | Shell tampil (Manajer Job); nav Wawancara + Antrian Job + Penempatan + Antrian Penempatan ada |
| S1-01 | PASS | `S1-01-request.png` | Maker request link #1 (label `W6 Smoke Guest Link R1`, expiry 2026-08-31, tanpa kode) → status **Menunggu Persetujuan**; tidak ada token/URL |
| S1-02 | PASS (retry setelah S1-03) | `S1-02-request-code.png` | Request link #2 (label `W6 Smoke Guest Link R2`, expiry 2026-08-31, kode `W6TEST`) → **Menunggu Persetujuan** (pending id=23, `kode_tambahan_hash` ter-set); tanpa token/URL. Percobaan pertama gagal `APV_DUPLICATE` (lihat F-01) |
| S1-03..S1-06 | (belum — butuh Checker) | | |
| S1-03 | PASS | `S1-03-approved-url.png` (token di-mask) + observasi teks | Checker approve link #1 dengan catatan → **Aktif**; panel "Link Tamu Disetujui": URL publik `http://…/guest/{token}` tampil **sekali** + tombol Salin (onclick menyalin value `#guest-token`); nilai token tidak dicatat |
| S1-04 | PASS | `S1-04-after-reload.png` | Reload → `#guest-token` hilang, section "Link Tamu Disetujui" tidak dirender ulang; URL/token tidak tampil lagi |
| S1-05 | PASS | `S1-05-approved-url.png` (token di-mask) | Checker approve link #2 (berkode `W6TEST`) → Aktif; URL publik tampil sekali + tombol Salin; reload → token hilang (token-once untuk link #2 juga) |
| S1-06 | PASS | `S1-06-rejected.png` | Request #3 (tanpa kode) → Checker **tolak** dengan catatan `W6 smoke tolak link` (pending id=24 → rejected, note tersimpan); tidak ada token/baris link aktif |
| S2-01 | PASS | `S2-01-list-ok.png` | Link #4 (tanpa kode) dibuka → langsung list G2 (tanpa form kode); pseudonim, tanpa nama |
| S2-02 | PASS | `S2-02-code-form.png` | Buka link #2 (berkode) → form "追加コードの入力" tampil (input `#code` + tombol 開く) |
| S2-03 | PASS | `S2-03-code-wrong.png` | Kode salah (`WRONG`) → halaman tolak generik JP "リンクを確認できません" — tanpa alasan spesifik |
| S2-04 | PASS | (terlihat di S3-01) | Kode benar (`W6TEST`) → masuk list G2 "面接候補者リスト" |
| S3-01 | PASS | `S3-01-list-fields.png` | List: identifier NIK `K-2026-00001/2`, umur `26歳/25歳`, gender `男/女`, kewarganegaraan, JP level, SSW, bidang — tanpa nama/foto/email/telepon (cek body: 0 nama, 0 email, 0 img) |
| S3-02 | PASS | (termasuk S3-01) | Header: `R3テスト会社` (nama_ja), `面接日: 2026-08-21`, `面接形式: 対面` — bahasa Jepang |
| S3-03 | PASS | (termasuk S3-01) | Tidak ada kontrol sort/filter sama sekali di list (hanya link kandidat) — tidak ada filter PII |
| S4-01 | PASS | `S4-01-detail.png` | Detail G3 kandidat 1 & 2: Nama Alphabet (`W4 Smoke Candidate A/B`), umur, gender, kewarganegaraan, bidang; riwayat kerja/pendidikan render kosong (`—`); hanya field whitelist |
| S4-02 | BLOCKED (data gap) | — | 0 foto di seluruh DB (`candidate_photo` kosong) → foto via route `guest.photo` tidak bisa diamati. Verifikasi tersisa: PII-leak suite build (PASS) + rute `guest.photo` terpasang + scoped (kode) |
| S4-03 | PASS | (termasuk S4-01) | Cek body detail: 0 hit field HIDE (email/telepon/Line/alamat/tanggal lahir/IQ/MTK/psikotes/keluarga/imigrasi/visa/paspor) |
| S5-01 | PASS (pra-login, curl) | `S5-01-invalid-body.html` | `/guest/token-tidak-valid` → 404; body JP generik: "リンクを確認できません / リンクが無効、期限切れ、またはアクセスできない状態です" — tidak membedakan alasan |
| S5-02 | PASS (pra-login, curl) | `S5-02-throttle.txt` | 11th attempt dalam 1 menit dari 127.0.0.1 → HTTP 429 (10x 404 lalu 429) |
| S5-03 | PASS | `S5-03-lockout.png` | 5× kode salah (2 browser + 3 curl) → lockout: percobaan berikutnya HTTP 429 + halaman generik JP; log security `code_failed` ×5 (guest_link_id=3) |
| S5-04 | PASS | (teks) | Kode benar `W6TEST` saat lockout aktif → **ditolak** (HTTP 429, halaman generik) — lockout berjalan |
| S5-05 | PASS | `S5-05-scope.png` | Navigasi `/guest/candidates` tanpa scope → hanya menampilkan kontainer sesi token (R3テスト会社, 2 kandidat); tidak ada pemilih kontainer; tidak bocor kontainer lain |
| S6-01 | PASS | `S6-01-reload.png` | Reload link #4 → tetap bisa (sesi token valid), list sama (2 kandidat) |
| S6-02 | PASS (pra-login, curl) | `S6-02-headers.txt` | Response halaman Tamu memuat `Cache-Control: no-store, private` (+ HSTS/XFO/XCTO/Referrer-Policy/Permissions-Policy/CSP) — juga pada 429 |
| S7-01 | SKIP (UI tidak mengizinkan) | — | Form menolak expiry masa lalu (validasi server `GUEST_EXPIRY_PAST`; tidak ada pending dibuat, tanpa pesan tampil di UI — catatan UX). Kriteria "body identik token invalid" diuji via S7-02 |
| S7-02 | PASS | `S7-02-closed-denied.png` | Maker request close → Checker approve (step-up, pending 26 approved; container `W-2026-00002` → **Ditutup**, `closed_at` tercatat) → buka link #4 → HTTP 404 halaman generik JP; body normalisasi **identik** dengan halaman token invalid |

> Catatan: S5-01/S5-02/S6-02 dieksekusi via curl sebelum login (tidak butuh secret),
> karena operator belum hadir. PNG browser dapat diambil ulang saat fase S5 resmi
> bila diperlukan Reviewer; hasil curl setara environment (app yang sama, IP sama).

## Findings

| ID | Severity | Description | Evidence |
| --- | --- | --- | --- |
| GAP-C | Resolved | Tabel `guest_access_log` awalnya belum ada; migrasi pending dijalankan via koneksi migrator (per komentar migrasi: `--database=pgsql_migrator`); skema sesuai migrasi proyek, tanpa improvisasi | `php artisan migrate:status`, `to_regclass('public.guest_access_log')` |
| F-01 | Info (matriks vs invariant) | Matriks S1-02/S1-06 mengharapkan beberapa request GUEST_LINK pending bersamaan, tapi invariant + implementasi `PendingRequestService::submit` menolak pending kedua (`APV_DUPLICATE`, `uq_pending_active`). Rencana adaptasi: S1-01 pending → S1-03 approve #1 → S1-02 ulang (kode) → S1-05 approve #2 → S1-06 request #3 + reject. Seluruh perilaku tetap diuji | `guest_link` id=1 (Aktif, lama), `pending_request` id=21 (GUEST_LINK pending, payload R1 tanpa kode), audit `GUEST_LINK_REQUESTED` hanya 1x (id 214) |
| F-02 | **Incident (security protocol)** | Saat membuka URL Tamu link #2 (S2-02), output `vibium go` mencetak URL lengkap berisi token mentah ke percakapan (chat). Melanggar aturan §2.1. Jejak file sementara (`/tmp/w6-guest-url`) sudah di-truncate ke 0 byte; tidak ada file evidence/report berisi token (token hanya pernah di /tmp + transcript). Smoke dihentikan sesuai §9. Link #2 dianggap terekspos di chat → perlu keputusan operator (lanjut dengan risiko terima, atau rotasi/recreate link) | transcript tool output S2-02 |

## Handoff

- **STATUS: SELESAI dieksekusi** (atas keputusan operator lanjut opsi A setelah
  insiden F-02). Semua skenario inti tereksekusi:
  DP, P0–P3b, S1-01..S1-06, S2-01..S2-04, S3-01..S3-03, S4-01/03 (S4-02 BLOCKED
  data gap), S5-01..S5-05, S6-01/02, S7-02 (S7-01 SKIP).
- Verdict final: **diisi Reviewer** (percakapan terpisah) — agen tidak memberi verdict.
- Catatan handoff: insiden F-02 (token link #2 bocor ke transcript) — operator
  memutuskan lanjut (risiko diterima). Token link #4 hanya disimpan sementara di
  /tmp (mode 600) dan sudah di-truncate; tidak ada token di file evidence/report.
