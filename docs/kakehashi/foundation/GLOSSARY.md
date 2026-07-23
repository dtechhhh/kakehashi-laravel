---
title: "GLOSSARY"
status: "FINAL"
source_notion_title: "GLOSSARY"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# GLOSSARY

> [!NOTE]
> [**GLOSSARY.md**](GLOSSARY.md)** — Kakehashi.** Sumber tunggal definisi kanonik istilah domain agar semua agent/file memakai bahasa yang sama. **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.** Jika konflik, PRD berlaku. Transisi status rinci milik STATUS_STATE_MACHINE; aturan bisnis milik BUSINESS_RULES; skema milik DATABASE_SCHEMA — di sini hanya definisi + pointer.
> **Status: FINAL (disetujui user 2026-06-28).** Glyph JP terverifikasi 2026-06-28.
>
## Cara baca
Tiap entri: **Istilah (ID)** · **JP/Romaji** (bila relevan) · **Definisi** · **§PRD** · **Catatan / jangan dikacaukan dengan**. Kolom JP hanya diisi untuk istilah yang punya glyph render kanonik atau nama perusahaan.
---
## 1. Entitas & Objek Domain
<table header-row="true">
<tr>
<td>Istilah (ID)</td>
<td>JP/Romaji</td>
<td>Definisi</td>
<td>§PRD</td>
<td>Catatan / jangan dikacaukan</td>
</tr>
<tr>
<td>Kakehashi</td>
<td>—</td>
<td>Nama produk: sistem terpusat manajemen kandidat & job Kumiai/TSK, bilingual ID/JP.</td>
<td>Judul; OVERVIEW §1</td>
<td>Nama produk, bukan nama modul.</td>
</tr>
<tr>
<td>Kumiai / TSK</td>
<td>組合 (kumiai)</td>
<td>Organisasi penyalur/pengelola program kerja ke Jepang; pemilik & pengguna sistem.</td>
<td>§1, §2.1</td>
<td>Bukan perusahaan tujuan penempatan.</td>
</tr>
<tr>
<td>Kandidat</td>
<td>—</td>
<td>Individu calon pekerja yang datanya dikelola dari input → validasi → tersedia → wawancara → penempatan.</td>
<td>§5.2, §6.2</td>
<td>Bukan Pengguna Internal.</td>
</tr>
<tr>
<td>Job (≡ Wawancara)</td>
<td>—</td>
<td>Istilah penamaan proyek: "Job" = domain/Modul Wawancara PRD.</td>
<td>OVERVIEW §5; DECISIONS_LOG 2026-06-28</td>
<td>JANGAN dibaca sebagai "lowongan/pekerjaan".</td>
</tr>
<tr>
<td>Perusahaan (tujuan)</td>
<td>会社 (nama_ja wajib)</td>
<td>Perusahaan Jepang tujuan penempatan; master data (nama_ja wajib, nama_romaji/nama_id opsional).</td>
<td>§5.4, §9.4</td>
<td>Bukan Kumiai. Mengakses sistem sebagai Tamu read-only.</td>
</tr>
<tr>
<td>Kontainer</td>
<td>—</td>
<td>Istilah payung: wadah proses tempat kandidat ditarik & dikelola.</td>
<td>§3.1; OVERVIEW §7</td>
<td>Selalu beri spesialisasi (Wawancara/Penempatan).</td>
</tr>
<tr>
<td>Kontainer Wawancara</td>
<td>—</td>
<td>Entitas wadah satu proses wawancara; state machine 5 status.</td>
<td>§5.3, Lampiran B.1</td>
<td>Beda dari Kontainer Penempatan.</td>
</tr>
<tr>
<td>Kontainer Penempatan</td>
<td>—</td>
<td>Entitas wadah satu penempatan; 1 perusahaan (tak bisa diubah), arsip otomatis; state machine 5 status.</td>
<td>§5.4, Lampiran B.2</td>
<td>Satu kontainer = satu perusahaan.</td>
</tr>
<tr>
<td>Penempatan (proses)</td>
<td>—</td>
<td>Proses menempatkan kandidat ke perusahaan (batch normal / force-majeur).</td>
<td>§6.4</td>
<td>Beda dari entitas Kontainer Penempatan.</td>
</tr>
<tr>
<td>Partisipasi (participation)</td>
<td>—</td>
<td>Baris keterkaitan satu kandidat di dalam satu kontainer wawancara; ber-`status_wawancara`.</td>
<td>§5.3</td>
<td>Bukan kandidat itu sendiri.</td>
</tr>
<tr>
<td>Riwayat Partisipasi</td>
<td>—</td>
<td>Catatan permanen seluruh partisipasi & perubahan status kandidat.</td>
<td>§2.2, §5.3</td>
<td>Read-model historis, bukan baris status aktif.</td>
</tr>
<tr>
<td>Placement Participant</td>
<td>—</td>
<td>Baris satu kandidat di kontainer penempatan; ber-`status_penempatan`; punya `source_participation_id` (nullable).</td>
<td>§5.4</td>
<td>`null` = masuk via Sub-flow Force-Majeur.</td>
</tr>
<tr>
<td>Tamu (Guest)</td>
<td>—</td>
<td>Akses eksternal read-only via link bertoken per kontainer wawancara. BUKAN akun/role internal.</td>
<td>§4.3</td>
<td>Muncul di tabel role §4.1 hanya sebagai konteks peta akses, bukan akun.</td>
</tr>
<tr>
<td>Link Tamu (token)</td>
<td>—</td>
<td>Tautan bertoken unik + kadaluarsa per kontainer; token digenerate setelah disetujui Manajer Job.</td>
<td>§5.3, Lampiran B.6</td>
<td>1 token = 1 kontainer; boleh banyak link aktif sekaligus.</td>
</tr>
<tr>
<td>Pengguna Internal</td>
<td>—</td>
<td>Akun sistem dengan salah satu dari 5 role internal.</td>
<td>§4.1, §5.1</td>
<td>Tamu BUKAN pengguna internal.</td>
</tr>
<tr>
<td>Pending Request</td>
<td>—</td>
<td>Entitas yang merepresentasikan aksi sensitif menunggu approval; status agregat belum berubah.</td>
<td>§7.4</td>
<td>Lihat Pending-as-Entity.</td>
</tr>
<tr>
<td>Data Referensi/Lookup</td>
<td>—</td>
<td>Nilai dropdown label deskriptif bilingual (label_id/label_ja/code), dikelola Super Admin.</td>
<td>§5.1, §7.8</td>
<td>Bukan status state machine (yang tetap hardcode).</td>
</tr>
<tr>
<td>Master Perusahaan</td>
<td>—</td>
<td>Kumpulan data perusahaan tujuan; nama_ja wajib; dikelola Super Admin.</td>
<td>§5.4</td>
<td>Subhimpunan lookup.</td>
</tr>
<tr>
<td>Audit Log</td>
<td>—</td>
<td>Catatan terpusat aksi sensitif: actor_id, action_type, entity, detail (JSONB), ip, created_at.</td>
<td>§5.1, Lampiran A</td>
<td>Read-only; Super Admin hanya melihat.</td>
</tr>
<tr>
<td>GuestCandidateView</td>
<td>—</td>
<td>Whitelist field yang boleh dilihat Tamu; read-model dari service publik Kandidat.</td>
<td>§4.3, Lampiran C</td>
<td>Bukan filtering ad-hoc per halaman.</td>
</tr>
</table>
---
## 2. Peran (5 role internal + Tamu)
> Enam role hardcode MVP (§4.1). Tamu = akses bertoken, bukan akun (lihat §1).
<table header-row="true">
<tr>
<td>Istilah (ID)</td>
<td>Definisi</td>
<td>§PRD</td>
<td>Catatan / jangan dikacaukan</td>
</tr>
<tr>
<td>Staf Input</td>
<td>Maker modul Kandidat: input data kandidat.</td>
<td>§4.1</td>
<td>Tidak menyetujui datanya sendiri.</td>
</tr>
<tr>
<td>Approver Kandidat</td>
<td>Checker modul Kandidat: setuju/tolak data (tanpa edit); akses terbatas hanya modul Kandidat; wajib 2FA.</td>
<td>§4.1, §4.5</td>
<td>Tidak punya akses Wawancara/Penempatan.</td>
</tr>
<tr>
<td>Asisten Manajer</td>
<td>Maker modul Wawancara & Penempatan: semua aksi eksekusi.</td>
<td>§4.1</td>
<td>Tidak bisa edit data master kandidat.</td>
</tr>
<tr>
<td>Manajer Job</td>
<td>Pure Checker Wawancara & Penempatan: setuju/tolak aksi Asisten Manajer; wajib 2FA.</td>
<td>§4.1, §4.5</td>
<td>Tidak mengeksekusi aksi operasional langsung.</td>
</tr>
<tr>
<td>Super Admin</td>
<td>Kelola akun pengguna, kelola lookup/master, lihat audit; read-only di semua modul operasional; wajib 2FA.</td>
<td>§4.2, §4.5</td>
<td>TIDAK menambah/menghapus tipe role (hardcode).</td>
</tr>
<tr>
<td>Tamu</td>
<td>Akses read-only daftar peserta wawancara via link bertoken per kontainer; bukan akun.</td>
<td>§4.3</td>
<td>Lihat entri "Tamu (Guest)" di §1.</td>
</tr>
</table>
---
## 3. Konsep Proses & Keamanan
<table header-row="true">
<tr>
<td>Istilah (ID)</td>
<td>JP/Romaji</td>
<td>Definisi</td>
<td>§PRD</td>
<td>Catatan / jangan dikacaukan</td>
</tr>
<tr>
<td>Maker–Checker</td>
<td>—</td>
<td>Pola dua peran: pelaku aksi (Maker) vs penyetuju (Checker); Checker hanya setuju/tolak + catatan, tidak mengedit.</td>
<td>§7.4</td>
<td>Bentuk kanonik. Alias lama "Maker-Approval" — hindari.</td>
</tr>
<tr>
<td>Approval</td>
<td>—</td>
<td>Keputusan setuju/tolak oleh Checker; penolakan WAJIB catatan alasan.</td>
<td>§7.4</td>
<td>—</td>
</tr>
<tr>
<td>Pending-as-Entity (Pending sebagai entitas)</td>
<td>—</td>
<td>Aksi sensitif tidak mengubah status agregat sampai disetujui; tampil sebagai overlay label di UI.</td>
<td>§7.4</td>
<td>Overlay label ≠ status kontainer.</td>
</tr>
<tr>
<td>Sub-flow Force-Majeur</td>
<td>—</td>
<td>Jalur pengecualian: menempatkan kandidat langsung tanpa pipeline wawancara; gated, alasan wajib, butuh approval Manajer Job, `source_participation_id = null`.</td>
<td>§6.4 Sub-flow 2b</td>
<td>Ejaan proyek kanonik "Force-Majeur" (ejaan umum: force majeure); selaras audit `FORCE_MAJEUR_ADDED`.</td>
</tr>
<tr>
<td>Nomor Induk (Kandidat)</td>
<td>—</td>
<td>Identitas unik kandidat, format `K-YYYY-NNNNN` (5-digit sequence per tahun); digenerate atomik saat submit pertama.</td>
<td>§5.2, §6.2</td>
<td>Internal; tidak pernah ditampilkan ke Tamu.</td>
</tr>
<tr>
<td>Cek-Kemiripan (similarity)</td>
<td>—</td>
<td>Deteksi duplikat sebelum submit: Nama (trigram pg_trgm, similarity ≥ 0.4) + Tgl Lahir (exact) + Kewarganegaraan (exact), mencakup draft.</td>
<td>§6.2, §8.1</td>
<td>Threshold/teknis detail → BUSINESS_RULES.</td>
</tr>
<tr>
<td>2FA TOTP</td>
<td>—</td>
<td>Faktor kedua login berbasis TOTP (RFC 6238); wajib Approver Kandidat, Manajer Job, Super Admin.</td>
<td>§4.5</td>
<td>BUKAN login OAuth akun Google.</td>
</tr>
<tr>
<td>Step-up Re-Auth</td>
<td>—</td>
<td>Re-autentikasi (password + TOTP) per aksi sensitif/irreversible; berbeda dari 2FA login.</td>
<td>§4.6, Lampiran D</td>
<td>Bukan pengganti 2FA login.</td>
</tr>
<tr>
<td>Sesi</td>
<td>—</td>
<td>Session 30 menit; idle → auto-logout & minta login ulang.</td>
<td>§4.4</td>
<td>—</td>
</tr>
<tr>
<td>Optimistic Locking</td>
<td>—</td>
<td>Kontrol konkurensi via kolom `version`; konflik → HTTP 409 + reload.</td>
<td>§7.10</td>
<td>Detail → BUSINESS_RULES.</td>
</tr>
<tr>
<td>Pessimistic Locking</td>
<td>—</td>
<td>`SELECT ... FOR UPDATE`, khusus penarikan kandidat bulk ke kontainer wawancara.</td>
<td>§7.10</td>
<td>Hanya untuk anti race-condition pull.</td>
</tr>
<tr>
<td>Anonimisasi / Tombstone PII</td>
<td>—</td>
<td>Penghapusan PII via anonimisasi terkontrol (soft tombstone), bukan DELETE fisik; hanya Super Admin + step-up.</td>
<td>§7.9</td>
<td>Detail → DATA_RETENTION_AND_PRIVACY.</td>
</tr>
</table>
---
## 4. Status & Enum (definisi singkat — transisi → STATUS_STATE_MACHINE)
> Satu entri per state machine. Nilai enum dicantumkan untuk penamaan kanonik; transisi rinci bukan ranah file ini.
<table header-row="true">
<tr>
<td>State machine</td>
<td>Nilai kanonik</td>
<td>§PRD</td>
<td>Catatan</td>
</tr>
<tr>
<td>Status ketersediaan kandidat</td>
<td>Tersedia / Sedang Dipakai</td>
<td>§7.1</td>
<td>Ditulis HANYA via service publik modul Kandidat.</td>
</tr>
<tr>
<td>Status approval kandidat</td>
<td>Draft / Menunggu Tinjauan-BARU / Menunggu Tinjauan-REVISI / Disetujui / Ditolak / Diterapkan (internal revision)</td>
<td>§5.2, Lampiran B.5</td>
<td>`Draft` belum masuk antrian/pending dan belum punya NIK; `Diterapkan` terminal untuk revision yang sudah merged.</td>
</tr>
<tr>
<td>Status kontainer wawancara</td>
<td>Draft / Menunggu Approval / Aktif / Ditutup / Dibatalkan</td>
<td>§7.5, Lampiran B.1</td>
<td>5 status final.</td>
</tr>
<tr>
<td>Status kontainer penempatan</td>
<td>Draft / Menunggu Approval / Aktif / Arsip / Dibatalkan</td>
<td>§7.6, Lampiran B.2</td>
<td>Arsip hanya otomatis; tidak ada penutupan manual.</td>
</tr>
<tr>
<td>`status_wawancara` (partisipasi)</td>
<td>Menunggu Wawancara / Lulus / Proses Dokumen / Siap Dikirim / Terkirim / Tidak Lolos / Mengundurkan Diri / Dikeluarkan</td>
<td>§7.2, Lampiran B.3</td>
<td>State machine terpisah dari `status_penempatan`.</td>
</tr>
<tr>
<td>`status_penempatan`</td>
<td>Bekerja / Selesai Kontrak / Mengundurkan Diri / Dikeluarkan</td>
<td>§7.3, Lampiran B.4</td>
<td>"Mengundurkan Diri"/"Dikeluarkan" di sini ≠ konteks `status_wawancara` (lihat catatan di bawah).</td>
</tr>
<tr>
<td>Status Link Tamu</td>
<td>Menunggu Approval / Aktif / Kadaluarsa</td>
<td>Lampiran B.6</td>
<td>Token digenerate saat → Aktif.</td>
</tr>
</table>
> **Status bernama sama lintas state machine:** `Mengundurkan Diri` dan `Dikeluarkan` muncul di `status_wawancara` DAN `status_penempatan`. Keduanya **state machine terpisah**. Untuk menghindari ambiguitas, rujuk selalu ber-qualifier: `status_wawancara.Mengundurkan Diri` vs `status_penempatan.Mengundurkan Diri`. (PRD §7.3)
---
## 5. Konvensi Bilingual ID/JP
<table header-row="true">
<tr>
<td>Istilah (ID)</td>
<td>Definisi</td>
<td>§PRD</td>
<td>Catatan</td>
</tr>
<tr>
<td>Pola lookup bilingual</td>
<td>Tiap nilai lookup punya `label_id`, `label_ja`, dan `code` (kanonik, tidak berubah).</td>
<td>§5.1, §9.4</td>
<td>`code` yang disimpan di DB; label hanya presentasi.</td>
</tr>
<tr>
<td>Pola nama perusahaan</td>
<td>`nama_ja` (wajib), `nama_romaji` (opsional), `nama_id` (opsional).</td>
<td>§5.4, §9.4</td>
<td>—</td>
</tr>
<tr>
<td>"Store canonical enum, render glyph"</td>
<td>Simpan nilai kanonik (enum/code), render terlokalisasi; JANGAN simpan glyph Jepang sebagai value.</td>
<td>§9.4</td>
<td>Glyph = presentation layer.</td>
</tr>
<tr>
<td>Format tanggal JP</td>
<td>`YYYY年MM月DD日` (urutan tahun → bulan → hari).</td>
<td>§9.4</td>
<td>Hanya tampilan saat view JP.</td>
</tr>
</table>
---
## Lampiran — Glyph Kanonik (terverifikasi 2026-06-28)
> Pola: simpan enum kanonik, render glyph per bahasa. Glyph terverifikasi ke sumber JP terkini.
<table header-row="true">
<tr>
<td>Field</td>
<td>Enum kanonik</td>
<td>Render JP</td>
<td>Render ID</td>
<td>§PRD</td>
</tr>
<tr>
<td>Umur</td>
<td>(angka)</td>
<td>歳 (sai)</td>
<td>tahun</td>
<td>§5.2</td>
</tr>
<tr>
<td>Jenis Kelamin</td>
<td>M / F</td>
<td>男 / 女</td>
<td>Laki-laki / Perempuan</td>
<td>§5.2</td>
</tr>
<tr>
<td>Status Pernikahan</td>
<td>MARRIED / SINGLE</td>
<td>既婚 / 未婚</td>
<td>Menikah / Lajang</td>
<td>§5.2</td>
</tr>
<tr>
<td>Dominan Tangan</td>
<td>RIGHT / LEFT</td>
<td>右 / 左</td>
<td>Kanan / Kiri</td>
<td>§5.2</td>
</tr>
<tr>
<td>Boolean fisik (Buta Warna, Merokok, Minum Sake, Riwayat Penyakit, dll)</td>
<td>YES / NO</td>
<td>有り / 無し</td>
<td>Ya / Tidak</td>
<td>§5.2</td>
</tr>
</table>
> Catatan ejaan: `歳` adalah bentuk resmi penghitung umur (bukan `才`, yang informal). Bentuk okurigana `有り/無し` dipertahankan sesuai PRD (varian `有/無` juga sah).
---
*Status: FINAL v1.0 — selaras PRD Kakehashi v0.3 + PROJECT_OVERVIEW (final) + DECISIONS_LOG. Disetujui user 2026-06-28.*
