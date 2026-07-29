---
title: "PRD_Kakehashi_v0_3_14"
status: "0.3.14"
source_notion_title: "PRD_Kakehashi_v0_3_14"
exported_at: "2026-07-28"
authority_rank: "highest"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# PRODUCT REQUIREMENTS DOCUMENT
## Sistem Manajemen Kandidat & Job — Kumiai/TSK
**Nama Produk:** Kakehashi
**Disusun oleh:** Novan Esthi Bimo Santosa
**Tanggal:** 28 Juni 2026
**Versi:** 0.3.14 (draft)
> Dokumen ini adalah **sumber kebenaran** untuk pengembangan dengan pendekatan *vibe engineering*. Semua dokumen `.md` modul/teknis turunan mengacu pada PRD ini. Jika terjadi konflik antara dokumen turunan dengan PRD, **PRD yang berlaku**.
---
## 1. Riwayat Revisi Dokumen
<table header-row="true">
<tr>
<td>Versi</td>
<td>Tanggal</td>
<td>Perubahan</td>
<td>Oleh</td>
</tr>
<tr>
<td>0.1</td>
<td>(isi tanggal)</td>
<td>Draft awal</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>0.2</td>
<td>(isi tanggal)</td>
<td>Revisi role Manajer Job, status approval kandidat, peta akses modul, flow modul wawancara & penempatan lengkap, kebutuhan fungsional</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3**</td>
<td>**28/06/2026**</td>
<td>Penajaman state machine kontainer (Draft/Menunggu Approval/Dibatalkan), pola *pending-as-entity*, sub-flow force-majeur, role hardcode 6 final, 2FA = TOTP (RFC 6238), step-up re-auth ber-cakupan, kontrak antar-modul, spesifikasi cross-cutting (notifikasi/audit/i18n/file storage), Nomor Induk format final, cek-kemiripan `pg_trgm`, optimistic locking, retensi PII via anonimisasi, NFR angka final</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.1**</td>
<td>**29/06/2026**</td>
<td>Adendum keamanan turunan MODULE_AUTH (disetujui user 29/06/2026): (1) tambah enumerasi audit domain Auth di Lampiran A — `LOGIN_LOCKED_OUT`, `TWOFA_VERIFIED`, `TWOFA_FAILED`, `TWOFA_RECOVERY_USED`, `STEPUP_FAILED`; (2) tegaskan anonimisasi PII (§7.9) sebagai pemicu step-up re-auth ke-5 di §4.6 dan Lampiran D. Bersifat non-breaking, tidak mengubah kebenaran inti.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.2**</td>
<td>**29/06/2026**</td>
<td>Klarifikasi turunan BUSINESS_RULES (D1) & STATUS_STATE_MACHINE (disetujui user 29/06/2026): tegaskan Sub-flow Force-Majeur (§6.4 Sub-flow 2b) TIDAK memicu step-up re-auth — kontrol cukup approval Manajer Job + alasan wajib + audit dua lapis `FORCE_MAJEUR_ADDED`; ditambahkan catatan eksplisit di Lampiran D. Bersifat non-breaking, tidak mengubah kebenaran inti.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.3**</td>
<td>**29/06/2026**</td>
<td>Penyelesaian item terbuka §11 & sinkronisasi GAP-4 (disetujui user 29/06/2026): (1) kunci kebijakan retensi PII — simpan PII 5 tahun aktif sejak keterikatan terakhir, lalu anonimisasi (soft tombstone) dalam tenggang ≤ 1 tahun (rincian → DATA_RETENTION_AND_[PRIVACY.md](../technical/DATA_RETENTION_AND_PRIVACY.md), pending konfirmasi DPO); (2) cantumkan escape transition `Aktif→Dibatalkan` kontainer penempatan (guard count(placement_participant)=0, ber-approval Manajer Job) di §7.6 & Lampiran B.2 selaras STATUS_STATE_MACHINE GAP-4. Non-breaking.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.4**</td>
<td>**29/06/2026**</td>
<td>Penutupan dua item terbuka §11 (disetujui user 2026-06-29): kunci estimasi volume kandidat 1–3 tahun = **500–3.000** dan jumlah user internal total = **±15 user** sebagai angka perencanaan kapasitas (sebelumnya berstatus asumsi). Diselaraskan ke §9.3 Skalabilitas. Non-breaking — seluruh item §11 kini tertutup.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.5**</td>
<td>**29/06/2026**</td>
<td>Adendum audit turunan MODULE_CANDIDATES (disetujui user 2026-06-29): tambah enumerasi audit domain Kandidat di Lampiran A — `CANDIDATE_CREATED`, `CANDIDATE_SOFT_DELETED`, `CANDIDATE_RESTORED`, `IDENTITY_DOC_VIEWED`, `CANDIDATE_PHOTO_UPLOADED`, `SIMILARITY_MATCH_SHOWN`. Mencatat siklus penuh kandidat (buat draft, soft delete, restore), akses dokumen identitas (WAJIB audit), unggah foto, dan tampilan peringatan kemiripan. Non-breaking, tidak mengubah kebenaran inti.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.6**</td>
<td>**30/06/2026**</td>
<td>Adendum turunan DATABASE_SCHEMA (disetujui user 2026-06-30): (1) **Visibilitas Tamu (mengubah kebenaran inti):** nama perusahaan riwayat pekerjaan kini DITAMPILKAN ke Tamu — ringkasan Riwayat Pekerjaan di `GuestCandidateView` (Lampiran C) menjadi *Nama Perusahaan + Bidang Pekerjaan + durasi* (sebelumnya nama perusahaan disembunyikan). (2) Penegasan struktur dokumen identitas: hanya **Foto Zairyu Card (URL link, envelope encryption §9.1/§9.8)** sesuai §5.2 — tabel multi-dokumen turunan `candidate_identity_doc` & lookup `jenis_dokumen_identitas` dihapus di tingkat skema (menyelaraskan ke PRD, bukan mengubah PRD).</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.7**</td>
<td>**30/06/2026**</td>
<td>Klarifikasi redaksi turunan DATABASE_SCHEMA (disetujui user 2026-06-30, Opsi A): perjelas field **Foto Zairyu Card** di §5.2 — dari frasa ambigu "URL link" menjadi **unggah file (gambar/PDF) yang dienkripsi app-level (envelope encryption) sebelum upload (§9.1/§9.8) lalu diakses via signed URL pendek (5–15 mnt)**, bukan URL eksternal yang diketik staf. Menyelaraskan redaksi §5.2 dengan §9.1/§9.8 (yang sejak awal mewajibkan upload+enkripsi) dan dengan kolom `zairyu_*` (envelope) pada skema fisik. Non-breaking — menegaskan kebenaran inti yang sudah ada.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.8**</td>
<td>**30/06/2026**</td>
<td>Restrukturisasi field geografis Data Card Kandidat (disetujui user 2026-06-30, Opsi B) — **mengubah kebenaran inti**: **Tempat Lahir** kini **dropdown Kota/Kabupaten saja** (sebelumnya hierarki Kecamatan/Kota/Provinsi); field **Provinsi/Kota-Kabupaten/Kecamatan dipindahkan menjadi komponen Alamat terstruktur**, dan **Alamat Lengkap** berubah dari teks bebas menjadi **dropdown bertingkat Provinsi → Kota/Kabupaten → Kecamatan + teks bebas untuk detail (jalan/RT/RW)**. Menggantikan bagian struktur keputusan Q2 MODULE_LOOKUP_DATA. Diselaraskan ke DATABASE_SCHEMA (GAP-DB8) & MODULE_LOOKUP_DATA.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.9**</td>
<td>**01/07/2026**</td>
<td>Pembalikan arahan "dokumen identitas" + arsitektur file Google Drive (disetujui user 2026-07-01) — **mengubah kebenaran inti**: "dokumen identitas" ternyata = **koleksi Dokumen Peserta** (KTP/KK/Ijazah/Kartu Zairyu/dll) berupa field berulang (Jenis Dokumen dropdown + URL Dokumen), pola seperti Riwayat Kerja/Pendidikan. Semua **URL file kini link Google Drive privat** ("tidak diset public", URL input), KECUALI **foto wajah** (tetap upload R2) & **video** (embed URL). **Envelope encryption/R2 signed URL untuk dokumen dihapus**. Foto Zairyu Card jadi salah satu jenis Dokumen Peserta. Diselaraskan ke DATABASE_SCHEMA (candidate_document, GAP-DB9), MODULE_LOOKUP_DATA (lookup jenis_dokumen), MODULE_CANDIDATES.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.10**</td>
<td>**11/07/2026**</td>
<td>Adendum audit & guard turunan verifikasi UI S1–S5 (disetujui user 2026-07-11), non-breaking: (1) tambah `action_type` audit — Lookup `LOOKUP_UPDATED`/`LOOKUP_REACTIVATED`; Company `COMPANY_CREATED`/`COMPANY_UPDATED`/`COMPANY_REACTIVATED`; User/Role `USER_UPDATED`/`USER_REACTIVATED`/`PASSWORD_RESET_BY_ADMIN` (Lampiran A). (2) `audit_log` ditambah kolom `actor_role_snapshot` (snapshot peran aktor saat kejadian, bukan join live) & `user_agent`; ditegaskan `action_type` = backed-enum aplikasi (bukan CHECK keras DB). (3) Guard manajemen user: larangan menonaktifkan/menurunkan Super Admin aktif terakhir & larangan aksi ke diri sendiri (§4.2/§6.1 + BUSINESS_RULES §8A BR-USR). Diselaraskan ke DATABASE_SCHEMA §7/§5.7 & BUSINESS_RULES.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.11**</td>
<td>**12/07/2026**</td>
<td>Pembalikan kebijakan whitelist Tamu untuk layar DETAIL kandidat (arahan atasan via user 2026-07-12) — **mengubah kebenaran inti**: `GuestCandidateView` kini **berjenjang** (Lampiran C direstrukturisasi). **Daftar (G2) tetap pseudonim** (identifier = kode kandidat/Nomor Induk, tanpa nama & foto). **Detail (G3)** kini menampilkan **Nama Alphabet + Katakana**, **Foto** (signed URL R2 TTL 15 mnt, di-scope sesi token), **Riwayat Pekerjaan penuh** (Nama Perusahaan + Penanggung TSK/Kumiai + Bidang + tanggal), dan **Riwayat Pendidikan penuh** (Jenis + Jurusan + Nama Lembaga + tanggal). Tanpa DDL struktural (data sudah ada). Tetap HIDE: tanggal lahir mentah, alamat/tempat lahir, email/telepon/Line, imigrasi, keluarga, dokumen peserta, fisik/kesehatan, IQ/MTK/psikotes, video (default OFF). Ditambah audit granular `GUEST_DETAIL_VIEWED` (Lampiran A) untuk melacak pembukaan detail kandidat oleh Tamu. Diselaraskan ke MODULE_GUEST_ACCESS §3/§4/§5/§12, DATABASE_SCHEMA §3.3/§7, DATA_RETENTION_AND_PRIVACY.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.12**</td>
<td>**13/07/2026**</td>
<td>Upgrade baseline infra MVP (disetujui user 2026-07-13): VPS dari **2vCPU/2GB** → **4 vCPU / 8 GB RAM** (single VPS, no HA). **Redis diizinkan co-located** di VPS yang sama untuk cache, session, queue, rate limit. Queue production = **Redis + 2 worker** (bukan database + 1 worker). Notifikasi tetap in-app + polling ≤60 dtk (tanpa WebSocket). Unique constraint + transaksi DB tetap sumber kebenaran anti-duplikasi. Diselaraskan ke PROJECT_OVERVIEW, TECH_VERSION_SEED, ARCHITECTURE, DATABASE_SCHEMA, MODULE_AUTH/LOOKUP/GUEST/PLACEMENT, BACKUP_AND_RECOVERY, DEPLOYMENT.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.13**</td>
<td>**14/07/2026**</td>
<td>Penutupan audit Batch A (D-01–D-07): transfer normal Wawancara→Penempatan mempertahankan availability `Sedang Dipakai`; approval domain selain `lookup_request` dan `company_request` memakai `pending_request` sebagai sumber keputusan Checker; partial unique satu participation Wawancara aktif/kandidat; lifecycle Kandidat menambah `Draft`  • merge revision aggregate atomik; anonimisasi diblok selama proses/pending aktif; login identifier final = email; soft-delete/restore Kandidat tidak diekspos di MVP. Ditegaskan pula: bisnis+audit DB commit dahulu, queue/email Redis after-commit dan gagal enqueue tidak me-rollback transaksi bisnis.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
<tr>
<td>**0.3.14**</td>
<td>**14/07/2026**</td>
<td>Penutupan audit Batch B: Redis mixed-workload=`noeviction`; queue/email after-commit; Nomor Induk menjadi identifier Guest; kandidat anonim dikeluarkan dari Guest read-model; audit link Google Drive didefinisikan jujur + permission manual; PII audit Auth diminimalkan; `FM_REJECTED` dikanonisasi; rumus akhir kontrak inklusif dikunci; restore test menjadi gate go-live; authority UI dan dokumen current-state dibersihkan.</td>
<td>Novan Esthi Bimo Santosa</td>
</tr>
</table>
> Update tabel ini setiap kali dokumen direvisi.
---
## 2. Latar Belakang & Tujuan
### 2.1 Masalah yang Ingin Diselesaikan
Kumiai ini merupakan organisasi yang baru berdiri dan belum memiliki sistem pengelolaan data kandidat yang berjalan. Observasi praktik umum industri (termasuk kompetitor) menunjukkan pola masalah berulang ketika proses ini dikelola manual: data kandidat tersebar tanpa sistem terpusat (komunikasi cabang ke pusat lewat telepon/pesan, rawan tidak konsisten), proses validasi data tidak terlacak (sulit diketahui siapa menyetujui, kapan, dengan alasan apa), penjadwalan wawancara rawan duplikasi penarikan kandidat karena tidak ada penanda status real-time, dan riwayat kandidat (wawancara, hasil, penempatan kerja) tidak tersimpan terstruktur sehingga sulit ditelusuri. Produk ini dibangun agar kumiai baru ini dapat beroperasi dengan sistem terpusat sejak awal, melompati fase manual yang rawan masalah.
### 2.2 Tujuan Produk
- Menyediakan satu sistem terpusat untuk mengelola data kandidat dari input awal sampai resmi tersedia, dengan alur validasi dua tahap yang jelas dan terlacak.
- Mencegah duplikasi penarikan kandidat ke lebih dari satu proses wawancara secara bersamaan, lewat penanda status kandidat yang konsisten.
- Menyimpan riwayat lengkap setiap kandidat — proses wawancara yang pernah diikuti, hasilnya, sampai riwayat penempatan kerja — secara permanen untuk catatan operasional dan mudah ditelusuri.
### 2.3 Metrik Keberhasilan
Karena kumiai ini baru berdiri dan belum memiliki data historis pembanding, metrik berikut ditetapkan sebagai target operasional sejak sistem mulai dipakai:<br>- Tidak ada kasus kandidat yang ditarik ke lebih dari satu job aktif secara bersamaan (validasi status kandidat berjalan sesuai desain).<br>- Setiap kandidat yang resmi disetujui memiliki jejak validasi lengkap dan dapat ditelusuri (siapa input, siapa menyetujui, kapan).<br>- Setelah 3 bulan operasional, dicatat baseline waktu rata-rata proses kandidat dari input sampai disetujui — untuk dijadikan dasar target perbaikan iterasi berikutnya.
---
## 3. Ruang Lingkup
### 3.1 Termasuk dalam MVP
- Manajemen data kandidat (CRUD + alur validasi dua tahap)
- Manajemen kontainer wawancara (state machine 5 status final — lihat Lampiran B)
- Manajemen kontainer penempatan (state machine 5 status final — lihat Lampiran B)
- **Sub-flow force-majeur** penempatan langsung tanpa wawancara ulang (gated, ber-approval, alasan wajib)
- Akses tamu (read-only) untuk perusahaan Jepang melihat daftar peserta wawancara per kontainer
- Cross-cutting infrastructure: notifikasi in-app + polling, audit log terpusat, i18n ID/JP, file storage (foto wajah di R2; dokumen peserta = link Google Drive privat; video = embed URL)
### 3.2 Tidak Termasuk dalam MVP (Out of Scope)
> Mencegah scope creep dan pengingat eksplisit ke agent AI supaya tidak membangun hal yang belum diminta.
- Modul Keuangan
- Modul Kelas/Pelatihan
- Modul Report tahunan
- Data kandidat multi-bahasa penuh (UI bilingual ID/JP tetap MVP)
- Modul Generate CV (mengisi template Excel dari data kandidat, multi-template)
- Manajemen **tipe role** (tambah/hapus jenis role) — post-MVP; MVP memakai 6 role hardcode
- Notifikasi push/websocket real-time — post-MVP; MVP near-real-time via polling
---
## 4. Pengguna & Role
### 4.1 Daftar Role & Peta Akses per Modul
**Enam role berikut bersifat hardcode untuk MVP** — tidak dapat ditambah/dihapus melalui dashboard.
<table header-row="true">
<tr>
<td>Role</td>
<td>Deskripsi Tugas</td>
<td>Bahasa Antarmuka</td>
</tr>
<tr>
<td>Staf Input</td>
<td>Input data kandidat ke sistem (Maker modul Kandidat)</td>
<td>Indonesia/Jepang</td>
</tr>
<tr>
<td>Approver Kandidat</td>
<td>Memeriksa data hasil input Staf Input, menyetujui atau menolak dengan catatan — tidak input/edit data. Akses terbatas hanya di modul Kandidat.</td>
<td>Indonesia/Jepang</td>
</tr>
<tr>
<td>Asisten Manajer</td>
<td>Semua aksi eksekusi di modul Wawancara dan Penempatan: membuat kontainer (Draft/submit), menarik/mengirim kandidat, update status, keluarkan kandidat, tutup kontainer, buat link tamu</td>
<td>Indonesia/Jepang</td>
</tr>
<tr>
<td>Manajer Job</td>
<td>Memeriksa dan menyetujui/menolak semua aksi Asisten Manajer — tidak mengeksekusi aksi apapun secara langsung (pure Checker)</td>
<td>Indonesia/Jepang</td>
</tr>
<tr>
<td>Super Admin</td>
<td>Mengelola akun pengguna (rutin); CRUD data referensi/lookup; melihat log audit. Read-only di semua modul operasional.</td>
<td>Indonesia/Jepang</td>
</tr>
<tr>
<td>Tamu</td>
<td>Melihat daftar peserta wawancara, read-only, akses via link bertoken per kontainer — bukan akun sistem</td>
<td>Jepang</td>
</tr>
</table>
<table header-row="true">
<tr>
<td>Role</td>
<td>Modul Kandidat</td>
<td>Modul Wawancara</td>
<td>Modul Penempatan</td>
</tr>
<tr>
<td>Staf Input</td>
<td>Maker</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Approver Kandidat</td>
<td>Checker</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Asisten Manajer</td>
<td>—</td>
<td>Maker</td>
<td>Maker</td>
</tr>
<tr>
<td>Manajer Job</td>
<td>—</td>
<td>Checker</td>
<td>Checker</td>
</tr>
<tr>
<td>Super Admin</td>
<td>Read-only</td>
<td>Read-only</td>
<td>Read-only</td>
</tr>
</table>
### 4.2 Kewenangan Super Admin
- **Kelola akun pengguna:** tambah user baru, nonaktifkan user keluar, **tugaskan/lepas role yang sudah ada** ke/dari user.
- CRUD field data referensi/lookup (nilai dropdown yang bersifat label deskriptif — bukan status state machine yang tetap hardcode).
- Lihat log audit seluruh sistem — tidak ikut membuat atau menyetujui aksi operasional apapun.
- **Bukan kewenangan Super Admin di MVP:** menambah/menghapus jenis role (role bersifat hardcode; manajemen tipe role dipertimbangkan post-MVP).
- **Guard keamanan akun (ditegakkan server-side, v0.3.10):** (a) **tidak boleh menonaktifkan atau menurunkan/mengubah peran Super Admin ****`Aktif`**** terakhir** — minimal satu Super Admin aktif wajib ada; (b) **tidak boleh menonaktifkan atau mengubah peran diri sendiri** (harus oleh Super Admin lain). Reset password oleh admin menerbitkan password sementara + `must_change_password=TRUE` (audit `PASSWORD_RESET_BY_ADMIN`, berbeda dari `PASSWORD_CHANGED` self-service). Detail aturan & pesan error → BUSINESS_RULES §8A (BR-USR).
### 4.3 Sifat Akses Tamu Eksternal
> Tamu eksternal BUKAN user dengan role internal — bukan akses publik tanpa batas. Akses diberikan lewat link bertoken unik per kontainer wawancara, dengan masa berlaku terbatas.
- Tidak memerlukan akun/login sistem internal
- Akses dibatasi hanya pada satu kontainer wawancara yang dirujuk oleh token
- Akses bersifat read-only — tidak bisa mengubah data apapun
- Link memiliki masa berlaku (kadaluarsa otomatis setelah periode tertentu)
- Pengiriman link: via email resmi. Kode tambahan opsional dikirim terpisah via WA.
- Satu kontainer boleh memiliki lebih dari satu link tamu aktif sekaligus
- Field yang ditampilkan = whitelist eksplisit `GuestCandidateView` (Lampiran C)
### 4.4 Manajemen Sesi
Pengguna diberikan session 30 menit; jika tidak ada aktivitas, sistem logout otomatis dan meminta login kembali.
### 4.5 Autentikasi & 2FA (TOTP)
Sistem memakai **2FA berbasis TOTP (RFC 6238)** sebagai faktor kedua. Kompatibel dengan **Google Authenticator, Authy, atau aplikasi TOTP lain — tidak bergantung pada akun Google**.
- **Login flow:** email + password → kode TOTP
- **Setup:** scan QR (otpauth URI), konfirmasi kode, simpan **kode cadangan (backup codes)**
- **Wajib** untuk role: Approver Kandidat, Manajer Job, Super Admin
> Catatan istilah: “Google Auth” yang muncul di v0.2 secara eksplisit didefinisikan ulang sebagai TOTP. Bukan login berbasis akun Google (OAuth).
### 4.6 Step-up Re-Authentication
Dibedakan tegas dari 2FA login:<br>- **2FA login** = faktor kedua saat masuk sistem (mengaktifkan sesi).<br>- **Step-up re-auth** = re-autentikasi (password + TOTP ulang) **per aksi** untuk operasi sensitif/irreversible.
**Daftar lengkap aksi yang memicu step-up re-auth:**<br>1. Ubah role / nonaktifkan akun user<br>2. Tutup kontainer wawancara (irreversible)<br>3. Keluarkan kandidat (wawancara & penempatan — jalur paksa, alasan dua lapis)<br>4. Kelola data referensi/lookup atau konfigurasi sistem<br>5. Anonimisasi PII kandidat — penghapusan/anonimisasi data identitas pribadi (§7.9); hanya Super Admin
**Tidak** memicu step-up re-auth: aksi approval rutin (Approver Kandidat menyetujui/menolak kandidat, Manajer Job menyetujui/menolak kontainer/aksi standar) — cukup 2FA login + sesi aktif.
---
## 5. Field Modul
### 5.1 Modul Super Admin
**Field Super Admin — Kelola Akun Pengguna**<br>- Nama: teks bebas<br>- Email: email unik, **satu-satunya identifier login MVP** (dinormalisasi lowercase untuk login/throttle); tidak ada username terpisah<br>- Role: dropdown dari **6 role hardcode** (Staf Input/Approver Kandidat/Asisten Manajer/Manajer Job/Super Admin/Tamu — Tamu tidak diberikan ke akun internal) — bisa pilih lebih dari satu kalau ada user yang merangkap (disarankan MVP: satu user = satu role)<br>- Status akun: Aktif/Nonaktif (state machine, tidak dihapus)<br>- Password: di-hash, tidak pernah ditampilkan; flag wajib ganti password saat login pertama<br>- Status 2FA: Belum Disetup/Aktif — wajib untuk role Approver Kandidat, Manajer Job, Super Admin<br>- Tanggal dibuat: otomatis<br>- Dibuat oleh: referensi ke Super Admin yang membuat<br>- Tanggal nonaktif (kalau berlaku) + dinonaktifkan oleh: otomatis, untuk audit
**Field Super Admin — Kelola Data Referensi/Lookup** *(skema bilingual)*<br>- Kategori lookup: menunjukkan dropdown mana (misalnya Level Bahasa Jepang, Jenis Kemampuan Kerja)<br>- **`label_id`**: label dalam Bahasa Indonesia<br>- **`label_ja`**: label dalam Bahasa Jepang<br>- **`code`**: nilai/kode internal yang disimpan di database (kanonik, tidak berubah)<br>- Status: Aktif/Nonaktif (tidak dihapus — data lama tetap utuh)<br>- Urutan tampil (opsional): angka untuk mengatur urutan muncul di dropdown
**Field Super Admin — Log Audit** *(hanya dilihat, tidak diisi manual)*<br>- `actor_id`: pelaku (nullable; untuk akses Tamu = null)<br>- `actor_role_snapshot`: peran aktor **saat kejadian** — snapshot teks, bukan join live ke `users` (peran bisa berubah); null untuk Tamu/sistem<br>- `action_type`: enumerasi backed-enum aplikasi, daftar tetap (lihat Lampiran A)<br>- `entity_type` + `entity_id`: target aksi<br>- `detail` (JSONB): kontekstual per `action_type` (skema lihat Lampiran A)<br>- `ip`: opsional (terutama Tamu & login)<br>- `user_agent`: opsional (forensik viewer audit)<br>- `created_at`: otomatis
### 5.2 Modul Manajemen Kandidat
**Field Data Card Kandidat**<br>- **Nomor Induk:** format **`K-YYYY-NNNNN`** (5-digit sequence per tahun). Digenerate **saat submit pertama** (status → Menunggu Tinjauan-BARU) lewat **PostgreSQL sequence** atau counter-table dengan row lock + **unique constraint** sebagai jaring pengaman. Tidak boleh duplikat.<br>- Foto: input image, disimpan di Cloudflare R2 (bucket privat)<br>- Nama Alphabet: teks bebas<br>- Nama Katakana: teks bebas<br>- Kewarganegaraan: dropdown master data + mekanisme request data baru<br>- Asal Rekrutmen: dropdown master data (diisi Super Admin)<br>- Tanggal Lahir: date, tampil format Jepang saat view JP<br>- Tempat Lahir: dropdown master data **Kota/Kabupaten** + mekanisme request data baru<br>- Umur: otomatis dihitung dari Tanggal Lahir, render `歳` saat view JP<br>- Jenis Kelamin: enum kanonik (`M`/`F`); render `男`/`女` saat view JP, `Laki-laki`/`Perempuan` saat view ID<br>- Status Pernikahan: enum kanonik (`MARRIED`/`SINGLE`); render `既婚`/`未婚` (JP) atau `Menikah`/`Lajang` (ID)<br>- Agama: dropdown master data<br>- Alamat Lengkap: dropdown master data bertingkat **Provinsi → Kota/Kabupaten → Kecamatan** + teks bebas untuk detail (jalan/RT/RW)<br>- Email: input email<br>- Phone Number: input nomor telepon dengan pilihan kode negara<br>- Line ID: input Line ID
**Field Data Fisik**<br>- Tinggi Badan (cm), Berat Badan (kg), Lingkar Perut (cm)<br>- Golongan Darah, Ukuran Sepatu, Mata Kiri/Kanan: dropdown master data<br>- Dominan Tangan: enum (`RIGHT`/`LEFT`) → render `右`/`左` (JP)<br>- Pembatasan Makanan, Buta Warna, Merokok, Minum Sake, Riwayat Penyakit Kronis, Riwayat Operasi: enum (`YES`/`NO`) → render `有り`/`無し` (JP)
**Field Riwayat Pendidikan (maks 5 isian)**<br>- Jenis Pendidikan: dropdown master data<br>- Nama Lembaga: teks bebas<br>- Jurusan: dropdown master data<br>- Tanggal Masuk/Keluar: date, format Jepang saat view JP
**Field Riwayat Pekerjaan (maks 5 isian)**<br>- Nama Perusahaan: teks bebas<br>- Nama Penanggung Jawab TSK/Kumiai: teks bebas (opsional)<br>- Bidang Pekerjaan: dropdown master data<br>- Tanggal Masuk/Keluar: date, format Jepang saat view JP
**Field Kualifikasi**<br>- Kualifikasi Bahasa Inggris: Jenis (dropdown), Tanggal Akuisisi, Score, URL File<br>- Kualifikasi Bahasa Jepang: Jenis (dropdown), Tanggal Akuisisi, Score, URL File<br>- Kualifikasi Keahlian Jepang/SSW (maks 8): Jenis (dropdown), Tanggal Akuisisi, URL File<br>- Kualifikasi Mengemudi (maks 5): Jenis (dropdown), Tanggal Akuisisi<br>- Kualifikasi Keahlian Lainnya (maks 5): Jenis (dropdown), Tanggal Akuisisi, URL File
**Field Promosi Diri**<br>- Video Jikoshokai: **embed URL** (mis. YouTube/Drive embed)<br>- Video Keahlian: **embed URL**<br>- IQ Score, MTK Score: number<br>- Bidang Diminati: dropdown master data<br>- Final Laporan Psikotes: teks bebas
**Field Informasi Keluarga (maks 10 isian)**<br>- Status Keluarga: dropdown master data<br>- Nama Keluarga: teks bebas<br>- Tanggal Lahir Keluarga: date<br>- Umur: otomatis + `歳` saat JP
**Field Kontak Keluarga yang Bisa Dihubungi (hanya 1)**<br>- Nama Keluarga: teks bebas<br>- Status: dropdown master data<br>- No Handphone: input nomor telepon dengan kode negara
**Field Imigrasi (semua opsional)**<br>- No Paspor: number<br>- No Zairyu Card: number<br>- Alamat Zairyu Card: teks bebas<br>- Jenis Visa Saat Ini: dropdown master data<br>- Foto Zairyu Card: dicatat sebagai salah satu dokumen di **Dokumen Peserta** (jenis `Kartu Zairyu`) — berupa **link Google Drive privat** (tidak diset public). Lihat *Field Dokumen Peserta* di bawah & §9.8.
**Field Dokumen Peserta (berulang — seperti Riwayat Pekerjaan/Pendidikan)**<br>- Jenis Dokumen: dropdown master data (KTP, Kartu Keluarga, Ijazah, Kartu Zairyu, Paspor, SKCK, dll)<br>- URL Dokumen: **link Google Drive** — dokumen disimpan di Google Drive & **tidak diset public** (URL input, bukan unggah file ke aplikasi). Berlaku untuk **semua jenis URL file**.<br>- Catatan: teks bebas (opsional)<br>- Dokumen bersifat internal (HIDE Tamu); akses dokumen sensitif (mis. KTP, Kartu Zairyu) dicatat audit `IDENTITY_DOC_VIEWED`.
**Field Administratif Sistem**<br>- Status ketersediaan: `Tersedia` / `Sedang Dipakai` — diubah oleh modul Wawancara & Penempatan via service publik modul Kandidat, **bukan UPDATE langsung lintas modul**<br>- Status approval data: `Draft` / `Menunggu Tinjauan-BARU` / `Menunggu Tinjauan-REVISI` / `Disetujui` / `Ditolak` / `Diterapkan` (internal revision)<br>- *Draft*: belum disubmit; `nomor_induk = null`; belum masuk antrian Checker dan belum memiliki pending approval<br>- *Menunggu Tinjauan-BARU*: data baru pertama kali disubmit<br>- *Menunggu Tinjauan-REVISI*: draft revisi disubmit ulang menunggu keputusan<br>- *Disetujui*: data aktif dipakai operasional<br>- *Ditolak*: perlu revisi & submit ulang<br>- Tanggal input: otomatis<br>- Diinput oleh: referensi user Staf Input<br>- Disetujui oleh: referensi user Approver<br>- Catatan penolakan terakhir: teks<br>- Catatan tambahan: teks bebas<br>- **`version`**** (integer):** kolom optimistic locking (lihat 7.10)
> **Catatan revisi:** revisi data Disetujui membuat baris revision terpisah (FK ke kandidat utama) berstatus awal `Draft`, `nomor_induk=null`, dan snapshot seluruh field mutable+child collections. Maksimum satu revision Draft/menunggu aktif per main candidate. Data utama tetap aktif sampai revision disetujui. Lihat §6.2.
### 5.3 Modul Wawancara
**Field Kontainer Wawancara**<br>- Kode Kontainer: format `W-YYYY-NNNNN` — identifier human-readable yang digenerate sistem **saat submit pertama** (kosong saat Draft), unik & tidak berubah setelah di-assign; counter per-tahun (zona JST). Field turunan sistem, bukan input manual.<br>- Nama/identifikasi kontainer: teks<br>- Nama perusahaan tujuan: referensi master data perusahaan (`nama_ja`, `nama_romaji`, `nama_id`)<br>- Jenis Wawancara: enum `OFFLINE` / `ONLINE`<br>- Jenis Visa: dropdown master data<br>- Tanggal wawancara: tanggal<br>- Jumlah peserta wawancara: angka otomatis dari kandidat yang masuk — tidak diisi manual<br>- Jumlah peserta yang diterima (target dari perusahaan): angka manual — **informatif, tanpa hard-block**; UI menampilkan progres `Diterima X / Target Y` dengan soft warning jika melebihi<br>- Catatan/deskripsi job: teks bebas (Bahasa Jepang)<br>- **Status kontainer (5 status final — Lampiran B):** `Draft` / `Menunggu Approval` / `Aktif` / `Ditutup` / `Dibatalkan`<br>- Dibuat oleh: referensi Asisten Manajer<br>- Disetujui oleh: referensi Manajer Job<br>- Tanggal dibuat, Tanggal disetujui: otomatis<br>- **`version`**** (integer):** kolom optimistic locking
**Data Riwayat Partisipasi (****`participation`**** — per kandidat di dalam kontainer)**<br>- `candidate_id`, `interview_container_id`: FK<br>- **`status_wawancara`****:** `Menunggu Wawancara` / `Lulus` / `Proses Dokumen` / `Siap Dikirim` / `Terkirim` / `Tidak Lolos` / `Mengundurkan Diri` / `Dikeluarkan` *(hardcode, state machine — lihat Lampiran B)*<br>- Tanggal masuk kontainer: otomatis<br>- Tanggal update status terakhir: otomatis<br>- Catatan alasan: teks; **wajib** untuk `Dikeluarkan`, opsional untuk lain<br>- **`version`**** (integer):** optimistic locking
**Data Akses Tamu (****`guest_link`****)**<br>- Label link: teks (identifikasi internal)<br>- `token`: string unik acak, **digenerate setelah disetujui Manajer Job**<br>- `interview_container_id`: FK (satu token = satu kontainer)<br>- Tanggal kadaluarsa: tanggal/waktu — **wajib**<br>- Kode tambahan: opsional, dikirim terpisah via WA<br>- Status link: `Menunggu Approval` / `Aktif` / `Kadaluarsa`<br>- Dibuat oleh, disetujui oleh: referensi user
**Data Log Akses Tamu (****`guest_access_log`****)**<br>- `token_id`: FK<br>- Waktu akses: otomatis<br>- IP pengakses: teks (opsional)
### 5.4 Modul Penempatan
**Data Kontainer Penempatan**<br>- Kode Kontainer: format `P-YYYY-NNNNN` — identifier human-readable yang digenerate sistem **saat submit pertama** (kosong saat Draft), unik & tidak berubah setelah di-assign; counter per-tahun (zona JST). Field turunan sistem, bukan input manual.<br>- Nama/identifikasi kontainer: teks<br>- Perusahaan tujuan: FK master data perusahaan (wajib, satu kontainer = satu perusahaan, **tidak bisa diubah setelah dibuat**)<br>- **Status kontainer (5 status final — Lampiran B):** `Draft` / `Menunggu Approval` / `Aktif` / `Arsip` / `Dibatalkan` *(Arsip hanya otomatis; tidak ada penutupan manual)*<br>- Dibuat oleh: referensi Asisten Manajer<br>- Disetujui oleh: referensi Manajer Job<br>- Tanggal dibuat, Tanggal disetujui, Tanggal arsip: otomatis<br>- **`version`**** (integer):** optimistic locking
**Data Per Kandidat di Kontainer Penempatan (****`placement_participants`****)**<br>- `candidate_id`, `placement_container_id`: FK<br>- **`source_participation_id`**** (nullable):** FK ke baris partisipasi wawancara asal — `null` untuk kandidat yang masuk via **sub-flow force-majeur**<br>- Jenis Visa: dropdown master data — per kandidat, bukan per kontainer<br>- Tanggal mulai kerja: tanggal<br>- Durasi kontrak: angka (bulan)<br>- Tanggal berakhir kontrak: default inklusif = **tanggal mulai + durasi bulan − 1 hari**; boleh di-override manual, tetapi hasil wajib ≥ tanggal mulai<br>- **`status_penempatan`****:** `Bekerja` / `Selesai Kontrak` / `Mengundurkan Diri` / `Dikeluarkan` *(hardcode, state machine — berbeda dari **`status_wawancara`** meski beberapa nama mirip — lihat Lampiran B)*<br>- Tanggal status final: otomatis saat status terminal tercapai<br>- Catatan alasan: teks; **wajib** untuk `Mengundurkan Diri` dan `Dikeluarkan`<br>- Disetujui oleh: referensi Manajer Job (khusus aksi yang butuh approval)<br>- **`version`**** (integer):** optimistic locking
**Master Data Perusahaan**<br>- `nama_ja`: teks Bahasa Jepang (wajib)<br>- `nama_romaji`: opsional<br>- `nama_id`: opsional<br>- Bidang industri/jenis pekerjaan: dropdown master data<br>- Negara: default Jepang<br>- Status: Aktif/Nonaktif (tidak dihapus)<br>- Dikelola Super Admin. Asisten Manajer bisa mengajukan request perusahaan baru langsung dari form kontainer — Super Admin yang approve.
---
## 6. Flow Modul
### 6.1 Modul Super Admin
**Flow Kelola User**<br>1. **Membuat akun baru.** Super Admin klik Tambah User Baru, isi: nama, email, role (dari 6 yang hardcode). Sistem generate password sementara. Akun tersimpan dengan status Aktif, flag wajib ganti password = true, 2FA belum disetup.<br>2. **Login pertama.** Sistem paksa ganti password → paksa setup 2FA (scan QR, konfirmasi kode, simpan kode cadangan) → akses dashboard sesuai role.<br>3. **Dashboard daftar user per role.** Tabel: Role, daftar user, status 2FA tiap user (Sudah/Belum). Penting untuk memantau role yang wajib 2FA.<br>4. **Mengubah role.** **Memicu step-up re-auth** (password + TOTP ulang) sebelum diterapkan. Audit log mencatat `ROLE_CHANGED`.<br>5. **User keluar/resign.** Status akun → Nonaktif (tidak dihapus). User Nonaktif tidak bisa login. Riwayat aksi tetap di audit log. **Memicu step-up re-auth**.
1. **Reaktivasi akun.** Super Admin mengaktifkan kembali akun Nonaktif → status Aktif. Audit `USER_REACTIVATED`. **Memicu step-up re-auth**.
2. **Reset password (oleh admin).** Super Admin menerbitkan password sementara + set wajib ganti password. Audit `PASSWORD_RESET_BY_ADMIN` (bukan untuk akun sendiri).
3. **Edit data user** (nama/email) → audit `USER_UPDATED`.
4. **Guard (server-side, v0.3.10).** Aksi Nonaktifkan/Ubah Peran diblok bila (a) target = Super Admin aktif terakhir, atau (b) target = diri sendiri. Pesan error `USR_LAST_SUPERADMIN` / `USR_SELF_DEACTIVATE` / `USR_SELF_ROLE` (BUSINESS_RULES §8A).
**Flow Kelola Data Referensi/Lookup**<br>Super Admin membuka menu Kelola Data Referensi → pilih kategori → tambah nilai baru (isi `label_id`, `label_ja`, `code`, urutan tampil) atau nonaktifkan nilai lama. Nilai yang sudah dipakai **tidak bisa dihapus**, hanya dinonaktifkan. **Memicu step-up re-auth** untuk perubahan konfigurasi.
> Yang dikelola Super Admin = label deskriptif. **Status state machine** (status partisipasi, status penempatan, status kontainer, role) **tetap hardcode di kode**.
### 6.2 Modul Kandidat
**Flow Input Kandidat Baru**<br>1. Staf Input membuka form kandidat baru; save awal menghasilkan status **`Draft`** (`nomor_induk=null`) dan belum masuk antrian Checker.<br>2. Sebelum submit, sistem cek kemiripan **Nama (trigram ****`pg_trgm`****, similarity ≥ 0.4) + Tanggal Lahir (exact) + Kewarganegaraan (exact)** mencakup **semua data termasuk draft**. Jika ada kemiripan, tampilkan peringatan + minta konfirmasi eksplisit.<br>3. Isi seluruh field yang diperlukan → Submit.<br>4. Dalam satu transaksi, sistem men-generate Nomor Induk (`K-YYYY-NNNNN`), mengubah status → `Menunggu Tinjauan-BARU`, dan membuat satu `pending_request` tipe `CANDIDATE_NEW`.<br>5. Approver Kandidat menerima notifikasi in-app.
**Flow Approval Kandidat**<br>Approver Kandidat membuka antrian. Hanya bisa **setuju atau tolak dengan catatan wajib** — tidak bisa edit data. Disetujui → status `Disetujui`, kandidat masuk operasional. Ditolak → status `Ditolak`, Staf Input dapat notifikasi + catatan alasan.
**Flow Revisi Data Ditolak**<br>Staf Input membuka data ditolak, perbaiki, submit ulang → status `Menunggu Tinjauan-REVISI`. **Sistem blok submit ulang jika tidak ada perubahan** dari versi sebelumnya.
**Flow Update Data Kandidat yang Sudah Disetujui**<br>Update membuat **baris revision terpisah** dengan FK ke kandidat utama, status awal `Draft`, `nomor_induk=null`, dan snapshot seluruh field mutable + child collections. Maksimum satu revision berstatus Draft/menunggu aktif per kandidat utama. Saat submit, revision → `Menunggu Tinjauan-REVISI` dan `pending_request` tipe `CANDIDATE_REVISION` dibuat dalam transaksi yang sama. Saat disetujui, field mutable + seluruh child collections mengganti snapshot utama dalam satu transaksi; Nomor Induk, availability, dan operational history tidak berubah; revision → `Diterapkan`. Jika ditolak, data utama tidak berubah.
### 6.3 Modul Wawancara
**Sub-flow 1 — Buat Kontainer Wawancara**<br>Asisten Manajer membuka daftar kontainer dan klik Buat Kontainer Baru. Isi form (nama, perusahaan tujuan, jenis wawancara, jenis visa, tanggal, target peserta diterima, catatan JP).<br>- Pilihan **Simpan sebagai Draft** → status `Draft`, bisa diedit lagi.<br>- Pilihan **Submit** → status `Menunggu Approval`. Manajer Job review.<br>- **Disetujui** → status `Aktif`, siap menerima kandidat.<br>- **Ditolak (catatan wajib)** → status kembali ke `Draft`, Asisten Manajer perbaiki & submit ulang. Sistem **blok submit ulang jika tidak ada perubahan** dari versi yang ditolak.<br>- **Batalkan** kontainer `Draft` atau `Menunggu Approval` (oleh pembuat) → status `Dibatalkan` (terminal, sebelum pernah Aktif, tidak butuh approval).<br>- Kontainer `Draft`, `Menunggu Approval`, atau `Dibatalkan` **tidak bisa menerima kandidat dalam kondisi apapun**.
**Sub-flow 2 — Tarik Kandidat ke Kontainer**<br>Asisten Manajer membuka kontainer `Aktif` → klik Tarik Kandidat. Filter daftar: hanya kandidat `Disetujui`; default tampilkan `Tersedia`, kandidat `Sedang Dipakai` tetap tampil tapi disabled dengan label jelas.<br>Pilih satu/banyak kandidat (bulk) → Tarik. **Backend menggunakan ****`SELECT ... FOR UPDATE`** untuk mengunci baris kandidat saat validasi: semua kandidat dipilih masih `Tersedia` dan `Disetujui`. Jika lolos, sistem membuat baris partisipasi per kandidat dengan status `Menunggu Wawancara`, dan **memanggil service publik modul Kandidat** untuk mengubah ketersediaan → `Sedang Dipakai`. Aksi langsung efektif **tanpa approval**.
**Sub-flow 3 — Akses Tamu Eksternal**
*Sisi internal — buat link tamu:*<br>Asisten Manajer membuka kontainer `Aktif` → Buat Link Tamu. Isi: label, masa berlaku (wajib), kode tambahan (opsional). Submit → status link `Menunggu Approval`. Manajer Job review. Disetujui → **token digenerate sistem** + status link `Aktif`. Asisten Manajer kirim link via email resmi; kode tambahan via WA terpisah. Satu kontainer boleh banyak link aktif. **Link yang ditolak tidak menghasilkan token.**
*Sisi tamu — akses link:*<br>Tamu buka URL bertoken. Sistem validasi berurutan: token ada → belum kadaluarsa → kontainer masih `Aktif`. Jika ada kode tambahan, minta input. Salah → akses ditolak.<br>Lolos → tamu melihat halaman read-only sesuai whitelist `GuestCandidateView` (Lampiran C). Setiap akses dicatat di audit (`GUEST_ACCESS`: token, IP, waktu, kontainer).
**Sub-flow 4 — View Kandidat dalam Kontainer**<br>- *Lapisan 1 — daftar kandidat:* nama, foto thumbnail, `status_wawancara` saat ini, bidang keahlian utama, level Bahasa Jepang. Filter by status, sort by nama/tanggal ditarik. Tombol aksi disesuaikan role.<br>- *Lapisan 2 — detail satu kandidat:* gabungan dua sumber. Dari **service publik modul Kandidat**: data master (identitas, fisik, riwayat, dokumen) — **read-only total**, tidak ada tombol edit. Dari modul Wawancara: status partisipasi, riwayat perubahan, tombol aksi sesuai role. Asisten Manajer **tidak bisa edit data master** dari halaman ini.
**Sub-flow 5 — Pola Maker-Approval (pending sebagai entitas)**<br>Berlaku konsisten untuk semua aksi yang butuh approval. Asisten Manajer submit aksi → **dibuat ****`pending_request`**** (entitas tersendiri)**, status agregat **tidak berubah** sampai disetujui. UI menampilkan label overlay (mis. *Menunggu Persetujuan Penutupan*). Manajer Job review → setuju (aksi efektif, audit log, status agregat berubah) atau tolak (catatan wajib). Asisten Manajer perbaiki + submit ulang; sistem blok submit ulang jika tidak ada perubahan.
**Sub-flow 6 — Update Status Partisipasi**
*Jalur alami (langsung efektif tanpa approval):*<br>Asisten Manajer pilih status berikutnya dari dropdown — hanya menampilkan transisi valid dari status saat ini (lihat Lampiran B). Catatan opsional. Aksi langsung efektif. Sistem catat riwayat (`PARTICIPATION_STATUS_CHANGED`).<br>- Transisi maju ketat: `Menunggu Wawancara` → `Lulus` → `Proses Dokumen` → `Siap Dikirim` → `Terkirim`. **Tidak ada rollback**.<br>- Terminal `Tidak Lolos` / `Mengundurkan Diri` bisa diakses dari status aktif manapun.<br>- Saat status menjadi `Siap Dikirim`, kandidat **siap ditarik** ke modul Penempatan — **belum otomatis berpindah**.
*Jalur paksa — Dikeluarkan (butuh approval + step-up re-auth):*<br>Asisten Manajer pilih Keluarkan Kandidat dari form khusus, **wajib isi alasan**. Submit → status partisipasi belum berubah, label *Menunggu Keputusan Pengeluaran* (entitas pending). Manajer Job review, **memicu step-up re-auth** + wajib isi catatan saat setuju/tolak. Disetujui → status `Dikeluarkan`, ketersediaan → `Tersedia`, audit dua lapis. Ditolak → status tetap.
**Sub-flow 7 — Tutup Kontainer (irreversible)**<br>Asisten Manajer membuka kontainer `Aktif` → Tutup Kontainer. UI dialog konfirmasi: jumlah kandidat masih aktif + peringatan irreversible. **Wajib isi alasan**. Submit → status kontainer tetap `Aktif`, label overlay *Menunggu Persetujuan Penutupan*.<br>Manajer Job review. **Memicu step-up re-auth.** Disetujui → status → `Ditutup` (irreversible). Kandidat masih aktif: status partisipasi difreeze, ketersediaan → `Tersedia`, flag visual. Ditolak → kontainer tetap `Aktif`.
### 6.4 Modul Penempatan
**Sub-flow 1 — Buat Kontainer Penempatan**<br>Identik pola dengan kontainer wawancara (Draft / Menunggu Approval / Aktif / Dibatalkan), kecuali:<br>- Perusahaan tujuan dari master data (wajib, **tidak bisa diubah setelah dibuat**)<br>- Jika perusahaan belum ada, Asisten Manajer ajukan request perusahaan baru langsung dari dalam form (request masuk antrian Super Admin)<br>- **Tidak ada penutupan manual** — arsip otomatis (Sub-flow 6)<br>- Satu kontainer = satu perusahaan; satu kontainer bisa menerima kandidat dari banyak kontainer wawancara
**Sub-flow 2 — Kirim Kandidat ke Penempatan (jalur normal, batch)**<br>Asisten Manajer membuka kontainer `Aktif` → Tambah Kandidat. Eligible hanya bila `status_wawancara = Siap Dikirim`, availability **`Sedang Dipakai`**, source participation aktif tersebut milik kandidat yang sama, dan kandidat belum memiliki placement berstatus `Bekerja`. **Jangan** memakai filter `Siap Dikirim + Tersedia`. Asal kontainer wawancara ditampilkan.<br>Pilih bulk → wajib isi per kandidat: jenis visa, tanggal mulai kerja, durasi kontrak, tanggal berakhir kontrak. Field bisa diisi seragam dulu lalu diedit per kandidat. Submit membuat `pending_request` tipe `PLACEMENT_BATCH` dengan payload snapshot seluruh kandidat+field penempatan; status sumber belum berubah.<br>Manajer Job review **seluruh batch atomik** — disetujui atau ditolak seluruhnya. Saat disetujui, satu transaksi mengunci/revalidasi candidate + source participation, mengubah `status_wawancara` → `Terkirim`, membuat `placement_participants` status `Bekerja` dengan `source_participation_id`, dan memindahkan ownership ikatan aktif. Availability **tetap ****`Sedang Dipakai`**; `markInUse()` tidak dipakai untuk flip `Tersedia→Sedang Dipakai`, hanya assertion ownership/state. Gagal satu kandidat → rollback seluruh batch.
**Sub-flow 2b — Tambah Kandidat Langsung (Force-Majeur)** *(baru di v0.3)*<br>Aksi terpisah dari Sub-flow 2, dengan label & ikon khas (“Tambah Langsung” / “Force Majeur”) untuk menegaskan ini jalur pengecualian.<br>- Filter sumber: kandidat **`Tersedia`**** + ****`Disetujui`** (tanpa syarat `Siap Dikirim`)<br>- **`source_participation_id`**** = null** (tidak ada referensi wawancara)<br>- **Wajib isi alasan** (mengapa kandidat masuk tanpa pipeline wawancara)<br>- **Butuh approval Manajer Job** (entitas pending request)<br>- Saat disetujui (atomik): `placement_participants` status `Bekerja`, ketersediaan → `Sedang Dipakai`. Audit log dua lapis (`EXPEL` style audit untuk force-majeur).<br>- **Tidak memicu step-up re-auth** — cukup approval Manajer Job + alasan wajib; jalur force-majeur sengaja **dikecualikan** dari daftar pemicu step-up (Lampiran D). *(klarifikasi v0.3.2)*
**Sub-flow 3 — View Kandidat dalam Kontainer Penempatan**<br>Mengikuti pola modul Wawancara: Lapisan 1 (daftar) + Lapisan 2 (detail gabungan dari modul Kandidat via service publik + data penempatan).
**Sub-flow 4 — Update Status Penempatan**
*Jalur 1 — Selesai Kontrak (langsung efektif tanpa approval):*<br>UI konfirmasi singkat → status → `Selesai Kontrak`, ketersediaan → `Tersedia`. Sistem cek: jika kandidat aktif terakhir, kontainer diarsip otomatis (Sub-flow 6).
*Jalur 2 — Mengundurkan Diri (butuh approval):*<br>Asisten Manajer wajib isi alasan → pending request. Manajer Job review + catatan wajib. Disetujui → status berubah, kandidat `Tersedia`, cek arsip. Ditolak → tetap `Bekerja`. Butuh approval karena ada kontrak kerja resmi.
*Jalur 3 — Dikeluarkan (butuh approval + step-up re-auth + alasan dua lapis):*<br>Identik pola dengan jalur paksa di Wawancara. Setelah disetujui: status berubah, kandidat `Tersedia`, cek arsip.
> Setelah kandidat kembali `Tersedia` dari jalur manapun, dapat masuk proses baru — langsung ke kontainer penempatan baru (force-majeur) atau melalui kontainer wawancara baru.
**Sub-flow 5 — Pola Maker-Approval Modul Penempatan**<br>Identik dengan modul Wawancara (Sub-flow 5 di 6.3).
**Sub-flow 6 — Arsip Kontainer Penempatan (otomatis)**<br>Tidak ada aksi manual, tidak ada approval. **Trigger:** kandidat terakhir berstatus `Bekerja` mencapai status terminal. Sistem pengecekan dilakukan **setelah seluruh batch diproses**, bukan per kandidat dalam loop (mencegah arsip prematur).<br>Saat arsip: status kontainer → `Arsip`, timestamp otomatis, audit log. Semua data tetap dilihat. Kontainer Arsip tidak bisa menerima kandidat baru atau update status. **Irreversible.**
---
## 7. Aturan Bisnis (Business Rules)
### 7.1 Status Ketersediaan Kandidat
**Makna final:** `Tersedia` berarti tidak ada ikatan Wawancara aktif dan tidak ada placement `Bekerja`; `Sedang Dipakai` berarti tepat satu ikatan aktif. Kandidat `Siap Dikirim` tetap `Sedang Dipakai`. Pada batch normal Placement, ikatan aktif dipindahkan secara atomik dari source participation ke placement participant tanpa window `Tersedia`. Force-Majeur tetap mulai dari `Tersedia + Disetujui` lalu memanggil `markInUse()`.
<table header-row="true">
<tr>
<td>Status</td>
<td>Arti</td>
<td>Bisa Ditarik ke Kontainer?</td>
</tr>
<tr>
<td>Tersedia</td>
<td>Kandidat tidak sedang terikat proses wawancara atau penempatan aktif manapun</td>
<td>Ya</td>
</tr>
<tr>
<td>Sedang Dipakai</td>
<td>Kandidat sedang dalam proses wawancara aktif atau masa kontrak penempatan yang berjalan</td>
<td>Tidak</td>
</tr>
</table>
> Field ketersediaan disimpan di Kandidat tapi **ditulis hanya melalui service publik modul Kandidat** yang dipanggil oleh modul Wawancara & Penempatan. Tidak ada UPDATE lintas-modul ke kolom ini secara langsung.
### 7.2 Status Partisipasi Wawancara (`status_wawancara`)
Lihat tabel transisi lengkap di Lampiran B. Ringkasan:
<table header-row="true">
<tr>
<td>Status</td>
<td>Arti</td>
<td>Status Kandidat Berikutnya</td>
</tr>
<tr>
<td>Menunggu Wawancara</td>
<td>Sudah ditarik, menunggu jadwal</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Lulus</td>
<td>Dinyatakan lulus sesi wawancara</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Proses Dokumen</td>
<td>Proses kelengkapan dokumen pasca-wawancara</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Siap Dikirim</td>
<td>Dokumen lengkap, siap dikirim ke perusahaan Jepang</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Terkirim</td>
<td>Resmi dikirim — status final di modul Wawancara, masuk modul Penempatan</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Tidak Lolos</td>
<td>Gugur dari proses wawancara</td>
<td>Tersedia</td>
</tr>
<tr>
<td>Mengundurkan Diri</td>
<td>Mundur atas kemauan sendiri dari wawancara</td>
<td>Tersedia</td>
</tr>
<tr>
<td>Dikeluarkan</td>
<td>Dikeluarkan administratif (jalur paksa, approval Manajer Job + step-up re-auth)</td>
<td>Tersedia</td>
</tr>
</table>
### 7.3 Status Penempatan (`status_penempatan`)
<table header-row="true">
<tr>
<td>Status</td>
<td>Arti</td>
<td>Status Kandidat Berikutnya</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Aktif bekerja di perusahaan tujuan — satu-satunya status aktif</td>
<td>Sedang Dipakai</td>
</tr>
<tr>
<td>Selesai Kontrak</td>
<td>Kontrak selesai normal (tanpa approval)</td>
<td>Tersedia</td>
</tr>
<tr>
<td>Mengundurkan Diri</td>
<td>Mundur dari kontrak kerja resmi (butuh approval — ada kontrak resmi)</td>
<td>Tersedia</td>
</tr>
<tr>
<td>Dikeluarkan</td>
<td>Dikeluarkan dari kontrak kerja (butuh approval + step-up re-auth + alasan dua lapis)</td>
<td>Tersedia</td>
</tr>
</table>
> **`status_wawancara`**** dan ****`status_penempatan`**** adalah dua state machine terpisah** di database. Jangan digabung meski beberapa nama mirip.
### 7.4 Aturan Maker-Approval (Pending sebagai Entitas)
- **`pending_request`**** adalah sumber keputusan Checker untuk seluruh approval domain selain ****`lookup_request`**** dan ****`company_request`.** Untuk domain yang memakai `pending_request`, status agregat tetap mencerminkan lifecycle (`Menunggu Approval` / `Menunggu Tinjauan-*`), dan status submission serta pending dibuat dalam satu transaksi.
- Tipe minimum: `CANDIDATE_NEW`, `CANDIDATE_REVISION`, `IC_CREATE`, `PC_CREATE`, `PLACEMENT_BATCH`, `IC_CLOSE`, `IC_EXPEL`, `GUEST_LINK`, `PC_CANCEL_ACTIVE`, `PLACEMENT_RESIGN`, `PLACEMENT_EXPEL`, `FORCE_MAJEUR`.
- Untuk domain tersebut, maksimum satu pending aktif per `(type, target_type, target_id)`; Checker memverifikasi status masih `pending` di transaksi keputusan.
- Payload snapshot wajib untuk `PLACEMENT_BATCH`, `FORCE_MAJEUR`, expel, resign, dan cancel.
- **`lookup_request`**** dan ****`company_request`**** adalah pengecualian eksplisit:** status pada masing-masing tabel request adalah sumber keputusan flow tersebut; keduanya tidak membuat baris `pending_request` dan tidak menambah `LOOKUP_REQUEST` atau `COMPANY_REQUEST` ke `PendingType`.
- Pengecualian hanya berlaku pada entitas sumber keputusan. Kedua flow tetap memakai RBAC, `StepUpService`, `AuditLogger`, `NotificationService`, transaksi dan rollback, after-commit, self-decision guard, serta anti-double-decision dari fondasi Wave 1.
- Maker selalu memperbaiki data yang ditolak.
- Checker hanya bisa **menyetujui atau menolak dengan catatan** — tidak mengedit data.
- Sistem menahan submit ulang jika **tidak ada perubahan** dari versi yang ditolak.
- Catatan alasan wajib untuk setiap penolakan, tanpa pengecualian.
- Untuk aksi Dikeluarkan & Tutup Kontainer: alasan **wajib dua lapis** — dari Maker saat submit + dari Checker saat approve/tolak.
- **Pola “Pending sebagai entitas”:** saat Maker submit aksi sensitif, status agregat **tidak berubah** sampai disetujui. Sistem membuat `pending_request` (entitas) yang tampil sebagai **overlay label** di UI (mis. *Menunggu Keputusan Pengeluaran*, *Menunggu Persetujuan Penutupan*). Status agregat berubah hanya saat approval. Berlaku konsisten untuk: pembuatan kontainer, penutupan kontainer, keluarkan kandidat, kirim batch penempatan, mengundurkan diri (penempatan), buat link tamu, draft revisi kandidat, force-majeur.
### 7.5 Aturan Kontainer Wawancara
- 5 status final: `Draft`, `Menunggu Approval`, `Aktif`, `Ditutup`, `Dibatalkan` (Lampiran B).
- Tidak pernah dihapus — riwayat permanen.
- Penutupan manual hanya dari status `Aktif`, irreversible, butuh approval + step-up re-auth, alasan wajib.
- `Dibatalkan` hanya dari `Draft` / `Menunggu Approval` (sebelum pernah `Aktif`).
- Kandidat aktif saat kontainer ditutup: status partisipasi difreeze, ketersediaan → `Tersedia`.
- Kandidat gagal/mundur: ketersediaan → `Tersedia`, riwayat partisipasi tetap.
- Satu kontainer boleh punya lebih dari satu link tamu aktif sekaligus.
### 7.6 Aturan Kontainer Penempatan
- 5 status final: `Draft`, `Menunggu Approval`, `Aktif`, `Arsip`, `Dibatalkan` (Lampiran B).
- Satu kontainer = satu perusahaan, wajib, tidak bisa diubah setelah dibuat.
- Satu kontainer bisa menerima kandidat dari lebih dari satu kontainer wawancara.
- **Arsip otomatis** saat kandidat aktif terakhir mencapai status terminal — tidak ada penutupan manual.
- Kontainer `Arsip` read-only, riwayat permanen, irreversible.
- **Escape ****`Aktif→Dibatalkan`**** (GAP-4):** kontainer `Aktif` boleh dibatalkan **hanya bila** `count(placement_participant)=0` (belum ada kandidat) **dan ber-approval Manajer Job** — mencegah deadlock kontainer `Aktif` yang kosong. Selaras STATUS_STATE_MACHINE GAP-4.
- **Force-majeur** = sub-flow eksplisit (6.4 Sub-flow 2b), gated dengan alasan wajib + approval.
### 7.7 Aturan Akses Tamu Eksternal
- Setiap link tamu terikat ke **satu token unik + satu kontainer wawancara**.
- Token tidak boleh dapat ditebak (string acak panjang, **bukan** ID kontainer).
- Field yang ditampilkan = whitelist eksplisit `GuestCandidateView` (Lampiran C), **berjenjang: daftar (G2) pseudonim vs detail (G3) memuat Nama + Foto + Riwayat Kerja/Pendidikan penuh** (v0.3.11).
- Field yang tidak ditampilkan: catatan internal, status partisipasi detail, data keluarga, email, no. telp, alamat & tempat lahir, imigrasi (Zairyu/Paspor/visa), dokumen peserta, data fisik/kesehatan, IQ/MTK & psikotes.
- Setiap akses dicatat di audit (`GUEST_ACCESS`: token, IP, waktu, kontainer); **pembukaan detail kandidat (G3)** dicatat granular (`GUEST_DETAIL_VIEWED`: token, kandidat, kontainer, waktu) untuk forensik eksposur PII (v0.3.11).
- Link otomatis tidak bisa diakses setelah masa berlaku habis atau kontainer ditutup/dibatalkan.
### 7.8 Aturan Data Referensi/Lookup
- **Label deskriptif** (Level Bahasa Jepang, Jenis Kemampuan Kerja, dst) dikelola Super Admin lewat dashboard. Skema bilingual: `label_id`, `label_ja`, `code` internal.
- **Status state machine** (status partisipasi, status penempatan, status kontainer, role) **tetap hardcode** — Super Admin tidak bisa mengubah.
- Nilai yang sudah dipakai tidak bisa dihapus — hanya dinonaktifkan.
- Mekanisme request data baru: Staf Input / Asisten Manajer ajukan request sebagai bagian dari form yang sedang diisi → Super Admin approve.
### 7.9 Aturan Penghapusan Data & Retensi PII
- **Tidak ada hard delete di operasi normal** untuk catatan operasional (kontainer, partisipasi, audit log, riwayat kandidat).
- **Soft-delete/restore Kandidat tidak diekspos pada MVP:** tidak ada route, tombol, atau permission aktif. `deleted_at` dan event soft-delete/restore hanya reserved/deferred.
- Anonimisasi hanya boleh bila tidak ada participation Wawancara aktif, placement `Bekerja`, pending request terbuka, atau revision Draft/menunggu aktif; availability harus `Tersedia`. Semua guard direvalidasi dalam transaksi tepat sebelum tombstone.
- **PII (data identitas pribadi)** tunduk pada **kebijakan retensi + hak penghapusan**:
	- Penghapusan PII **via anonimisasi terkontrol** (soft tombstone) — bukan DELETE fisik — agar integritas referensial & jejak audit terjaga.
	- Hanya **Super Admin + step-up re-auth**; dicatat di audit log.
	- Dasar hukum: APPI (Jepang), UU PDP (Indonesia). Consent saat intake, purpose limitation, data minimization (sudah tercermin di `GuestCandidateView`).
- Akun user: dinonaktifkan, tidak dihapus.
- Nilai lookup: dinonaktifkan, tidak dihapus.
- Detail jadwal retensi & prosedur anonimisasi → `DATA_RETENTION_AND_PRIVACY.md`.
### 7.10 Aturan Konkurensi
- **Optimistic locking** via kolom `version (integer)` pada agregat mutable: kandidat (+ draft revisinya), kontainer wawancara, kontainer penempatan, baris partisipasi, baris placement_participants.
- Database menegakkan **maksimum satu participation Wawancara aktif per kandidat** dengan partial unique index untuk status `Menunggu Wawancara`, `Lulus`, `Proses Dokumen`, `Siap Dikirim`.
- Database menegakkan maksimum satu revision Draft/menunggu aktif per kandidat utama dan satu pending aktif per `(type,target_type,target_id)`. Setiap UPDATE menyertakan WHERE `version = current`; konflik → **HTTP 409 Conflict** + minta reload UI.
- **Pessimistic locking** (`SELECT ... FOR UPDATE`) **khusus** penarikan kandidat bulk ke kontainer wawancara — mencegah race condition penarikan ganda.
- **Anti double-decision:** saat Checker bertindak, sistem memverifikasi sumber keputusan yang berlaku masih `pending` **di dalam transaksi yang sama**—`pending_request.status`, `lookup_request.status`, atau `company_request.status`—sebelum menerapkan keputusan.
- **Transaksi atomik lintas modul (sama-DB):** batch kirim penempatan + force-majeur diproses dalam satu DB transaction yang menyentuh `participation`, `placement_participants`, dan service publik Kandidat (untuk ketersediaan).
### 7.11 Enumerasi Event Audit
Daftar lengkap `action_type` + skema `detail` JSONB → Lampiran A.
---
## 8. Kebutuhan Fungsional
Format: **Sebagai \[role\], saya ingin \[aksi\], supaya \[tujuan\].** Diikuti constraint teknis yang wajib diperhatikan agent coding.
### 8.1 Modul Kandidat
**Input data kandidat baru**<br>Sebagai Staf Input, saya ingin menyimpan Draft kandidat lalu mengirimnya untuk ditinjau, supaya data kandidat masuk ke sistem dengan alur validasi yang terlacak.<br>*Constraint:* Save Draft belum membuat NIK/pending/antrian. Saat submit, cek kemiripan **Nama (trigram ****`pg_trgm`****, similarity ≥ 0.4) + TglLahir (exact) + Kewarganegaraan (exact)** sebelum submit, mencakup semua data termasuk draft. Jika ada kemiripan → peringatan + konfirmasi eksplisit. Nomor Induk format `K-YYYY-NNNNN` digenerate atomik saat submit, unique constraint sebagai jaring pengaman.
**Tinjauan dan keputusan data kandidat**<br>Sebagai Approver Kandidat, saya ingin meninjau data yang menunggu persetujuan lalu menyetujui atau menolak dengan catatan, supaya hanya data valid yang masuk operasional.<br>*Constraint:* Approver hanya setuju/tolak — tidak edit data. Penolakan wajib catatan alasan. Status approval 4 varian. Verifikasi `pending_request` masih pending dalam transaksi (anti double-approval).
**Revisi data ditolak**<br>Sebagai Staf Input, saya ingin memperbaiki data kandidat yang ditolak dan mengirim ulang.<br>*Constraint:* Revisi membuat **baris draft terpisah** — data utama tetap aktif sampai draft disetujui. Sistem blok submit ulang jika tidak ada perubahan dari versi yang ditolak.
**Update data kandidat yang sudah disetujui**<br>Sebagai Staf Input, saya ingin memperbarui data yang sudah disetujui.<br>*Constraint:* Update membuat revision snapshot berstatus awal `Draft`; maksimum satu revision Draft/menunggu aktif. Submit → `Menunggu Tinjauan-REVISI` + pending. Approve mengganti field mutable+seluruh child collections atomik tanpa mengubah NIK, availability, dan operational history. Optimistic locking via `version`.
**Lihat data kandidat**<br>Sebagai Approver Kandidat, saya ingin melihat data lengkap kandidat dalam mode read-only.<br>*Constraint:* Approver tidak memiliki akses ke modul Wawancara atau Penempatan.
### 8.2 Modul Wawancara
**Buat kontainer wawancara (Draft / Submit / Batalkan)**<br>Sebagai Asisten Manajer, saya ingin membuat kontainer wawancara dan menyimpannya sebagai draft atau mengirimkannya untuk disetujui, supaya saya bisa menyiapkan kontainer bertahap.<br>*Constraint:* 5 status final (Lampiran B). Draft & Menunggu Approval tidak menerima kandidat. Penolakan kembali ke Draft. Pembatalan hanya dari Draft / Menunggu Approval. Sistem blok submit ulang tanpa perubahan.
**Tarik kandidat ke kontainer wawancara**<br>Sebagai Asisten Manajer, saya ingin menarik satu atau beberapa kandidat ke kontainer aktif.<br>*Constraint:* Hanya kandidat `Tersedia + Disetujui` yang bisa ditarik. Backend `SELECT FOR UPDATE` saat validasi (anti race). Pemanggilan service publik Kandidat untuk ubah ketersediaan. Langsung efektif tanpa approval.
**Update status partisipasi — jalur alami***Constraint:* Transisi maju ketat sesuai Lampiran B. Terminal `Tidak Lolos` / `Mengundurkan Diri` dari status aktif manapun. Tidak ada rollback. Langsung efektif tanpa approval.
**Keluarkan kandidat dari kontainer wawancara (jalur paksa)***Constraint:* Wajib alasan dari Asisten Manajer + alasan dari Manajer Job. **Memicu step-up re-auth** untuk Manajer Job. Status berubah hanya setelah disetujui. Pending sebagai entitas.
**Tutup kontainer wawancara (irreversible)***Constraint:* Wajib alasan + approval + **step-up re-auth**. UI dialog konfirmasi dengan jumlah kandidat masih aktif. Pending sebagai entitas (status tetap `Aktif` sampai disetujui → `Ditutup`). Kandidat aktif difreeze, ketersediaan → `Tersedia`.
**Approve atau tolak aksi Asisten Manajer (modul wawancara)***Constraint:* Manajer Job hanya setuju/tolak dengan catatan. Penolakan wajib alasan. Verifikasi pending di transaksi.
**Buat link tamu untuk kontainer wawancara***Constraint:* Butuh approval Manajer Job sebelum token digenerate. Satu kontainer boleh banyak link aktif. Kirim manual via email; kode tambahan via WA terpisah.
**Lihat kontainer dan daftar kandidat (Super Admin read-only)***Constraint:* Super Admin read-only di semua modul operasional.
### 8.3 Modul Penempatan
**Buat kontainer penempatan***Constraint:* Satu kontainer = satu perusahaan, tidak bisa diubah. Request perusahaan baru via form (Super Admin approve). 5 status final (Lampiran B). Tidak ada penutupan manual.
**Kirim kandidat ke penempatan (batch normal)***Constraint:* Hanya source participation `Siap Dikirim` yang masih memiliki availability `Sedang Dipakai`, milik kandidat yang sama, dan tanpa placement `Bekerja`. Batch atomik memakai payload snapshot pending. Setelah approval: `status_wawancara` → `Terkirim`, baris `placement_participants` dibuat dengan `source_participation_id`, dan availability tetap `Sedang Dipakai` (transfer ownership; bukan flip via `markInUse`).
**Tambah kandidat langsung — Force-Majeur** *(baru di v0.3)*<br>Sebagai Asisten Manajer, saya ingin menambahkan kandidat langsung ke kontainer penempatan tanpa wawancara untuk kasus force majeur, supaya kandidat siap pakai bisa ditempatkan cepat tanpa proses ulang yang tidak relevan.<br>*Constraint:* Sumber `Tersedia + Disetujui`, **wajib alasan**, **butuh approval Manajer Job**, `source_participation_id = null`. Audit dua lapis.
**Update status penempatan***Constraint:* Selesai Kontrak langsung efektif. Mengundurkan Diri butuh approval (kontrak resmi). Dikeluarkan butuh approval + step-up re-auth + alasan dua lapis. Cek arsip otomatis dilakukan setelah seluruh batch diproses.
**Approve atau tolak aksi Asisten Manajer (modul penempatan)***Constraint:* Identik pola modul Wawancara.
### 8.4 Lintas Modul
**Pagination/filter/sort untuk daftar**<br>Sebagai pengguna sistem, saya ingin daftar (kandidat, kontainer, partisipasi) dipaginasi & terfilter, supaya UI tetap responsif di volume besar.<br>*Constraint:* Server-side pagination, **page size default 25**. Sort/filter pakai whitelist kolom ber-index. Berlaku juga untuk halaman Tamu.
**Notifikasi in-app**<br>Sebagai pengguna, saya ingin menerima notifikasi atas event yang relevan dengan peran saya.<br>*Constraint:* In-app notification + polling ≤60 dtk. Tanpa websocket di MVP. Email kritis via **queue Redis + 2 worker** (Supervisor).
---
## 9. Kebutuhan Non-Fungsional
### 9.1 Keamanan Data
- **Dokumen peserta** (KTP/KK/Ijazah/Kartu Zairyu/dll) disimpan sebagai link Google Drive privat—URL input, tanpa upload/envelope di aplikasi. Permission Drive dibatasi manual ke akun/grup staf berwenang dan direview saat offboarding.
- **Foto wajah** = upload R2 bucket privat + **R2 SSE** at-rest + signed URL pendek. **Video** = embed URL.
- Akses berbasis role granular.
- Audit log seluruh aksi sensitif (Lampiran A).
- Token akses tamu tidak bisa ditebak (string acak panjang) + kadaluarsa otomatis + dicatat akses.
- 2FA TOTP wajib untuk role berisiko tinggi (4.5).
- Step-up re-auth untuk aksi sensitif/irreversible (4.6).
### 9.2 Performa
- **p95 < 800ms** halaman umum; **< 2s** list/berat.
- Index DB pada kolom filter/sort utama.
- `pg_trgm` + GIN trigram index pada kolom nama kandidat untuk cek-kemiripan cepat di skala ribuan.
### 9.3 Skalabilitas
- Desain untuk **±25 concurrent users** internal + Tamu sesekali.
- Estimasi volume kandidat 1–3 tahun: **500–3.000** *(dikunci user 2026-06-29; angka perencanaan kapasitas, dapat ditinjau ulang)*.
- Estimasi user internal total: **±15 user** *(dikunci user 2026-06-29)*.
- Jalur scale: vertikal dulu (upgrade Lighthouse), read-replica menyusul saat volume naik. Detail → `DEPLOYMENT.md`.
### 9.4 Bahasa Antarmuka (i18n)
- Indonesia & Jepang — toggle bebas; semua role bisa switch (kecuali Tamu = JP).
- **Simpan nilai kanonik (enum/code), render terlokalisasi.** Tidak menyimpan glyph Jepang sebagai value.
- Glyph Jepang (歳, 男/女, 有り/無し, format tanggal `YYYY年MM月DD日`) = presentation layer.
- UI string via Laravel localization (`lang/id`, `lang/ja`).
- Lookup bilingual: `label_id`, `label_ja`, `code`. Master perusahaan: `nama_ja` (wajib), `nama_romaji` (opsional), `nama_id` (opsional).
### 9.5 Ketersediaan
- **Target uptime 99%** (single VPS, no HA — jujur sesuai infra).
- Jam operasional prioritas **jam kerja JST** (Asia/Tokyo); best-effort 24/7.
- **Backup DB harian via ****`pg_dump`**** → R2** (bucket terpisah dari foto), retensi harian 14 hari + mingguan 3 bulan. Minimal satu restore test sukses ke DB temporary wajib sebelum go-live; setelahnya uji bulanan.
- VPS baseline **4 vCPU / 8 GB** memberi headroom untuk backup/restore & Redis co-located tanpa mengorbankan app.
- Target RPO ≤ 24 jam, RTO beberapa jam (runbook di `BACKUP_AND_RECOVERY.md` / `DEPLOYMENT.md`).
- Detail → `BACKUP_AND_RECOVERY.md`.
### 9.6 Tech Stack
- Backend: **Laravel + PostgreSQL** (modular monolith)
- File storage: **foto wajah** di **Cloudflare R2** (bucket privat + signed URL pendek 5–15 mnt); **dokumen peserta** = **link Google Drive privat** (URL input, tidak diset public); **video** = embed URL
- Infra: **VPS 4 vCPU / 8 GB RAM** (baseline MVP; single VPS, no HA). Provider/region final → `DEPLOYMENT.md` (kandidat: Lighthouse Tokyo / Singapore / ID).
- **Redis co-located** di VPS yang sama: cache, session, queue, rate limit (bind [localhost](http://localhost); tidak diekspos publik).
- Queue: driver **`redis`**** + 2 worker** (Supervisor). Redis co-located untuk cache/session/queue/rate-limit memakai **`maxmemory-policy noeviction`**; cache wajib TTL dan memory dimonitor. Unique constraint + transaksi DB tetap sumber kebenaran anti-duplikasi. Kebenaran bisnis + audit + notifikasi in-app DB commit terlebih dahulu; email/queue Redis dikirim after-commit. Kegagalan enqueue dicatat dan tidak me-rollback transaksi bisnis.
- Notifikasi: in-app tabel + polling ≤60 dtk; email kritis via queue Redis
- Cek-kemiripan: PostgreSQL extension **`pg_trgm`** + GIN trigram index
### 9.7 Kontrak Komunikasi Antar-Modul
- Modul berkomunikasi **hanya lewat public service / facade** modul tujuan.
- **Dilarang:** akses tabel lintas modul langsung, query lintas-domain, FK lintas-modul tanpa kontrak service.
- Penulisan ketersediaan kandidat (`Tersedia`/`Sedang Dipakai`) **hanya** melalui service publik modul Kandidat (mis. `markInUse()`, `markAvailable()`).
- Detail kontrak per modul → `ARCHITECTURE.md`.
### 9.8 File Storage Detail
- **Model penyimpanan (keputusan user 2026-07-01):**<br> • **Foto wajah:** upload ke R2 bucket privat + R2 SSE at-rest; akses via signed URL pendek (5–15 mnt); batas ≤ 5MB (jpg/png/webp), validasi MIME asli.<br> • **Dokumen peserta** (KTP/KK/Ijazah/Kartu Zairyu/dll) & **sertifikat/kualifikasi (URL File):** **link Google Drive privat** ("tidak diset public") — URL input yang ditempel staf, **bukan** unggah file ke aplikasi; tanpa envelope encryption/R2/signed URL. Berlaku untuk **semua jenis URL file**.<br> • **Video** (Jikoshokai/Keahlian): **embed URL**.
- Dokumen identitas/keluarga **tidak pernah** ke Tamu (HIDE). Akses dokumen sensitif dicatat audit `IDENTITY_DOC_VIEWED`.
- File untuk Tamu = **whitelist eksplisit** (mis. foto thumbnail + sertifikat skill ber-flag *shareable*). Sertifikat shareable berupa link Google Drive dibagikan via Drive **"anyone with link"** (opsi paling sederhana).
---
## 10. Risiko & Mitigasi
<table header-row="true">
<tr>
<td>Risiko</td>
<td>Dampak</td>
<td>Mitigasi</td>
</tr>
<tr>
<td>Kebocoran data identitas kandidat</td>
<td>Tinggi</td>
<td>Akses role granular, audit log, dokumen di Google Drive privat (tidak public) + audit akses, foto di bucket privat + signed URL</td>
</tr>
<tr>
<td>Race condition penarikan kandidat ganda</td>
<td>Tinggi</td>
<td>Constraint DB, validasi backend di transaksi, `SELECT FOR UPDATE` saat pull, status `Sedang Dipakai` sebagai lock, optimistic `version` untuk edit</td>
</tr>
<tr>
<td>Bottleneck approval karena tim kecil</td>
<td>Menengah</td>
<td>Notifikasi in-app ke Manajer Job, monitoring antrian approval di dashboard Super Admin</td>
</tr>
<tr>
<td>Link akses tamu bocor/tersebar</td>
<td>Tinggi</td>
<td>Token tidak bisa ditebak, masa berlaku wajib, kode tambahan opsional via kanal terpisah, log akses, signed URL pendek</td>
</tr>
<tr>
<td>Arsip kontainer penempatan terpicu prematur</td>
<td>Menengah</td>
<td>Cek arsip setelah seluruh batch diproses, bukan per kandidat dalam loop</td>
</tr>
<tr>
<td>**SPOF VPS tunggal** *(baru v0.3)*</td>
<td>Tinggi</td>
<td>Backup harian ke R2 + uji restore, dokumentasi runbook, jalur scale vertikal & read-replica menyusul</td>
</tr>
<tr>
<td>**Kepatuhan privasi PII (APPI/UU PDP)** *(baru v0.3)*</td>
<td>Tinggi</td>
<td>Kebijakan retensi + anonimisasi terkontrol, hak penghapusan via Super Admin + step-up + audit</td>
</tr>
<tr>
<td>**Double-approval karena race antar-Checker** *(baru v0.3)*</td>
<td>Menengah</td>
<td>Verifikasi status sumber keputusan yang berlaku masih `pending` di dalam transaksi sebelum menerapkan keputusan</td>
</tr>
</table>
---
## 11. Item yang Masih Menunggu Konfirmasi
Tiga item berikut telah dikonfirmasi user (2026-06-29). Dua item kapasitas dikunci sebagai angka perencanaan; retensi PII tertutup di tingkat kebijakan dengan rincian jadwal menunggu konfirmasi final DPO:
<table header-row="true">
<tr>
<td>Item</td>
<td>Default sementara</td>
<td>Diisi</td>
</tr>
<tr>
<td>Estimasi jumlah kandidat 1–3 tahun</td>
<td>500–3.000</td>
<td>**500–3.000** (dikunci user 2026-06-29 sebagai angka perencanaan kapasitas; bersifat estimasi, dapat ditinjau ulang sesuai realita operasional kumiai)</td>
</tr>
<tr>
<td>Jumlah user internal total</td>
<td>±15 user</td>
<td>**±15 user** (dikunci user 2026-06-29; selaras target ±25 concurrent users di §9.3)</td>
</tr>
<tr>
<td>Kebijakan retensi PII (legal): periode simpan PII aktif pasca-keterikatan terakhir + periode anonimisasi nonaktif</td>
<td>TBD (mis. 5–7 tahun aktif, anonimisasi 1 tahun pasca-final)</td>
<td>**5 tahun** aktif sejak keterikatan terakhir, lalu anonimisasi (soft tombstone) dalam tenggang **≤ 1 tahun** (disetujui user 2026-06-29; rincian jadwal & prosedur → DATA_RETENTION_AND_[PRIVACY.md](../technical/DATA_RETENTION_AND_PRIVACY.md), pending konfirmasi DPO)</td>
</tr>
</table>
---
## Lampiran A — Enumerasi Audit Events
Semua aksi tercatat di `audit_log` lewat **satu service audit terpusat** (shared infrastructure). Skema dasar: `actor_id (nullable), actor_role_snapshot (nullable — snapshot peran aktor saat kejadian), action_type, entity_type, entity_id, detail (JSONB), ip (nullable), user_agent (nullable), created_at`. `action_type` = **backed-enum aplikasi** (daftar tetap A.1), bukan string bebas & bukan CHECK keras DB — daftar A.1 adalah kontrak filter viewer audit.
### A.1 Daftar `action_type`
<table header-row="true">
<tr>
<td>Domain</td>
<td>Action Type</td>
</tr>
<tr>
<td>Auth</td>
<td>`LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGIN_LOCKED_OUT`, `LOGOUT`, `TWOFA_SETUP`, `TWOFA_VERIFIED`, `TWOFA_FAILED`, `TWOFA_RECOVERY_USED`, `PASSWORD_CHANGED`, `STEPUP_REAUTH`, `STEPUP_FAILED`</td>
</tr>
<tr>
<td>User/Role</td>
<td>`USER_CREATED`, `USER_UPDATED`, `USER_DEACTIVATED`, `USER_REACTIVATED`, `ROLE_ASSIGNED`, `ROLE_CHANGED`, `PASSWORD_RESET_BY_ADMIN`</td>
</tr>
<tr>
<td>Lookup</td>
<td>`LOOKUP_CREATED`, `LOOKUP_UPDATED`, `LOOKUP_DEACTIVATED`, `LOOKUP_REACTIVATED`, `LOOKUP_REQUEST_SUBMITTED`, `LOOKUP_REQUEST_APPROVED`, `LOOKUP_REQUEST_REJECTED`</td>
</tr>
<tr>
<td>Company</td>
<td>`COMPANY_CREATED`, `COMPANY_UPDATED`, `COMPANY_REQUESTED`, `COMPANY_APPROVED`, `COMPANY_REJECTED`, `COMPANY_DEACTIVATED`, `COMPANY_REACTIVATED`</td>
</tr>
<tr>
<td>Kandidat</td>
<td>`CANDIDATE_CREATED`, `CANDIDATE_SUBMITTED`, `CANDIDATE_APPROVED`, `CANDIDATE_REJECTED`, `CANDIDATE_REVISION_SUBMITTED`, `CANDIDATE_UPDATED`, `CANDIDATE_SOFT_DELETED` (reserved/deferred), `CANDIDATE_RESTORED` (reserved/deferred), `IDENTITY_DOC_VIEWED`, `CANDIDATE_PHOTO_UPLOADED`, `SIMILARITY_MATCH_SHOWN`, `CANDIDATE_ANONYMIZED`</td>
</tr>
<tr>
<td>Wawancara</td>
<td>`IC_CREATED`, `IC_SUBMITTED`, `IC_APPROVED`, `IC_REJECTED`, `IC_CANCELLED`, `IC_CLOSE_REQUESTED`, `IC_CLOSED`, `CANDIDATE_PULLED`, `PARTICIPATION_STATUS_CHANGED`, `EXPEL_REQUESTED`, `EXPEL_APPROVED`, `EXPEL_REJECTED`</td>
</tr>
<tr>
<td>Tamu</td>
<td>`GUEST_LINK_REQUESTED`, `GUEST_LINK_APPROVED`, `GUEST_LINK_REJECTED`, `GUEST_ACCESS`, `GUEST_DETAIL_VIEWED`</td>
</tr>
<tr>
<td>Penempatan</td>
<td>`PC_CREATED`, `PC_SUBMITTED`, `PC_APPROVED`, `PC_REJECTED`, `PC_CANCELLED`, `BATCH_SENT`, `FORCE_MAJEUR_ADDED`, `FM_REJECTED`, `PLACEMENT_STATUS_CHANGED`, `RESIGN_REQUESTED`, `RESIGN_APPROVED`, `RESIGN_REJECTED`, `PLACEMENT_EXPEL_REQUESTED`, `PLACEMENT_EXPEL_APPROVED`, `PLACEMENT_EXPEL_REJECTED`, `CONTAINER_ARCHIVED`</td>
</tr>
</table>
### A.2 Contoh Skema `detail` JSONB
<table header-row="true">
<tr>
<td>Event</td>
<td>Bentuk `detail`</td>
</tr>
<tr>
<td>`ROLE_CHANGED`</td>
<td>`{ "target_user_id": ..., "old_role": "...", "new_role": "..." }`</td>
</tr>
<tr>
<td>`GUEST_ACCESS`</td>
<td>`{ "token_id": ..., "ip": "...", "container_id": ... }`</td>
</tr>
<tr>
<td>`GUEST_DETAIL_VIEWED`</td>
<td>`{ "token_id": ..., "candidate_id": ..., "container_id": ..., "ip": "..." }` (pembukaan detail kandidat G3 oleh Tamu; actor NULL; v0.3.11)</td>
</tr>
<tr>
<td>`PARTICIPATION_STATUS_CHANGED`</td>
<td>`{ "participation_id": ..., "from": "...", "to": "...", "note": "..." }`</td>
</tr>
<tr>
<td>`CANDIDATE_REJECTED`</td>
<td>`{ "candidate_id": ..., "reason": "..." }`</td>
</tr>
<tr>
<td>`EXPEL_APPROVED`</td>
<td>`{ "participation_id": ..., "maker_reason": "...", "checker_note": "..." }`</td>
</tr>
<tr>
<td>`BATCH_SENT`</td>
<td>`{ "placement_container_id": ..., "candidate_ids": [...], "per_candidate": [{ "candidate_id": ..., "visa": "...", "start_date": "...", "duration_months": ... }] }`</td>
</tr>
<tr>
<td>`FORCE_MAJEUR_ADDED`</td>
<td>`{ "placement_container_id": ..., "candidate_id": ..., "reason": "..." }`</td>
</tr>
<tr>
<td>`LOOKUP_DEACTIVATED`</td>
<td>`{ "lookup_category": "...", "code": "...", "label_id": "...", "label_ja": "..." }`</td>
</tr>
<tr>
<td>`LOOKUP_UPDATED` / `LOOKUP_REACTIVATED`</td>
<td>`{ "lookup_category": "...", "code": "...", "changed": { "label_id": ["old","new"], "label_ja": ["old","new"] } }` (code immutable)</td>
</tr>
<tr>
<td>`COMPANY_CREATED` / `COMPANY_UPDATED` / `COMPANY_REACTIVATED`</td>
<td>`{ "perusahaan_id": ..., "nama_ja": "...", "changed": { ... } }`</td>
</tr>
<tr>
<td>`USER_UPDATED` / `USER_REACTIVATED`</td>
<td>`{ "target_user_id": ..., "changed": { ... } }`</td>
</tr>
<tr>
<td>`PASSWORD_RESET_BY_ADMIN`</td>
<td>`{ "target_user_id": ..., "must_change_password": true }` (oleh admin; ≠ `PASSWORD_CHANGED` self-service)</td>
</tr>
<tr>
<td>`CANDIDATE_ANONYMIZED`</td>
<td>`{ "candidate_id": ..., "basis": "retention_policy|right_to_erasure", "fields_tombstoned": [...] }`</td>
</tr>
<tr>
<td>`IDENTITY_DOC_VIEWED`</td>
<td>`{ "candidate_id": ..., "candidate_document_id": ..., "doc_type": "...", "viewer_role": "..." }` — aplikasi mengungkap/membuka link Drive kepada aktor berwenang; bukan bukti file dibaca di Drive</td>
</tr>
<tr>
<td>`SIMILARITY_MATCH_SHOWN`</td>
<td>`{ "candidate_draft_id": ..., "matches": [{ "candidate_id": ..., "score": 0.xx }], "threshold": 0.4 }`</td>
</tr>
<tr>
<td>`CANDIDATE_SOFT_DELETED` / `CANDIDATE_RESTORED`</td>
<td>`{ "candidate_id": ..., "reason": "..." }`</td>
</tr>
<tr>
<td>`CANDIDATE_PHOTO_UPLOADED`</td>
<td>`{ "candidate_id": ..., "size_bytes": ..., "mime": "image/jpeg|png|webp" }`</td>
</tr>
<tr>
<td>`LOGIN_LOCKED_OUT`</td>
<td>`{ "user_id": ... | null, "email_masked_or_fingerprint": "...", "locked_until": "..." }`; IP hanya di kolom `ip`, email input mentah tidak disimpan</td>
</tr>
<tr>
<td>`STEPUP_REAUTH` / `STEPUP_FAILED`</td>
<td>`{ "action": "...", "entity_type": "...", "entity_id": ..., "result": "success|fail" }`</td>
</tr>
<tr>
<td>`TWOFA_RECOVERY_USED`</td>
<td>`{ "user_id": ..., "codes_left": ... }`</td>
</tr>
</table>
> Skema lengkap per setiap `action_type` didefinisikan di `BUSINESS_RULES.md` atau dokumen `AUDIT_EVENTS.md` (turunan).
---
## Lampiran B — Tabel Transisi State Machine
### B.1 Kontainer Wawancara (5 status final)
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu</td>
<td>Approval?</td>
<td>Step-up?</td>
</tr>
<tr>
<td>(baru)</td>
<td>Draft</td>
<td>Asisten Manajer simpan</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Draft</td>
<td>Menunggu Approval</td>
<td>Submit</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Draft</td>
<td>Dibatalkan</td>
<td>Batalkan oleh pembuat</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Aktif</td>
<td>Manajer Job setuju</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Draft</td>
<td>Manajer Job tolak (catatan wajib)</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Dibatalkan</td>
<td>Batalkan sebelum diputuskan</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Aktif</td>
<td>Ditutup</td>
<td>Flow Tutup Kontainer disetujui</td>
<td>✔</td>
<td>✔</td>
</tr>
</table>
> Catatan: `Aktif` tidak pernah kembali ke status sebelumnya. “Menunggu Persetujuan Penutupan” adalah **entitas pending overlay**, bukan status kontainer — status tetap `Aktif` sampai disetujui.
### B.2 Kontainer Penempatan (5 status final)
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu</td>
<td>Approval?</td>
<td>Step-up?</td>
</tr>
<tr>
<td>(baru)</td>
<td>Draft</td>
<td>Asisten Manajer simpan</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Draft</td>
<td>Menunggu Approval</td>
<td>Submit</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Draft / Menunggu Approval</td>
<td>Dibatalkan</td>
<td>Batalkan oleh pembuat</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Aktif</td>
<td>Manajer Job setuju</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Draft</td>
<td>Manajer Job tolak (catatan wajib)</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Aktif</td>
<td>Arsip</td>
<td>Otomatis saat kandidat aktif terakhir → terminal</td>
<td>— (sistem)</td>
<td>—</td>
</tr>
<tr>
<td>Aktif</td>
<td>Dibatalkan</td>
<td>Escape GAP-4 — guard `count(placement_participant)=0`, ber-approval Manajer Job</td>
<td>✔</td>
<td>—</td>
</tr>
</table>
> Tidak ada penutupan manual; pembatalan `Aktif→Dibatalkan` hanya lewat escape GAP-4 (kontainer masih kosong).
### B.3 `status_wawancara` (8 status — partisipasi)
Transisi maju ketat: `Menunggu Wawancara` → `Lulus` → `Proses Dokumen` → `Siap Dikirim` → `Terkirim`. Tidak ada rollback.
Terminal dari status aktif manapun (jalur alami): `Tidak Lolos`, `Mengundurkan Diri`.
Terminal jalur paksa (butuh approval + step-up): `Dikeluarkan`.
### B.4 `status_penempatan` (4 status)
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu</td>
<td>Approval?</td>
<td>Step-up?</td>
</tr>
<tr>
<td>(baru via batch normal / force-majeur)</td>
<td>Bekerja</td>
<td>Approval batch / force-majeur</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Selesai Kontrak</td>
<td>Jalur 1, langsung efektif</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Mengundurkan Diri</td>
<td>Jalur 2 disetujui</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Dikeluarkan</td>
<td>Jalur 3 disetujui</td>
<td>✔</td>
<td>✔</td>
</tr>
</table>
### B.5 Status Approval Kandidat
Kandidat baru: `Draft` → `Menunggu Tinjauan-BARU` → `Disetujui` / `Ditolak`; `Ditolak` → `Menunggu Tinjauan-REVISI` saat revisi disubmit.<br>Revision kandidat approved: `Draft` → `Menunggu Tinjauan-REVISI` → `Diterapkan` / `Ditolak`.
Save Draft belum memiliki pending approval. Submit membuat pending+status dalam satu transaksi. Maksimum satu revision Draft/menunggu aktif per main candidate. Approval revision mengganti field mutable+seluruh child collections atomik; data utama tetap `Disetujui`, sedangkan NIK, availability, dan operational history tidak berubah.
### B.6 Status Link Tamu
`Menunggu Approval` → `Aktif` (token digenerate saat disetujui) → `Kadaluarsa` (otomatis saat masa berlaku habis atau kontainer ditutup/dibatalkan).
---
## Lampiran C — `GuestCandidateView` (Whitelist Field Tamu — berjenjang G2/G3, v0.3.11)
Halaman Tamu **HANYA** menampilkan field yang ada di daftar ini. Dibangun lewat satu *read-model* server-side dari service publik modul Kandidat — tidak ada filtering ad-hoc per halaman. **Proyeksi berjenjang (v0.3.11):** dua profil terpisah — **G2 (daftar) pseudonim** vs **G3 (detail) diperluas**; objek kandidat penuh tidak pernah dikirim ke klien.
**Info kontainer wawancara:**<br>- Nama perusahaan tujuan (`nama_ja`)<br>- Tanggal wawancara<br>- Jenis wawancara (Offline/Online)
**Profil G2 — Daftar Kandidat (PSEUDONIM, anti-PII):**<br>- **Kode kandidat** (Nomor Induk `K-YYYY-NNNNN`) sebagai identifier — **BUKAN nama**<br>- Umur (render `歳`, computed dari tanggal lahir)<br>- Jenis Kelamin (render `男`/`女`)<br>- Kewarganegaraan<br>- Level Bahasa Jepang (jenis + score)<br>- Kualifikasi Keahlian Jepang/SSW · Bidang Diminati (jenis)<br>- **TIDAK di daftar:** nama, foto, riwayat kerja/pendidikan
**Profil G3 — Detail Kandidat (DIPERLUAS — dibuka atas arahan atasan 2026-07-12):**<br>Mewarisi seluruh field G2, **ditambah**:<br>- **Nama Alphabet + Nama Katakana** *(BARU)*<br>- **Foto kandidat** — signed URL R2 ber-expiry (TTL 15 mnt), di-scope ke sesi `guest_link` Aktif *(BARU)*<br>- Level Bahasa Inggris (jika ada), Kualifikasi Mengemudi (jenis)<br>- **Riwayat Pekerjaan PENUH** — Nama Perusahaan + Nama Penanggung TSK/Kumiai + Bidang Pekerjaan + Tanggal Masuk/Keluar *(BARU/diperluas)*<br>- **Riwayat Pendidikan PENUH** — Jenis Pendidikan + Jurusan + Nama Lembaga + Tanggal Masuk/Keluar *(BARU)*<br>- (Opsional, default OFF) URL Video Jikoshokai, URL Video Keahlian<br>- Dokumen/sertifikat ber-flag *shareable* saja (link Google Drive "anyone with link")
**Field yang DISEMBUNYIKAN (tidak pernah tampil ke Tamu — kedua profil):**<br>- Catatan internal<br>- Status partisipasi detail (riwayat perubahan, alasan, dst)<br>- Data keluarga & kontak keluarga/darurat<br>- Email, nomor telepon, Line ID<br>- Tanggal lahir mentah (hanya **umur** yang tampil)<br>- Alamat lengkap & Tempat lahir<br>- Imigrasi & dokumen peserta (Foto Zairyu, no. paspor, no. zairyu, alamat zairyu, jenis visa saat ini, seluruh `candidate_document`)<br>- Data fisik sensitif & kesehatan<br>- IQ/MTK Score, Final Laporan Psikotes<br>- Video (default OFF, tampil hanya bila diaktifkan per link)
> **Enforcement (v0.3.14):** identifier G2/G3 = Nomor Induk `K-YYYY-NNNNN`; tidak ada kode `CAND-*` baru. Nama/foto/nama lembaga/nama perusahaan tidak boleh menjadi parameter sort/filter. Kandidat dengan `pii_anonymized_at` terisi **dikeluarkan seluruhnya** dari G2/G3; request detail langsung ditolak dengan respons generik.
---
## Lampiran D — Daftar Aksi Step-up Re-Auth
Aksi berikut **memicu re-autentikasi (password + TOTP ulang)** sebelum diterapkan, terlepas dari sesi 2FA login yang sudah aktif:
1. **Manajemen user sensitif:** Ubah role user, nonaktifkan akun user
2. **Tutup kontainer wawancara** (irreversible)
3. **Keluarkan kandidat** — di modul Wawancara maupun Penempatan (jalur paksa, alasan dua lapis)
4. **Konfigurasi sistem:** kelola data referensi/lookup, kelola master data perusahaan
5. **Anonimisasi PII kandidat:** penghapusan/anonimisasi data identitas pribadi (§7.9) — hanya Super Admin
Aksi approval rutin (Approver Kandidat menyetujui/menolak kandidat; Manajer Job menyetujui/menolak kontainer baru, link tamu, batch kirim, mengundurkan diri penempatan) **tidak** memicu step-up — cukup 2FA login + sesi aktif.
> **Catatan Force-Majeur (v0.3.2):** Penambahan kandidat langsung jalur Force-Majeur (§6.4 Sub-flow 2b) **tidak** memicu step-up re-auth — kontrol cukup approval Manajer Job + alasan wajib + audit dua lapis `FORCE_MAJEUR_ADDED`. Berbeda dari *Keluarkan kandidat* (butir 3) yang tetap memicu step-up.
---
*— Akhir Dokumen PRD Kakehashi v0.3.14 (draft) —*
