# UI-W5 Manual Smoke Results

- Task: UI-W5-T9-MANUAL-SMOKE-01
- Date: 2026-08-06 s/d 2026-08-07 (dua sesi; jeda operator di antara)
- Branch / HEAD: `ui-w5-placement` / `c86ff67`
- Base URL: `http://127.0.0.1:8000`
- DB: `kakehashi_r3_manual` (PostgreSQL lokal, 127.0.0.1:5432)
- Verdict: **PASS WITH NON-BLOCKING NOTES** — Reviewer 2026-08-07
  (`docs/tmp/UI-W5-MANUAL-SMOKE-REVIEW.md`)
- Status sesi: **SELESAI** — seluruh skenario S1–S7 + Fase DP dijalankan; menunggu Reviewer.

## Preflight

| ID | Result | Note |
| --- | --- | --- |
| PF-01 | PASS | Server dinyalakan agent: `DB_DATABASE=kakehashi_r3_manual php artisan serve --host=127.0.0.1 --port=8000`; `GET /login` → 200. |
| PF-02 | PASS | Branch `ui-w5-placement` @ `c86ff67`; DB `kakehashi_r3_manual` terhubung. |
| PF-03 | PASS | Tabel `placement_container`, `placement_participants` ada (migrasi sudah jalan). |
| PF-04 | PASS | ASSISTANT-A, JOB-MANAGER-A (2FA), ADMIN-A (2FA), STAFF-A, APPROVER-A (2FA) — Aktif, role benar. |
| PF-05 | PASS | Dicapai via DP-2: 2 partisipasi unfrozen `Siap Dikirim` (participation 3, 4) di kontainer Jobs Aktif. |
| PF-06 | PASS | Dicapai via DP-1: 2 Disetujui+SEDANG_DIPAKAI (28, 29) + Disetujui+TERSEDIA (27; tambahan 26 untuk S5-05). |
| PF-07 | PASS | `kategori_force_majeur` aktif 6/6; perusahaan aktif 1/1. |
| PF-08 | PASS | Operator konfirmasi siap mengetik secret; agen tidak melihat pack, tidak rotate. |
| PF-09 | PASS | `vibium is-installed` OK; base URL reachable. |

## Scenarios

| ID | Result | Evidence | Note |
| --- | --- | --- | --- |
| P0–P3b | PASS | — | Login/logout ASSISTANT-A, JOB-MANAGER-A, ADMIN-A; nav sesuai role. |
| DP-1 / DP-1b | PASS | `DP-1-submitted.png`, `DP-1-approved.png` | Kandidat 27 → K-2026-00003 → Disetujui+Tersedia. |
| DP-1c | SKIP | — | Negatif tidak terpicu (submit berhasil percobaan pertama). |
| DP-2 / DP-2b | PASS | `DP-2-siap-dikirim.png`, `DP-2b-no-terkirim.png` | 2 partisipasi → Siap Dikirim via UI Jobs; tanpa aksi Terkirim manual. |
| S1-01 | PASS | `S1-01-list-maker.png` | List + badge status. Temuan F1: label `ui.placement.status.*` mentah. |
| S1-02 | PASS | `S1-02-detail.png` | Detail: perusahaan + partisipasi + panel batch. |
| S1-03 | PASS | `S1-03-admin-readonly.png`, `S1-03-admin-readonly-detail.png` | Admin read-only, tanpa tombol mutasi. |
| S2-01 | PASS | `S2-01-draft.png` | Draft tanpa kode, tanpa pending. |
| S2-02 | PASS | `S2-02-edited.png` | Nama draft berubah. |
| S2-03 | PASS | `S2-03-immutable.png` | Perusahaan immutabel (`PC_COMPANY_IMMUTABLE`), DB tetap perusahaan 1. |
| S2-04 | PASS | `S2-04-submitted.png` | P-2026-00003, Menunggu Approval, pending PC_CREATE. |
| S2-05 | PASS | `S2-05-no-self-approve.png` | Maker akses queue → 403. |
| S2-06 | PASS | (screenshot tidak sempat diambil — F4) | Pending PC_CREATE P-2026-00003 terlihat di queue Checker. |
| S2-07 | PASS | `S2-07-aktif.png` | Approve → Aktif, tanpa step-up. |
| S3-01 | PASS | `S3-01-cancel-request.png` | Tombol "Ajukan pembatalan kontainer" ada (Aktif kosong). |
| S3-02 | PASS | `S3-02-pending.png` | Pending PC_CANCEL_ACTIVE; kontainer tetap Aktif. |
| S3-03 | PASS | `S3-03-cancelled.png` | Approve → Dibatalkan, tanpa step-up. |
| S3-04 | PASS | `S3-04-container-b.png` | Kontainer #2 P-2026-00004 → Aktif, tanpa step-up. |
| S3-05 | SKIP | — | Tolak-cancel tidak diuji (butuh kontainer #3; opsi "buat jika perlu"). |
| S3-06 | PASS | `S3-06-no-cancel.png` | Kontainer berpartisipan: tombol cancel tidak muncul. |
| S4-01 | PASS | `S4-01-picker.png` | Picker hanya Siap Dikirim + Sedang Dipakai; Tersedia tidak tampil. |
| S4-02 | PASS | `S4-02-submitted.png` | Pending PLACEMENT_BATCH; source tetap Siap Dikirim. |
| S4-03 | PASS | `S4-03-approved.png`, `S4-03-detail-bekerja.png` | Bekerja + Terkirim + availability tetap Sedang Dipakai; akhir kontrak = mulai+durasi−1 hari; tanpa step-up. |
| S4-04 | PASS | `S4-01-picker.png` | Kandidat Tersedia dikecualikan. |
| S4-05 | SKIP | — | >50 tidak bisa dikonstruksi (2 eligible); limit service `MAX_BATCH=50` + teks UI "Maksimal 50 kandidat per batch." |
| S5-01 | PASS | `S5-01-panel.png` | Panel Force-Majeur tampil. |
| S5-02 | PASS | `S5-02-submitted.png` | Pending FORCE_MAJEUR; kandidat tetap Tersedia. |
| S5-03 | PASS | `S5-03-required.png` | Tanpa kategori/alasan → validasi wajib, tanpa pending. |
| S5-04 | PASS | `S5-04-approved.png` | Approve → Bekerja + Sedang Dipakai, tanpa step-up. |
| S5-05 | PASS | `S5-05-rejected.png` | FM #2 ditolak + catatan → pending rejected, audit `FM_REJECTED`, kandidat tetap Tersedia. |
| S6-01 | PASS | `S6-01-selesai.png` | Selesai Kontrak 28 → terminal; kandidat Tersedia. |
| S6-02 | PASS | `S6-02-resign-pending.png` | Resign 29 → pending PLACEMENT_RESIGN, belum terminal. |
| S6-03 | PASS | `S6-03-resign-approved.png` | Approve resign → terminal, tanpa step-up; kandidat Tersedia. |
| S6-04 | PASS | `S6-04-expel-request.png`, `S6-04-expel-27.png` | Expel → pending PLACEMENT_EXPEL. |
| S6-05 | PASS | `S6-05-stepup-modal.png` | Approve expel → modal step-up (password+TOTP) WAJIB muncul; belum terminal. |
| S6-06 | PASS | `S6-06-expelled.png` | Setelah step-up → Dikeluarkan; kandidat Tersedia. |
| S6-07 | PASS | `S6-07-expel-rejected.png` | Expel lain ditolak + catatan → tetap Bekerja. |
| S6-08 | PASS | `S6-08-arsip.png` | Bekerja terakhir terminal → kontainer otomatis **Arsip**, read-only, tanpa tombol archive manual. |
| S7-01 | PASS | `S2-05-no-self-approve.png` | Maker decide pengajuan sendiri → diblokir (403 queue). |
| S7-02 | PASS | `S6-05-stepup-modal.png` | Approve expel tanpa step-up → modal muncul, tanpa mutasi. |
| S7-03 | PASS | (DOM capture: banner konflik) | Dua tab edit; mutasi tab stale → banner "Data telah diubah oleh pihak lain. Muat ulang…"; mutasi tidak diterapkan (DB tetap versi mutator). |
| S7-04 | PASS | `S7-04-arsip-readonly.png`, `S7-04-dibatalkan-readonly.png` | Arsip & Dibatalkan read-only, tanpa tombol mutasi. |

## Findings

| ID | Severity | Description | Evidence |
| --- | --- | --- | --- |
| F1 | Non-blocking (UI) | Label status kontainer render sebagai translation key mentah `ui.placement.status.*` (Draft/Menunggu Approval/Aktif/Arsip/Dibatalkan) di list & detail. | `S1-01-list-maker.png`, `S1-02-detail.png`, `S6-08-arsip.png` |
| F2 | Info | Data sisa run sebelumnya: pending PC_CREATE P-2026-00001 (kontainer "Kontainer Display Menunggu") masih di queue Checker; tidak mengganggu skenario inti. | Query `pending_request` |
| F3 | Info | S4-05 (batch >50) tidak dapat diuji end-to-end (hanya 2 eligible); limit diverifikasi dari service (`MAX_BATCH=50`) + teks UI. | `PlacementBatchService.php:36` |
| F4 | Info | Evidence screenshot S2-06 belum diambil (pending PC_CREATE P-2026-00003 sudah diverifikasi in-session). | — |
| F5 | Info | Kontainer 1 ("Kontainer Display Draft", data sisa) dipakai sebagai subjek uji 409 S7-03 → namanya berubah menjadi "W5 Smoke 409 Mut v3", version 3 (data synthetic, bukan production). | Query `placement_container` |

## Handoff

- Skenario inti minimum §8 terpenuhi: S2 (draft→submit→approve tanpa step-up), S4 (Bekerja + Terkirim + availability tetap Sedang Dipakai), S5 (FM tanpa step-up), S6 (expel dengan step-up + arsip otomatis), S7 (self-approve diblokir + 409 versi).
- Agent tidak mengubah app code, routes/, docs/kakehashi/, Build Log; tidak membuat tag; tidak ada secret yang dibaca/dicatat; evidence tanpa secret (modal step-up di-screenshot saat field kosong).
- Verdict final dari Reviewer (percakapan terpisah) berdasarkan file ini + `docs/tmp/ui-w5-manual-smoke-evidence/`.
