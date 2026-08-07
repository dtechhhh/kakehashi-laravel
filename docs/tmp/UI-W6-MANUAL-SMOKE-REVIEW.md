# UI-W6 Manual Smoke — Reviewer Report

- Task: `UI-W6-T9-MANUAL-SMOKE-01` (review)
- Date: 2026-08-07
- Branch / HEAD ditinjau: `ui-w6-guest` @ `22cf26a`
- DB diverifikasi: `kakehashi_r3_manual` (PostgreSQL 127.0.0.1:5432)
- Sumber: `docs/tmp/UI-W6-MANUAL-SMOKE-RESULTS.md` + `docs/tmp/ui-w6-manual-smoke-evidence/`
- Reviewer: percakapan terpisah — tidak mengubah kode
- **Verdict: PASS WITH NON-BLOCKING NOTES**

---

## Metode verifikasi

1. Baca RESULTS lengkap (preflight, skenario S1–S7, findings, handoff).
2. Cross-check klaim terhadap DB `kakehashi_r3_manual` (guest_link,
   guest_access_log, pending_request, audit_log, candidate_photo,
   interview_container) dan evidence (file HTML/TXT header + PNG sesuai matriks).
3. Inspeksi evidence teks: body tolak generik JP, throttle 429, header
   no-store + security headers (S5-01, S5-02, S6-02).

## Cross-check invariant (klaim agent vs bukti DB/kode)

| Invariant | Klaim agent | Bukti verifikasi Reviewer |
| --- | --- | --- |
| Token hash-only at rest | PASS | `guest_link` hanya punya `token_hash` + `kode_tambahan_hash`; tidak ada kolom token mentah; semua 4 baris hash ✓ |
| Token hanya setelah approval | PASS (S1-01/02/06) | pending GUEST_LINK: 4 approved + 2 rejected; tidak ada baris guest_link dari request yang ditolak ✓ |
| Token-once UI | PASS (S1-03/04/05) | Panel URL sekali, hilang setelah reload (evidence) ✓ |
| Gate validasi urut + generik | PASS (S2, S5-01, S7-02) | 404 JP generik; body closed vs invalid identik (S7-02) ✓ |
| Rate limit (invalid 10/mnt/IP) | PASS (S5-02) | Evidence: 10× 404 lalu 429 ✓ |
| Lockout kode 5× → 15 mnt | PASS (S5-03/04) | Kode benar saat lockout tetap ditolak (429) ✓ |
| G2 pseudonim, tanpa nama/foto/PII | PASS (S3-01) | NIK + umur/gender/kewarganegaraan/level JP; 0 nama/email/img di body ✓ |
| G3 whitelist + audit | PASS (S4-01/03) | `GUEST_DETAIL_VIEWED` 8×; 0 field HIDE di body detail ✓ |
| Anonymized excluded | (tidak ada data anonim di manual DB) | Ditutup oleh domain suite W6-T4/T5 ✓ |
| Akses log tanpa token mentah | PASS | `guest_access_log` kolom: guest_link_id, accessed_at, ip, user_agent — tanpa token; 4 baris ✓ |
| Audit GUEST_ACCESS | PASS | `GUEST_ACCESS` 4×, `GUEST_LINK_APPROVED` 4, `GUEST_LINK_REJECTED` 2, `GUEST_LINK_REQUESTED` 6 ✓ |
| Header no-store + keamanan | PASS (S6-02) | Evidence: `no-store, private`, HSTS, XFO DENY, XCTO nosniff, Referrer-Policy, Permissions-Policy, CSP ✓ |
| Scope kontainer | PASS (S5-05) | List hanya kontainer sesi token; tanpa pemilih kontainer ✓ |
| Link mati saat kontainer ditutup | PASS (S7-02) | `W-2026-00002` → `Ditutup` (closed_at 2026-08-07 02:58:15); link → 404 generik identik ✓ |

## Disposisi temuan agent

| ID | Severity agent | Disposisi Reviewer |
| --- | --- | --- |
| GAP-C | Resolved | Diterima — migrasi `guest_access_log` dijalankan via koneksi migrator sesuai komentar migrasi; skema cocok, tanpa improvisasi. |
| F-01 | Info | Diterima — matriks disesuaikan dengan invariant satu-pending-aktif (`APV_DUPLICATE`); seluruh perilaku tetap teruji. |
| F-02 | **Incident (process)** | **Diterima sebagai non-blocking process note — BUKAN bug aplikasi.** Token mentah link #2 bocor ke transcript lewat output tool (`vibium go` mencetak URL), bukan lewat app/log/DB (hash-only terverifikasi). Agent stop sesuai protokol, truncate file sementara; operator lanjut dengan risiko diterima. Dampak: token synthetic di DB manual; link kini **mati** (kontainer Ditutup). Rekomendasi: jika link serupa dibuat ulang untuk tes berikutnya, **recreate** (jangan pakai link #2 lagi); proses guest smoke selanjutnya wajib menekan output URL berisi token (pakai clipboard/masked, bukan `vibium go` ke chat). |
| S4-02 | BLOCKED (data gap) | Diterima — `candidate_photo` = 0 baris; foto E2E tidak bisa diamati di smoke. Cakupan domain: rute `guest.photo` + scope + TTL diuji build (W6-T6 8/8). Non-blocking; opsi re-run setelah DB manual di-seed foto (masuk backlog Wave 7 bila perlu). |
| S7-01 | SKIP | Diterima — expiry masa lalu tidak bisa dibuat via UI (validasi server `GUEST_EXPIRY_PAST` tanpa pesan tampil = minor UX note); path expired dicakup domain T2. Kriteria body-identik teruji via S7-02. |

## Path inti minimum (§8 handoff)

1. S1 request → approve → token-once (muncul sekali, hilang setelah reload) — PASS
2. S2/S3 gate valid + G2 pseudonim JP (NIK saja) — PASS
3. S4 G3 detail whitelist (foto terblokir data gap, dicakup build) — PASS
4. S5 token invalid → 404 generik + rate limit 429 + lockout — PASS
5. S7 link mati saat kontainer ditutup, tolak generik identik — PASS

Semua terpenuhi. Tidak ada Blocker/Major aplikasi; tidak ada pelanggaran
invariant Wave 6 (hash-only, generic denial, G2 anti-PII, whitelist G3, scope
token, rate limit, no-store, audit).

## Gate

- UI Wave 6 manual smoke: **PASS WITH NON-BLOCKING NOTES**.
- F-02 = insiden proses (bukan bug produk); link terkait sudah mati; tidak
  memblokir Wave 7 dari sisi smoke UI.
- Rekomendasi: catat hasil di `BUILD_LOG.md` oleh operator; proses smoke
  berikutnya: jangan pernah cetak URL berisi token ke transcript.

## Lampiran bukti

- `docs/tmp/ui-w6-manual-smoke-evidence/` — 19 file (PNG + HTML/TXT header)
  sesuai matriks.
- Query DB verifikasi: guest_link hash-only (4), guest_access_log tanpa token
  (4), pending GUEST_LINK 4/2, audit GUEST_* 4/8/4/2/6, candidate_photo 0,
  W-2026-00002 Ditutup.
