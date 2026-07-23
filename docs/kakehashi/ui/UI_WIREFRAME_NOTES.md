---
title: "UI_WIREFRAME_NOTES"
status: "FINAL v1.0 — Batch B consistency pass"
source_notion_title: "UI_WIREFRAME_NOTES"
exported_at: "2026-07-16"
authority_rank: "semantic-ui-below-domain"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Semantic UI snapshot. PRD v0.3.14, Batch A/B, and domain/schema/module/API/security/privacy documents take precedence. Approved HTML is visual reference only.

> [!NOTE]
> **UI_WIREFRAME_**[**NOTES.md**](UI_WIREFRAME_NOTES.md)** — Kakehashi (Kelompok 3 · Teknis).** Sumber kebenaran **SEMANTIK** UI: alur, peran, state, step-up, whitelist Tamu, i18n. **Hierarki final: PRD_Kakehashi_v0.3.14 > fondasi domain/schema/modul/API/security/privacy > UI_WIREFRAME_NOTES > Approved HTML non-authoritative.** HTML Stitch = referensi visual **non-authoritative** (Stitch buta aturan bisnis; bila bentrok, PRD/NOTES menang & HTML direvisi).
> **Status: FINAL v1.0 — Batch B consistency pass 2026-07-14.** Stack, tema, bahasa, screen inventory, dan authority telah dikunci. Persona: UX/Frontend Architect + Orkestrator. Tgl: 2026-06-30 (Asia/Jakarta).
>
## 0. Intake & Koreksi Wajib (dikonfirmasi)
Baseline final diselaraskan ke PRD v0.3.14. GLOSSARY, ROLES_AND_PERMISSIONS, STATUS_STATE_MACHINE, BUSINESS_RULES, MODULE_AUTH/LOOKUP_DATA/CANDIDATES/JOBS/PLACEMENT/GUEST_ACCESS, DATABASE_SCHEMA, ARCHITECTURE, PROJECT_OVERVIEW, TECH_VERSION_SEED, DECISIONS_LOG.
Empat koreksi wajib ditegakkan sebagai invariant UI:
1. **Tamu = TOKEN-GATED satu kontainer**, read-only murni (tanpa upload), **Japanese-only**, hanya field whitelist `GuestCandidateView` (PRD Lampiran C); tanpa listing/navigasi kontainer lain; halaman tolak generik bila token invalid. (GA-5, MODULE_CANDIDATES #1, ROLES §10)
2. **Step-up re-auth = 5 trigger saja** (PRD Lampiran D + §7.9 + ROLES §8.2 + AUTH A-1): (a) ubah role/nonaktifkan akun · (b) tutup kontainer wawancara · (c) keluarkan/cabut kandidat (Wawancara **dan** Penempatan) · (d) kelola lookup/config + master perusahaan · (e) anonimisasi PII. **Approve/Reject kandidat & kontainer, kirim batch, resign, Force-Majeur = RUTIN (bukan step-up).**
3. Rujukan §PRD: pagination 25 + whitelist sort/filter = **§8.4**; notif in-app polling ≤60 dtk tanpa websocket = **§8.4 + ARCH D6**; i18n/enum kanonik/glyph/format tanggal = **§9.4**.
4. **Form Force-Majeur**: kategori (lookup `kategori_force_majeur`) **+** alasan free-text, **keduanya wajib** (MODULE_PLACEMENT #4 + CHECK DB); **tanpa step-up**; lalu approval batch biasa.
---
## 1. Screen Inventory (kerangka — per modul/peran)
> Legenda aksi: 🔒 = memicu **step-up** · ⏳ = menghasilkan **pending_request** (status agregat belum berubah) · 👁️ = read-only · RUTIN = approval biasa tanpa step-up.
> Semua layar internal: desktop-first, toggle bahasa ID/JP, badge status render glyph (D9), konflik tulis → **409 + minta reload** (D8), pagination server-side **25** + sort/filter **hanya kolom whitelist** (§8.4).
### 1.1 Auth (MODULE_AUTH)
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci (ROLES + STATE + step-up)</td>
<td>→ html</td>
</tr>
<tr>
<td>A1 Login</td>
<td>Semua user internal</td>
<td>Email + password → bila 2FA aktif lanjut ke challenge TOTP; throttle 5 gagal/15 mnt (A-4); akun `Nonaktif` ditolak 403</td>
<td>→ html/auth-login.html (belum dibuat)</td>
</tr>
<tr>
<td>A2 Paksa ganti password</td>
<td>Semua (login pertama)</td>
<td>`must_change_password=true` (§6.1); policy min 12 char + 3/4 kelas (A-7)</td>
<td>→ html/auth-force-password.html (belum dibuat)</td>
</tr>
<tr>
<td>A3 Enroll TOTP</td>
<td>Wajib: Approver Kandidat, Manajer Job, Super Admin; opsional: Staf Input, Asisten Manajer</td>
<td>QR otpauth + secret → WAJIB konfirmasi 1 kode (anti lock-out) → tampilkan 8 recovery codes sekali</td>
<td>→ html/auth-2fa-enroll.html (belum dibuat)</td>
</tr>
<tr>
<td>A4 Challenge TOTP</td>
<td>User dengan 2FA aktif</td>
<td>Input 6 digit (toleransi ±1 step) atau recovery code (single-use); throttle terpisah</td>
<td>→ html/auth-2fa-challenge.html (belum dibuat)</td>
</tr>
<tr>
<td>A5 Pesan lockout</td>
<td>Semua</td>
<td>Tampilan 429 + waktu buka kembali (A-4)</td>
<td>→ html/auth-lockout.html (belum dibuat)</td>
</tr>
<tr>
<td>A6 Modal step-up re-auth</td>
<td>Pelaku 5 trigger (semua wajib-2FA)</td>
<td>Re-entry password + TOTP, TTL 5 mnt, per-aksi (A-6); HANYA untuk 5 trigger</td>
<td>→ html/modal-stepup.html (belum dibuat)</td>
</tr>
</table>
### 1.2 Kandidat (MODULE_CANDIDATES)
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci</td>
<td>→ html</td>
</tr>
<tr>
<td>K1 List kandidat</td>
<td>Staf Input (miliknya+umum), Approver 👁️ antrian, Super Admin 👁️</td>
<td>Pagination 25; filter/sort whitelist (status_approval, status_ketersediaan, nama, umur); badge status_approval (Draft/Menunggu Tinjauan-BARU/REVISI/Disetujui/Ditolak/Diterapkan)</td>
<td>→ html/candidate-list.html (belum dibuat)</td>
</tr>
<tr>
<td>K2 Detail kandidat</td>
<td>Staf Input, Approver 👁️, Super Admin 👁️</td>
<td>Multi-section read-only; badge ketersediaan (Tersedia/Sedang Dipakai); akses Dokumen Peserta → buka link Google Drive privat + audit `IDENTITY_DOC_VIEWED` (link diungkap, bukan bukti file dibaca)</td>
<td>→ html/candidate-detail.html (belum dibuat)</td>
</tr>
<tr>
<td>K3 Form create/edit</td>
<td>Staf Input (Maker)</td>
<td>Save awal=`Draft`, tanpa NIK/pending; foto upload R2 ≤5MB; Dokumen Peserta=repeatable Jenis+URL Google Drive privat; maksimum satu revision Draft/menunggu aktif; panel **cek-kemiripan soft warning** (≥0.4 nama + DOB + kewarganegaraan; TIDAK memblok); NIK assign saat submit; optimistic `version`; **inline “Ajukan nilai lookup baru”** pada Select master-data (mis. Kewarganegaraan, Tempat Lahir/Provinsi/Kota/Kecamatan) → membuat `lookup_request` (audit `LOOKUP_REQUEST_SUBMITTED`); approval Super Admin di layar S2 (PRD §5.2/§7.8, ROLES §5.5). TIDAK berlaku untuk enum hardcode (jenis_kelamin/status_pernikahan/dominan_tangan/boolean fisik)</td>
<td>→ html/candidate-form.html (ANCHOR — DIBUILT; revisi v3: tambah inline lookup-request + Dokumen Identitas repeatable)</td>
</tr>
<tr>
<td>K4 Antrian tinjauan + keputusan</td>
<td>Approver Kandidat (Checker)</td>
<td>Approve / Reject (catatan wajib) — **RUTIN, bukan step-up**; tidak boleh edit data; verifikasi pending dalam transaksi</td>
<td>→ html/candidate-review.html (belum dibuat)</td>
</tr>
<tr>
<td>K5 Alur revisi (draft)</td>
<td>Staf Input</td>
<td>Baris draft-revisi (`parent_candidate_id`); blokir resubmit tanpa perubahan; setuju → `Diterapkan` (merged)</td>
<td>→ html/candidate-revision.html (belum dibuat)</td>
</tr>
<tr>
<td>K6 Anonimisasi PII</td>
<td>Super Admin</td>
<td>🔒 step-up; soft tombstone (irreversible); audit `CANDIDATE_ANONYMIZED`</td>
<td>→ html/candidate-anonymize.html (belum dibuat)</td>
</tr>
</table>
### 1.3 Kontainer Wawancara (MODULE_JOBS)
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci</td>
<td>→ html</td>
</tr>
<tr>
<td>W1 List kontainer</td>
<td>Asisten Manajer, Manajer Job 👁️, Super Admin 👁️</td>
<td>Pagination 25; kolom **Kode Kontainer** (`W-YYYY-NNNNN`; kosong saat Draft) — boleh jadi kolom sort/filter whitelist; badge status kontainer (Draft/Menunggu Approval/Aktif/Ditutup/Dibatalkan)</td>
<td>→ html/interview-list.html (belum dibuat)</td>
</tr>
<tr>
<td>W2 Detail kontainer + partisipasi</td>
<td>Asisten Manajer, Manajer Job 👁️, Super Admin 👁️</td>
<td>Daftar partisipasi + badge status_wawancara (8 status); soft warning target peserta (informatif); jumlah_peserta auto</td>
<td>→ html/interview-detail.html (belum dibuat)</td>
</tr>
<tr>
<td>W3 Wizard create</td>
<td>Asisten Manajer (Maker)</td>
<td>Form (perusahaan, posisi, jenis wawancara, jenis visa, tanggal, target); simpan Draft / submit; tanpa autosave (Draft = save point)</td>
<td>→ html/interview-create.html (belum dibuat)</td>
</tr>
<tr>
<td>W4 Approve/Reject kontainer</td>
<td>Manajer Job (Checker)</td>
<td>Setujui → Aktif / Tolak → Draft (catatan wajib) — **RUTIN, bukan step-up**</td>
<td>→ html/interview-approve.html (belum dibuat)</td>
</tr>
<tr>
<td>W5 Tutup kontainer</td>
<td>Maker: Asisten Manajer ⏳ · Checker: Manajer Job</td>
<td>**🔒 step-up** saat approve; irreversible; alasan 2 lapis; freeze partisipasi + markAvailable → Tersedia</td>
<td>→ html/interview-close.html (belum dibuat)</td>
</tr>
<tr>
<td>W6 Tarik kandidat (bulk)</td>
<td>Asisten Manajer</td>
<td>Hanya kandidat Disetujui+Tersedia; **maks 50/operasi**; SELECT FOR UPDATE; langsung efektif tanpa approval; markInUse</td>
<td>→ html/interview-pull.html (belum dibuat)</td>
</tr>
<tr>
<td>W7 Update status partisipasi</td>
<td>Asisten Manajer</td>
<td>Maju ketat (Menunggu Wawancara→Lulus→Proses Dokumen→Siap Dikirim); terminal Tidak Lolos/Mengundurkan Diri; jalur alami tanpa approval; **Terkirim hanya via approval batch Penempatan**</td>
<td>→ html/interview-status.html (belum dibuat)</td>
</tr>
<tr>
<td>W8 Keluarkan kandidat (expel)</td>
<td>Maker: Asisten Manajer ⏳ · Checker: Manajer Job</td>
<td>**🔒 step-up** saat approve; alasan 2 lapis; → Dikeluarkan; markAvailable</td>
<td>→ html/interview-expel.html (belum dibuat)</td>
</tr>
<tr>
<td>W9 Buat link tamu</td>
<td>Maker: Asisten Manajer ⏳ · Checker: Manajer Job</td>
<td>RUTIN; token digenerate saat approve (tolak → token tak lahir); masa berlaku wajib; kode tambahan opsional</td>
<td>→ html/interview-guestlink.html (belum dibuat)</td>
</tr>
</table>
### 1.4 Kontainer Penempatan (MODULE_PLACEMENT)
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci</td>
<td>→ html</td>
</tr>
<tr>
<td>P1 List kontainer</td>
<td>Asisten Manajer, Manajer Job 👁️, Super Admin 👁️</td>
<td>Pagination 25; kolom **Kode Kontainer** (`P-YYYY-NNNNN`; kosong saat Draft) — boleh jadi kolom sort/filter whitelist; badge status (Draft/Menunggu Approval/Aktif/Arsip/Dibatalkan)</td>
<td>→ html/placement-list.html (belum dibuat)</td>
</tr>
<tr>
<td>P2 Detail kontainer + placement_participants</td>
<td>Asisten Manajer, Manajer Job 👁️, Super Admin 👁️</td>
<td>Daftar placement_participants + badge status_penempatan (Bekerja/Selesai Kontrak/Mengundurkan Diri/Dikeluarkan); arsip = read-only</td>
<td>→ html/placement-detail.html (belum dibuat)</td>
</tr>
<tr>
<td>P3 Create kontainer</td>
<td>Asisten Manajer (Maker)</td>
<td>Perusahaan **immutable** setelah dibuat; Draft/submit; request perusahaan baru → antrian Super Admin</td>
<td>→ html/placement-create.html (belum dibuat)</td>
</tr>
<tr>
<td>P4 Kirim batch (Siap Dikirim)</td>
<td>Maker: Asisten Manajer ⏳ · Checker: Manajer Job</td>
<td>Eligible=`Siap Dikirim`  • availability `Sedang Dipakai`  • source aktif milik kandidat + tanpa placement `Bekerja`; **maks 50**; submit pending `PLACEMENT_BATCH`+payload; approve atomik: source→Terkirim, buat Bekerja, availability tetap Sedang Dipakai; tanpa flip `markInUse`</td>
<td>→ html/placement-batch.html (belum dibuat)</td>
</tr>
<tr>
<td>P5 Sub-flow Force-Majeur</td>
<td>Maker: Asisten Manajer ⏳ · Checker: Manajer Job</td>
<td>Kategori (lookup `kategori_force_majeur`) **+** alasan free-text **keduanya wajib** (CHECK DB); source_participation_id=NULL; **TANPA step-up**; RUTIN approval; audit `FORCE_MAJEUR_ADDED`</td>
<td>→ html/placement-forcemajeur.html (belum dibuat)</td>
</tr>
<tr>
<td>P6 Update status penempatan</td>
<td>Asisten Manajer (⏳ utk approval) · Manajer Job (Checker)</td>
<td>Selesai Kontrak = langsung (tanpa approval); Mengundurkan Diri = approval RUTIN + catatan; **Dikeluarkan/Cabut = 🔒 step-up**  • alasan 2 lapis; markAvailable + cek arsip setelah batch</td>
<td>→ html/placement-status.html (belum dibuat)</td>
</tr>
</table>
### 1.5 Domain Super Admin — Lookup, Master, Akun, Audit
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci</td>
<td>→ html</td>
</tr>
<tr>
<td>S1 CRUD lookup bilingual</td>
<td>Super Admin</td>
<td>**🔒 step-up** semua mutasi; `code` immutable; soft-disable (bukan hard-delete); audit `LOOKUP_*`</td>
<td>→ html/lookup-crud.html (belum dibuat)</td>
</tr>
<tr>
<td>S2 Antrian request lookup/perusahaan</td>
<td>Super Admin (approve); Staf Input/Asisten Manajer (ajukan)</td>
<td>**🔒 step-up** saat approve; audit `LOOKUP_REQUEST_*` / `COMPANY_*`</td>
<td>→ html/lookup-requests.html (belum dibuat)</td>
</tr>
<tr>
<td>S3 Master perusahaan</td>
<td>Super Admin</td>
<td>**🔒 step-up**; `nama_ja` wajib; soft-disable</td>
<td>→ html/company-master.html (belum dibuat)</td>
</tr>
<tr>
<td>S4 Kelola akun user</td>
<td>Super Admin</td>
<td>Buat akun (password sementara); **🔒 step-up** untuk assign/unassign role & nonaktifkan; lihat status 2FA per user</td>
<td>→ html/user-admin.html (belum dibuat)</td>
</tr>
<tr>
<td>S5 Audit log viewer</td>
<td>Super Admin (👁️ only)</td>
<td>Read-only; filter action_type/entity/aktor/tanggal; immutable</td>
<td>→ html/audit-log.html (belum dibuat)</td>
</tr>
</table>
### 1.6 Tamu / Guest (MODULE_GUEST_ACCESS) — Japanese-only, read-only
<table header-row="true">
<tr>
<td>Layar</td>
<td>Peran berhak</td>
<td>Aksi & guard kunci</td>
<td>→ html</td>
</tr>
<tr>
<td>G1 Gerbang token</td>
<td>Tamu (token)</td>
<td>Validasi berurutan: token ada → belum kadaluarsa → kontainer Aktif → kode tambahan (opsional); constant-time; **halaman tolak generik JP** bila gagal (tanpa bocor alasan)</td>
<td>→ html/guest-gate.html (belum dibuat)</td>
</tr>
<tr>
<td>G2 Input kode tambahan (opsional)</td>
<td>Tamu</td>
<td>Bila link punya kode; 5 gagal → lockout 15 mnt</td>
<td>→ html/guest-code.html (belum dibuat)</td>
</tr>
<tr>
<td>G3 GuestCandidateView</td>
<td>Tamu</td>
<td>**HANYA field whitelist Lampiran C** (nama alphabet+katakana, foto thumbnail signed URL, 歳, 男/女, level bahasa, SSW/mengemudi, bidang diminati, ringkasan pendidikan TANPA nama lembaga, ringkasan kerja TANPA nama perusahaan, dok `is_shareable`); **JP-only, read-only, TANPA upload/aksi**; pagination 25; sort/filter hanya kolom aman; audit `GUEST_ACCESS`</td>
<td>→ html/guest-view.html (belum dibuat)</td>
</tr>
</table>
---
## 2. Pola UI Lintas Layar
- **Pagination** server-side default **25** + sort/filter **hanya kolom whitelist** (§8.4). Kolom HIDE Tamu tak boleh jadi parameter sort/filter.
- **Notifikasi in-app**: ikon badge + dropdown, **polling ≤60 dtk tanpa websocket** (§8.4 + ARCH D6); bila Livewire → `wire:poll`.
- **Step-up modal** (A6): hanya 5 trigger; TTL 5 mnt per-aksi.
- **Soft warning** target peserta: informatif, **tidak memblok** (BR-TGT).
- **Inline request data baru** (§7.8): form ber-Select master-data (Kandidat, kontainer) menyediakan aksi “Ajukan data baru” → `lookup_request` / `company_request` (pending) → antrian approval Super Admin (S2/S3). Hanya untuk lookup Kelas-2 (admin-editable), BUKAN enum hardcode.
- **Badge status**: warna dipetakan ke STATUS_STATE_MACHINE; simpan enum kanonik, render glyph (D9).
- **Concurrency**: optimistic `version` → **409 + pesan konflik jelas + minta reload** (D8).
- **Pending-as-entity** (⏳): `pending_request` adalah sumber keputusan Checker untuk seluruh approval domain; pending+status submission dibuat satu transaksi; command sensitif memakai overlay tanpa mengubah target sebelum approve.
## 3. i18n (§9.4)
- Toggle ID/JP semua role internal; **Tamu = JP terkunci**.
- Glyph: 歳 / 男・女 / 既婚・未婚 / 右・左 / 有り・無し; tanggal `YYYY年MM月DD日`.
- Laravel `lang/id` + `lang/ja`; fallback berjenjang → bahasa lain → `code` mentah. **Jangan terjemahkan glyph ke Latin.**
- Simpan **enum kanonik / code**, bukan glyph (D9, GLOSSARY).
## 4. Catatan Engineering (Stitch hanya skeleton)
Stitch menghasilkan HTML+Tailwind skeleton; **aksesibilitas (label, kontras WCAG AA, fokus keyboard, ARIA), state interaksi, validasi, polling, optimistic-lock 409, signed-URL refresh, dan enforcement step-up ditambahkan di tahap engineering** — dicatat per layar saat verifikasi \[6d\]. HTML Stitch ditandai "Non-authoritative visual reference".
## 5. Keputusan Final UI
- **GATE-1 (stack) — TERKUNCI (user 2026-06-30)**: **A2 — Livewire 4 + Blade/Livewire custom + Tailwind 4**. Implikasi: HTML+Tailwind Stitch dipakai langsung; menjauh dari Filament panel penuh; hemat VPS (tanpa Node runtime/SSR).
- **Bahasa default — TERKUNCI**: **Indonesia (ID)** untuk antarmuka internal; Tamu tetap JP terkunci.
- **GAP-UI1 — Tempat Lahir vs Alamat (keputusan user 2026-06-30)**: `candidate.tempat_lahir` = **field khusus** (teks tersendiri); `provinsi_id`/`kota_kabupaten_id`/`kecamatan_id` = komponen **Alamat/domisili** (+ `alamat` teks), **bukan** Tempat Lahir. Berbeda dari frasa literal **PRD §5.2** (“Tempat Lahir (Kecamatan/Kota/Provinsi): dropdown”) → **perlu update PRD §5.2** + entri DECISIONS_LOG. Skema DATABASE_SCHEMA sudah mendukung.
- **GATE-2 (tema)** design system — diputuskan di \[5a\].
- **CSP nonce-vs-hash** (MODULE_GUEST_ACCESS, K4-SECURITY): dengan A2 (Blade/Livewire custom + Tailwind) arah ke **CSP nonce-based** via middleware/`spatie/laravel-csp`; difinalkan saat halaman Tamu dirakit.
- Footprint FE di VPS 2vCPU/2GB (build asset Node, SSR bila Inertia) — dianalisis di \[3\].
---
*Status: FINAL v1.0 — Batch B 2026-07-14. Selaras PRD v0.3.14 + DESIGN v1.1 + dokumen domain/schema final.*
