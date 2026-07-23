---
title: "DATABASE_SCHEMA"
status: "FINAL v1.0"
source_notion_title: "DATABASE_SCHEMA"
exported_at: "2026-07-15"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# DATABASE_SCHEMA

> [!NOTE]
> **DATABASE_**[**SCHEMA.md**](DATABASE_SCHEMA.md)** — Kakehashi (Kelompok 3 · Teknis).** Skema fisik PostgreSQL terkonsolidasi dari seluruh modul FINAL (K1 + K2). **Sumber kebenaran tertinggi = PRD_Kakehashi_v0_3_14.** File ini hanya merinci bentuk fisik (kolom/tipe/constraint/index); status & transisi milik STATUS_STATE_MACHINE, izin milik ROLES, master data milik MODULE_LOOKUP_DATA. Status: **FINAL v1.0 — Design Ready for MVP** (Batch B + verification 2026-07-14) · Persona: Senior Database Architect · Tgl: 2026-06-30.
>
## 0. Tabel Verifikasi Teknologi (browsing live 2026-06-30)
<table header-row="true">
<tr>
<td>Tech</td>
<td>Versi rekomen</td>
<td>Status maint</td>
<td>Caveat proyek</td>
<td>Sumber resmi (akses 2026-06-30)</td>
</tr>
<tr>
<td>PostgreSQL</td>
<td>**18.x** (pin `^18`; verifikasi minor saat deploy)</td>
<td>GA Sep 2025, aktif s/d 2030</td>
<td>Jangan PG 19 (devel/beta). PG18: `uuidv7()` native, virtual generated columns (default), B-tree skip scan, async I/O</td>
<td>[postgresql.org/docs/18/release-18.html](http://postgresql.org/docs/18/release-18.html)</td>
</tr>
<tr>
<td>pg_trgm + GIN `gin_trgm_ops`</td>
<td>1.6 (bawaan PG18, ekstensi trusted)</td>
<td>Aktif</td>
<td>`similarity()` real 0–1; operator `%` pakai ambang global default 0.3 → **WAJIB ****`similarity() >= 0.4`**** eksplisit**. Build default case-insensitive + abaikan non-alfanumerik</td>
<td>[postgresql.org/docs/18/pgtrgm.html](http://postgresql.org/docs/18/pgtrgm.html)</td>
</tr>
<tr>
<td>citext / unaccent</td>
<td>bawaan PG18 (F.9 / F.48)</td>
<td>Aktif</td>
<td>**TIDAK dipakai** (justifikasi §3.4): BR-DUP/MODULE_CANDIDATES #6 = nama "apa adanya"; pg_trgm sudah case-insensitive; nama latin Indonesia jarang diakritik</td>
<td>[postgresql.org/docs/18/citext.html](http://postgresql.org/docs/18/citext.html), /unaccent.html</td>
</tr>
<tr>
<td>Laravel migration (CHECK/jsonb/partial/GIN)</td>
<td>13.x (`^13`)</td>
<td>Aktif (security s/d Mar 2028)</td>
<td>`jsonb` & `bytea` didukung; CHECK / partial index / GIN / SEQUENCE via `DB::statement` (raw) di migration — tanpa breaking dari 12</td>
<td>[laravel.com/docs/13.x/migrations](http://laravel.com/docs/13.x/migrations)</td>
</tr>
<tr>
<td>Strategi PK</td>
<td>**bigint GENERATED ALWAYS AS IDENTITY**</td>
<td>Pola standar</td>
<td>UUIDv7 "basically matched" bigint utk insert, tapi tetap 16 byte vs 8 → setiap FK index \~2× lebih besar (signifikan di RAM 2GB). PK internal tak diekspos ke Tamu (token terpisah) → bigint dipilih</td>
<td>[postgresql.org/docs/18/ddl-identity-columns.html](http://postgresql.org/docs/18/ddl-identity-columns.html)</td>
</tr>
</table>
> Tidak ada perubahan versi mayor dari TECH_VERSION_SEED. Backend stack lain (PHP 8.4, Fortify, spatie/laravel-permission \^8.1, Flysystem v3 + aws-sdk-php ≥3.337) sudah terverifikasi di modul K2. **Queue/cache/session production = Redis** (baseline VPS 4C/8G, 2026-07-13); tabel `jobs`/`cache`/`sessions` tetap ada sebagai fallback non-prod.
---
## 1. Konvensi & Standar Global
### 1.1 Penamaan
- **snake_case** untuk semua tabel & kolom.
- **Tabel domain = singular** mengikuti nama kanonik modul: `candidate`, `interview_container`, `placement_container`, `participation`, `guest_link`, `audit_log`, `pending_request`, `perusahaan`, `nik_counter`. Pengecualian dipertahankan **apa adanya dari PRD §5.4**: `placement_participants` (plural — nama kanonik PRD, tidak diubah).
- **Tabel anak kandidat** ber-prefix `candidate_*`.
- **Tabel lookup** = nama benda tunggal sesuai MODULE_LOOKUP_DATA §4 (mis. `negara`, `jenis_visa`).
- **Tabel framework/3rd-party** mempertahankan nama default-nya (plural): `users`, `sessions`, `cache`, `jobs`, `failed_jobs`, `job_batches`, `notifications`, `roles`, `permissions`, dst (agar Eloquent/Fortify/spatie bekerja tanpa override). Model domain singular memakai `protected $table` eksplisit bila perlu.
- **FK** = `<entitas>_id` (mis. `candidate_id`, `perusahaan_id`).
### 1.2 Primary Key (keputusan terkunci — §0)
Semua tabel domain & lookup memakai:
```sql
id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY
```
Alasan: hemat 8 byte/baris vs UUID + FK index \~2× lebih kecil (kritis di VPS 2GB); tidak butuh ID terdistribusi; PK internal tak pernah diekspos ke Tamu (Tamu pakai **token acak terpisah**, bukan PK). Tabel framework mengikuti tipe PK bawaannya (`notifications.id` = uuid, `sessions.id` = string, `jobs.id` = bigserial).
### 1.3 Timestamp standar (PRD §9.4)
- Setiap tabel mutable: `created_at TIMESTAMPTZ NOT NULL DEFAULT now()` + `updated_at TIMESTAMPTZ NOT NULL DEFAULT now()`.
- **Simpan UTC, render JST (Asia/Tokyo)** di presentation layer. `timestamptz` menyimpan instant absolut (bukan zona) sehingga aman.
- `audit_log` & `guest_access_log` = append-only → hanya `created_at` (tanpa `updated_at`).
### 1.4 Optimistic & pessimistic locking (PRD §7.10, ARCH D8)
- Kolom `version INTEGER NOT NULL DEFAULT 0` pada SEMUA agregat mutable: `candidate` (termasuk revision), `interview_container`, `participation`, `placement_container`, `placement_participants`.
- Partial unique menegakkan satu participation Wawancara aktif per kandidat, satu revision Draft/menunggu aktif per main candidate, dan satu pending aktif per `(type,target_type,target_id)`.
- Pola UPDATE: `UPDATE ... SET version = version + 1 WHERE id = :id AND version = :v`; 0 baris → konflik → **HTTP 409**.
- **Pessimistic ****`SELECT ... FOR UPDATE`** khusus bulk pull kandidat (READ COMMITTED, tanpa `SKIP LOCKED`) + verifikasi `pending_request` masih `pending` di dalam transaksi (anti double-approval). Batas batch **≤ 50 kandidat/operasi** (MODULE_PLACEMENT #5) membatasi jumlah baris terkunci.
### 1.5 Larangan FK lintas-modul (ARCH D2 / PRD §9.7)
FK fisik **hanya** dalam satu modul atau ke **master/lookup bersama** (`perusahaan`, tabel lookup) dan ke **`users`** (identitas fondasi). Referensi **antar-agregat domain lintas-modul disimpan sebagai kolom ****`bigint`**** TANPA FK**, divalidasi via public service:
- `participation.candidate_id` (Wawancara→Kandidat) — tanpa FK.
- `placement_participants.candidate_id` (Penempatan→Kandidat) — tanpa FK.
- `placement_participants.source_participation_id` (Penempatan→Wawancara) — tanpa FK.
- `guest_link.interview_container_id` (Tamu→Wawancara) — tanpa FK.
<table header-row="true">
<tr>
<td>Perilaku FK</td>
<td>Aturan</td>
</tr>
<tr>
<td>ON DELETE</td>
<td>**RESTRICT** (default) untuk semua FK referensial; lookup/master pakai soft-disable `is_active=false`, bukan hard-delete</td>
</tr>
<tr>
<td>ON UPDATE</td>
<td>**RESTRICT** (PK identity tak pernah berubah)</td>
</tr>
<tr>
<td>Child kandidat → `candidate`</td>
<td>**ON DELETE CASCADE** (anak ikut terhapus bila parent benar-benar dihapus; namun normal-nya pakai soft delete `deleted_at`)</td>
</tr>
</table>
---
## 2. Ekstensi PostgreSQL yang di-enable
```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- cek-kemiripan nama (GIN gin_trgm_ops), BR-DUP
```
**Hanya ****`pg_trgm`****.** Tidak meng-enable `citext`/`unaccent` (justifikasi §3.4), tidak butuh `uuid-ossp`/`pgcrypto` (PK bigint; tidak ada enkripsi konten di DB — dokumen peserta memakai link Google Drive privat (URL input), bukan upload/enkripsi di aplikasi). `uuidv7()` PG18 native tidak dipakai karena PK = bigint.
---
## 3. Enum vs Lookup, Index Khusus, Normalisasi Nama
### 3.1 Backed enum + CHECK (HARDCODE — MODULE_LOOKUP_DATA D-L3)
Nilai fixed ber-glyph & seluruh status state machine = PHP 8.4 backed enum di aplikasi **+ CHECK constraint** di DB (pertahanan ganda). Disimpan sebagai `TEXT` (nilai kanonik), **bukan** lookup table.
<table header-row="true">
<tr>
<td>Domain</td>
<td>Kolom</td>
<td>Nilai kanonik (CHECK)</td>
</tr>
<tr>
<td>Jenis Kelamin</td>
<td>`candidate.jenis_kelamin`</td>
<td>`M`, `F`</td>
</tr>
<tr>
<td>Status Pernikahan</td>
<td>`candidate.status_pernikahan`</td>
<td>`MARRIED`, `SINGLE`</td>
</tr>
<tr>
<td>Dominan Tangan</td>
<td>`candidate_physical.dominan_tangan`</td>
<td>`RIGHT`, `LEFT`</td>
</tr>
<tr>
<td>Boolean fisik</td>
<td>`candidate_physical.*` (lihat §5.2)</td>
<td>`YES`, `NO`</td>
</tr>
<tr>
<td>Jenis Wawancara</td>
<td>`interview_container.jenis_wawancara`</td>
<td>`OFFLINE`, `ONLINE`</td>
</tr>
<tr>
<td>**Mesin 1** Kontainer Wawancara</td>
<td>`interview_container.status`</td>
<td>`Draft`, `Menunggu Approval`, `Aktif`, `Ditutup`, `Dibatalkan`</td>
</tr>
<tr>
<td>**Mesin 2** Kontainer Penempatan</td>
<td>`placement_container.status`</td>
<td>`Draft`, `Menunggu Approval`, `Aktif`, `Arsip`, `Dibatalkan`</td>
</tr>
<tr>
<td>**Mesin 3** status_wawancara</td>
<td>`participation.status_wawancara`</td>
<td>`Menunggu Wawancara`, `Lulus`, `Proses Dokumen`, `Siap Dikirim`, `Terkirim`, `Tidak Lolos`, `Mengundurkan Diri`, `Dikeluarkan`</td>
</tr>
<tr>
<td>**Mesin 4** status_penempatan</td>
<td>`placement_participants.status_penempatan`</td>
<td>`Bekerja`, `Selesai Kontrak`, `Mengundurkan Diri`, `Dikeluarkan`</td>
</tr>
<tr>
<td>**Mesin 5** Approval Kandidat</td>
<td>`candidate.status_approval`</td>
<td>`Draft`, `Menunggu Tinjauan-BARU`, `Menunggu Tinjauan-REVISI`, `Disetujui`, `Ditolak`, `Diterapkan`</td>
</tr>
<tr>
<td>**Mesin 6** Link Tamu</td>
<td>`guest_link.status_link`</td>
<td>`Menunggu Approval`, `Aktif`, `Kadaluarsa`</td>
</tr>
<tr>
<td>Ketersediaan kandidat</td>
<td>`candidate.status_ketersediaan`</td>
<td>`TERSEDIA`, `SEDANG_DIPAKAI`</td>
</tr>
<tr>
<td>Status akun</td>
<td>`users.status_akun`</td>
<td>`Aktif`, `Nonaktif`</td>
</tr>
</table>
> Mesin 7 (transisi khusus Force-Majeur) tidak punya kolom status sendiri — diwujudkan oleh `placement_participants.source_participation_id IS NULL` + CHECK §5 (bukan enum terpisah).
### 3.2 GIN trigram untuk cek-kemiripan (BR-DUP, PRD §9.2/§6.2)
```sql
CREATE INDEX idx_candidate_nama_alpha_trgm ON candidate USING gin (lower(nama_alphabet) gin_trgm_ops);
CREATE INDEX idx_candidate_nama_kana_trgm  ON candidate USING gin (nama_katakana gin_trgm_ops);
```
Query kanonik (match bila salah satu nama ≥ 0.4, DOB & kewarganegaraan exact, **mencakup draft**, **kecualikan anonim**):
```sql
SELECT id, nomor_induk, nama_alphabet, nama_katakana
FROM candidate
WHERE tanggal_lahir = :dob
  AND kewarganegaraan_id = :nat
  AND pii_anonymized_at IS NULL
  AND ( similarity(lower(nama_alphabet), lower(:nama_alpha)) >= 0.4
     OR similarity(nama_katakana, :nama_kana) >= 0.4 );
```
WAJIB `similarity() >= 0.4` eksplisit (deterministik), BUKAN operator `%`.
### 3.3 Composite index pagination (PRD §8.4)
Pagination server-side default **25/halaman**; sort/filter hanya pada kolom whitelist. **Kolom HIDE Tamu tidak boleh jadi parameter sort/filter** (MODULE_GUEST_ACCESS §3.3/§4). **v0.3.11:** field yang kini tampil di **detail G3** (`nama_alphabet`/`nama_katakana`, foto, `candidate_education.nama_institusi`, `candidate_work.nama_perusahaan`/`perusahaan_penanggung`) **tetap TIDAK boleh jadi parameter sort/filter Tamu** (cegah inferensi/enumerasi PII); daftar G2 tetap pseudonim (identifier = `nomor_induk`).
```sql
-- daftar kandidat internal (antrian tinjauan + listing)
CREATE INDEX idx_candidate_list ON candidate (status_approval, created_at DESC)
  WHERE deleted_at IS NULL AND pii_anonymized_at IS NULL;
CREATE INDEX idx_candidate_avail ON candidate (status_ketersediaan, created_at DESC)
  WHERE deleted_at IS NULL AND pii_anonymized_at IS NULL;
-- peserta wawancara (whitelist sort Tamu: nama_alphabet, umur(DOB), level bahasa)
CREATE INDEX idx_participation_container ON participation (interview_container_id, id);
-- peserta penempatan
CREATE INDEX idx_pp_container ON placement_participants (placement_container_id, id);
```
### 3.4 Normalisasi nama (keputusan terkunci): pg_trgm "apa adanya"
Tidak memakai `citext` maupun `unaccent`. Justifikasi: (a) BR-DUP/MODULE_CANDIDATES #6 mengunci similarity pada nama latin & katakana **apa adanya**; (b) pg_trgm sudah lowercase + mengabaikan non-alfanumerik (case-insensitive tanpa citext); (c) nama latin Indonesia jarang berdiakritik sehingga unaccent tak memberi nilai tambah dan justru mengubah "apa adanya". Normalisasi terbatas `lower()` + `trim()` pada sisi latin via ekspresi index.
---
## 4. Daftar Tabel (inventaris konsolidasi)
**Domain Kandidat (16):** `candidate`, `candidate_physical`, `candidate_education`, `candidate_work`, `candidate_qual_english`, `candidate_qual_japanese`, `candidate_qual_ssw`, `candidate_qual_driving`, `candidate_qual_other`, `candidate_self_promo`, `candidate_family`, `candidate_family_contact`, `candidate_immigration`, `candidate_document` (koleksi dokumen peserta: KTP/KK/Ijazah/Zairyu Card/dll — link Google Drive privat), `candidate_photo`, `nik_counter`. *(candidate_identity_doc lama DIHAPUS; digantikan **`candidate_document`** generik 2026-07-01 — lihat §12 GAP-DB9.)*
**Domain Wawancara (2):** `interview_container`, `participation`.
**Akses Tamu (2):** `guest_link`, `guest_access_log`.
**Domain Penempatan (2):** `placement_container`, `placement_participants`.
**Master & lookup (28):** `perusahaan`, `company_request`, `lookup_request`, + **25 tabel lookup** (negara, bahasa, provinsi, kota_kabupaten, kecamatan, agama, golongan_darah, ukuran_sepatu, tingkat_penglihatan, asal_rekrutmen, status_keluarga, tingkat_pendidikan, jurusan, bidang_pekerjaan, posisi_pekerjaan, bidang_industri_perusahaan, bidang_diminati, jenis_kualifikasi_bahasa_inggris, jenis_kualifikasi_bahasa_jepang, skill_ssw, kualifikasi_mengemudi, kualifikasi_keahlian_lainnya, jenis_visa, kategori_force_majeur, jenis_dokumen). *(jenis_dokumen di-add kembali 2026-07-01 sebagai dropdown koleksi dokumen peserta **`candidate_document`** — lihat §12 GAP-DB9.)*
**Auth/RBAC (7):** `users`, `sessions`, `cache`, `cache_locks`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.
**Lintas-modul & infra (7):** `pending_request`, `audit_log`, `notifications`, `jobs`, `failed_jobs`, `job_batches`, `container_counter` (counter Kode Kontainer per-prefix per-tahun — W-/P-, lihat §5.1).
> **Keputusan final:** tidak ada tabel `candidate_participation`. Riwayat kandidat adalah read-model/service union atas `participation` + `placement_participants`; keputusan ini telah disetujui dan konsep tabel generik resmi disupersede.
---
## 5. DDL per Domain
### 5.1 Domain Kandidat
```sql
CREATE TABLE candidate (
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nomor_induk         VARCHAR(13),                       -- K-YYYY-NNNNN; NULL saat draft (PII? operasional)
  -- ===== PII =====
  nama_alphabet       TEXT NOT NULL,                      -- PII (trigram latin)
  nama_katakana       TEXT,                               -- PII (trigram katakana)
  tanggal_lahir       DATE NOT NULL,                      -- PII (exact-match dup + umur computed)
  tempat_lahir_kota_id BIGINT REFERENCES kota_kabupaten(id),  -- Tempat Lahir = dropdown Kota/Kabupaten (PII; Opsi B 2026-06-30)
  alamat_detail       TEXT,                               -- PII: detail alamat lengkap (jalan/RT/RW), teks bebas
  email               TEXT,                               -- PII
  phone               TEXT,                               -- PII
  line_id             TEXT,                               -- PII
  -- ===== operasional / referensi lookup =====
  kewarganegaraan_id  BIGINT NOT NULL REFERENCES negara(id),
  asal_rekrutmen_id   BIGINT REFERENCES asal_rekrutmen(id),
  agama_id            BIGINT REFERENCES agama(id),
  alamat_provinsi_id       BIGINT REFERENCES provinsi(id),        -- Alamat: Provinsi (Opsi B 2026-06-30)
  alamat_kota_kabupaten_id BIGINT REFERENCES kota_kabupaten(id),  -- Alamat: Kota/Kabupaten
  alamat_kecamatan_id      BIGINT REFERENCES kecamatan(id),       -- Alamat: Kecamatan
  jenis_kelamin       TEXT NOT NULL CHECK (jenis_kelamin IN ('M','F')),
  status_pernikahan   TEXT CHECK (status_pernikahan IN ('MARRIED','SINGLE')),
  status_ketersediaan TEXT NOT NULL DEFAULT 'TERSEDIA' CHECK (status_ketersediaan IN ('TERSEDIA','SEDANG_DIPAKAI')),
  status_approval     TEXT NOT NULL DEFAULT 'Draft'
        CHECK (status_approval IN ('Draft','Menunggu Tinjauan-BARU','Menunggu Tinjauan-REVISI','Disetujui','Ditolak','Diterapkan')),
  -- ===== draft-revisi (GAP-6) =====
  parent_candidate_id BIGINT REFERENCES candidate(id) ON DELETE CASCADE,  -- baris revisi → induk
  -- ===== jejak & konkurensi =====
  version             INTEGER NOT NULL DEFAULT 0,
  created_by          BIGINT NOT NULL REFERENCES users(id),
  approved_by         BIGINT REFERENCES users(id),
  -- ===== siklus hidup data =====
  deleted_at          TIMESTAMPTZ,                        -- RESERVED/DEFERRED; no route/button/policy soft-delete di MVP
  pii_anonymized_at   TIMESTAMPTZ,                        -- tombstone PII (permanen, irreversible)
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT candidate_nomor_induk_unique UNIQUE (nomor_induk),
  -- Maker-Checker: approver != pembuat saat Disetujui (BR-APV)
  CONSTRAINT candidate_maker_checker CHECK (status_approval <> 'Disetujui' OR approved_by IS NULL OR approved_by <> created_by)
);
```
> `umur` TIDAK disimpan (computed dari `tanggal_lahir`, render `歳`). Revision row memakai `parent_candidate_id`, `nomor_induk=NULL`, dan snapshot child collections. Maksimum satu revision Draft/menunggu aktif per main candidate.
```sql
CREATE UNIQUE INDEX uq_candidate_one_active_revision ON candidate (parent_candidate_id)
WHERE parent_candidate_id IS NOT NULL
  AND status_approval IN ('Draft','Menunggu Tinjauan-REVISI');
```
Index lain: lihat §3.2/§3.3.
```sql
CREATE TABLE candidate_physical (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id    BIGINT NOT NULL UNIQUE REFERENCES candidate(id) ON DELETE CASCADE,  -- 1:1
  tinggi_cm       NUMERIC(5,2),
  berat_kg        NUMERIC(5,2),
  lingkar_perut_cm NUMERIC(5,2),                          -- PRD §5.2: Lingkar Perut (cm)
  golongan_darah_id     BIGINT REFERENCES golongan_darah(id),
  ukuran_sepatu_id      BIGINT REFERENCES ukuran_sepatu(id),
  penglihatan_kiri_id   BIGINT REFERENCES tingkat_penglihatan(id),
  penglihatan_kanan_id  BIGINT REFERENCES tingkat_penglihatan(id),
  dominan_tangan  TEXT CHECK (dominan_tangan IN ('RIGHT','LEFT')),
  -- boolean fisik ber-glyph 有り/無し → disimpan TEXT YES/NO (backed enum)
  buta_warna        TEXT CHECK (buta_warna IN ('YES','NO')),
  merokok           TEXT CHECK (merokok IN ('YES','NO')),
  minum_sake        TEXT CHECK (minum_sake IN ('YES','NO')),
  pembatasan_makanan TEXT CHECK (pembatasan_makanan IN ('YES','NO')),
  riwayat_penyakit  TEXT CHECK (riwayat_penyakit IN ('YES','NO')),
  riwayat_operasi   TEXT CHECK (riwayat_operasi IN ('YES','NO')),
  catatan_kesehatan TEXT,                                  -- PII (detail bila YES)
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE candidate_education (   -- maks 5 (enforce aplikasi)
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id    BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  tingkat_pendidikan_id BIGINT NOT NULL REFERENCES tingkat_pendidikan(id),
  jurusan_id      BIGINT REFERENCES jurusan(id),
  nama_institusi  TEXT,            -- PII; DITAMPILKAN di detail G3 Tamu (v0.3.11); tetap TIDAK jadi sort/filter Tamu
  tanggal_masuk   DATE,            -- PRD §5.2: "Tanggal Masuk/Keluar: date"
  tanggal_keluar  DATE,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE candidate_work (        -- maks 5 (PRD §5.2)
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id    BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  nama_perusahaan TEXT,                   -- DITAMPILKAN di detail G3 Tamu (PRD Lampiran C v0.3.11); tetap TIDAK jadi sort/filter Tamu
  perusahaan_penanggung TEXT,             -- opsional: nama TSK/Kumiai penanggung saat pengalaman kerja di Jepang (PRD §5.2)
  bidang_pekerjaan_id BIGINT REFERENCES bidang_pekerjaan(id),
  tanggal_masuk   DATE,                   -- PRD §5.2: "Tanggal Masuk/Keluar: date"
  tanggal_keluar  DATE,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
> **Kualifikasi (normalisasi penuh):** MODULE_CANDIDATES menyebut satu konsep `candidate_qualification`; karena satu FK tak bisa menunjuk 5 lookup berbeda, diwujudkan sebagai **5 tabel anak ber-FK bersih** (selaras keputusan normalisasi penuh). Semua punya `candidate_id` (CASCADE) + timestamps.
```sql
CREATE TABLE candidate_qual_english (   -- bahasa Inggris (PRD §5.2: Jenis, Tanggal Akuisisi, Score, URL File)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  jenis_id BIGINT NOT NULL REFERENCES jenis_kualifikasi_bahasa_inggris(id),
  tanggal_akuisisi DATE,
  skor TEXT,                              -- Score
  url_file TEXT,                          -- link Google Drive sertifikat (privat; ke Tamu via Drive "anyone with link" bila lookup is_shareable)
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_qual_japanese (  -- bahasa Jepang (PRD §5.2: Jenis, Tanggal Akuisisi, Score, URL File)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  jenis_id BIGINT NOT NULL REFERENCES jenis_kualifikasi_bahasa_jepang(id),
  tanggal_akuisisi DATE,
  skor TEXT,                              -- Score
  url_file TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_qual_ssw (       -- Keahlian Jepang/SSW, maks 8 (PRD §5.2: Jenis, Tanggal Akuisisi, URL File)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  skill_ssw_id BIGINT NOT NULL REFERENCES skill_ssw(id),
  tanggal_akuisisi DATE,
  url_file TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_qual_driving (   -- mengemudi, maks 5 (PRD §5.2: Jenis, Tanggal Akuisisi)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  kualifikasi_mengemudi_id BIGINT NOT NULL REFERENCES kualifikasi_mengemudi(id),
  tanggal_akuisisi DATE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_qual_other (     -- keahlian lainnya, maks 5 (PRD §5.2: Jenis, Tanggal Akuisisi, URL File)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  kualifikasi_keahlian_lainnya_id BIGINT NOT NULL REFERENCES kualifikasi_keahlian_lainnya(id),
  tanggal_akuisisi DATE,
  url_file TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
> Flag `is_shareable` untuk whitelist Tamu hidup di lookup `skill_ssw` & `kualifikasi_keahlian_lainnya` (bukan di tabel anak) — sertifikat tampil ke Tamu hanya bila lookup-nya `is_shareable=true`.
```sql
CREATE TABLE candidate_self_promo (     -- 1:1 (PRD §5.2 Promosi Diri)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL UNIQUE REFERENCES candidate(id) ON DELETE CASCADE,
  skor_iq SMALLINT,                      -- IQ Score; HIDE Tamu
  skor_matematika SMALLINT,              -- MTK Score; HIDE Tamu
  bidang_diminati_id BIGINT REFERENCES bidang_diminati(id),
  video_jikoshokai_url TEXT,             -- Video Jikoshokai: EMBED URL (mis. YouTube/Drive embed); default OFF ke Tamu
  video_keahlian_url TEXT,               -- Video Keahlian: EMBED URL; default OFF ke Tamu
  final_laporan_psikotes TEXT,           -- HIDE Tamu
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_family (         -- maks 10 (PRD §5.2 Informasi Keluarga)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  status_keluarga_id BIGINT NOT NULL REFERENCES status_keluarga(id),
  nama TEXT,                   -- Nama Keluarga; PII / HIDE Tamu
  tanggal_lahir DATE,          -- Tanggal Lahir Keluarga; umur computed (render 歳), tidak disimpan
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_family_contact ( -- 1 (kontak darurat)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL UNIQUE REFERENCES candidate(id) ON DELETE CASCADE,
  status_keluarga_id BIGINT REFERENCES status_keluarga(id),
  nama TEXT, phone TEXT, alamat TEXT,   -- semua PII / HIDE Tamu
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE candidate_immigration (    -- 1:1 (info imigrasi; PII / HIDE Tamu)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL UNIQUE REFERENCES candidate(id) ON DELETE CASCADE,
  nomor_paspor TEXT,                    -- PII
  masa_berlaku_paspor DATE,
  nomor_zairyu TEXT,                    -- PII
  alamat_zairyu TEXT,                   -- PII
  jenis_visa_id BIGINT REFERENCES jenis_visa(id),   -- status tinggal saat ini
  pernah_ke_jepang TEXT CHECK (pernah_ke_jepang IN ('YES','NO')),
  catatan TEXT,
  -- Foto Zairyu Card TIDAK lagi di sini: kini satu baris di candidate_document
  -- (jenis_dokumen = ZAIRYU_CARD, url_dokumen = link Google Drive privat). Keputusan user 2026-07-01.
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
```sql
-- ===== Koleksi Dokumen Peserta (candidate_document) — keputusan user 2026-07-01 =====
-- Menggantikan candidate_identity_doc lama. Tabel BERULANG (seperti candidate_work/candidate_education):
-- kumpulan dokumen peserta (KTP, KK, Ijazah, Zairyu Card, dll). Tiap baris = satu dokumen:
-- jenis (dropdown lookup jenis_dokumen) + url_dokumen (link Google Drive PRIVAT, "tidak diset public").
-- File TIDAK di-upload ke aplikasi (URL input). Tanpa envelope encryption / R2 / signed URL.
CREATE TABLE candidate_document (
  id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id     BIGINT NOT NULL REFERENCES candidate(id) ON DELETE CASCADE,
  jenis_dokumen_id BIGINT NOT NULL REFERENCES jenis_dokumen(id),   -- dropdown: KTP/KK/IJAZAH/ZAIRYU_CARD/dll
  url_dokumen      TEXT NOT NULL,        -- link Google Drive PRIVAT (bukan public); PII / HIDE Tamu
  nama_file        TEXT,                 -- label opsional dokumen
  catatan          TEXT,
  uploaded_by      BIGINT REFERENCES users(id),
  sort_order       SMALLINT NOT NULL DEFAULT 0,
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_candidate_document_candidate ON candidate_document (candidate_id);
-- Akses dokumen sensitif (Zairyu Card, KTP, dll) tetap diaudit IDENTITY_DOC_VIEWED (§7). HIDE Tamu.

CREATE TABLE candidate_photo (          -- 1:1 (foto wajah; tetap UPLOAD bucket privat R2 + SSE; HIDE thumbnail diatur aplikasi)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  candidate_id BIGINT NOT NULL UNIQUE REFERENCES candidate(id) ON DELETE CASCADE,
  object_key TEXT NOT NULL, mime_type TEXT NOT NULL, size_bytes BIGINT,
  uploaded_by BIGINT NOT NULL REFERENCES users(id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE nik_counter (              -- counter Nomor Induk per-tahun (JST)
  year       SMALLINT PRIMARY KEY,      -- tahun Asia/Tokyo
  last_value INTEGER NOT NULL DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE container_counter (        -- counter Kode Kontainer per-prefix per-tahun (JST) — 'W' wawancara / 'P' penempatan
  prefix     VARCHAR(2) NOT NULL,       -- 'W' | 'P'
  year       SMALLINT NOT NULL,         -- tahun Asia/Tokyo
  last_value INTEGER NOT NULL DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (prefix, year)
);
-- Assign kode saat submit pertama kontainer (Draft->Menunggu Approval), UPSERT transaksional:
--   INSERT INTO container_counter (prefix, year, last_value) VALUES (:p, :y, 1)
--   ON CONFLICT (prefix, year) DO UPDATE SET last_value = container_counter.last_value + 1, updated_at = now()
--   RETURNING last_value;
-- year = EXTRACT(YEAR FROM (now() AT TIME ZONE 'Asia/Tokyo')); kode = prefix||'-'||year||'-'||LPAD(last_value::text,5,'0').
-- Reset per-tahun (JST); gap diizinkan; kode UNIQUE & immutable setelah di-assign (WAJIB MVP, tanpa backfill).
```
### 5.2 Domain Wawancara
```sql
CREATE TABLE interview_container (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kode_kontainer  VARCHAR(13) UNIQUE,                              -- W-YYYY-NNNNN; NULL saat Draft, di-assign saat submit pertama (Draft->Menunggu Approval); immutable setelah assign (BR-KODE)
  judul           TEXT NOT NULL,
  perusahaan_id   BIGINT NOT NULL REFERENCES perusahaan(id),       -- master (FK diperbolehkan)
  posisi_pekerjaan_id BIGINT NOT NULL REFERENCES posisi_pekerjaan(id),
  jenis_wawancara TEXT NOT NULL CHECK (jenis_wawancara IN ('OFFLINE','ONLINE')),
  jenis_visa_id   BIGINT NOT NULL REFERENCES jenis_visa(id),
  tanggal_wawancara DATE,
  jumlah_peserta  INTEGER NOT NULL DEFAULT 0,                       -- dihitung dari participation aktif
  target_peserta_diterima INTEGER,                                 -- soft warning, tidak memblok
  deskripsi       TEXT,
  syarat          TEXT,
  status          TEXT NOT NULL DEFAULT 'Draft'
        CHECK (status IN ('Draft','Menunggu Approval','Aktif','Ditutup','Dibatalkan')),
  dibuat_oleh     BIGINT NOT NULL REFERENCES users(id),
  disetujui_oleh  BIGINT REFERENCES users(id),
  version         INTEGER NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_at     TIMESTAMPTZ,
  closed_at       TIMESTAMPTZ,
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ic_status ON interview_container (status);
CREATE INDEX idx_ic_perusahaan ON interview_container (perusahaan_id);

CREATE TABLE participation (            -- partisipasi kandidat di kontainer wawancara
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  interview_container_id BIGINT NOT NULL REFERENCES interview_container(id),  -- intra-modul (FK ok)
  candidate_id    BIGINT NOT NULL,      -- LINTAS-MODUL → TANPA FK (service Candidates)
  status_wawancara TEXT NOT NULL DEFAULT 'Menunggu Wawancara'
        CHECK (status_wawancara IN ('Menunggu Wawancara','Lulus','Proses Dokumen','Siap Dikirim','Terkirim','Tidak Lolos','Mengundurkan Diri','Dikeluarkan')),
  catatan         TEXT,
  version         INTEGER NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_participation_container ON participation (interview_container_id, id);
CREATE INDEX idx_participation_candidate ON participation (candidate_id);
CREATE UNIQUE INDEX uq_participation_one_active ON participation (candidate_id)
  WHERE status_wawancara IN ('Menunggu Wawancara','Lulus','Proses Dokumen','Siap Dikirim');
```
### 5.3 Akses Tamu
```sql
CREATE TABLE guest_link (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  interview_container_id BIGINT NOT NULL,    -- LINTAS-MODUL (Tamu→Wawancara) → TANPA FK
  token_hash      TEXT NOT NULL UNIQUE,      -- HASH token acak (≠ id kontainer); token mentah tak disimpan
  kode_tambahan_hash TEXT,                   -- opsional (hash, constant-time compare)
  tanggal_kadaluarsa TIMESTAMPTZ NOT NULL,
  status_link     TEXT NOT NULL DEFAULT 'Menunggu Approval'
        CHECK (status_link IN ('Menunggu Approval','Aktif','Kadaluarsa')),
  dibuat_oleh     BIGINT NOT NULL REFERENCES users(id),
  disetujui_oleh  BIGINT REFERENCES users(id),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_at     TIMESTAMPTZ,
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Link ditolak (GAP-2): TIDAK ada baris guest_link (penolakan hidup di pending_request).

CREATE TABLE guest_access_log (         -- append-only (PRD §5.3)
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  guest_link_id   BIGINT NOT NULL REFERENCES guest_link(id),   -- intra-modul (FK ok)
  accessed_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  ip              INET,                  -- data pribadi → retensi via DATA_RETENTION_AND_PRIVACY
  user_agent      TEXT
);
CREATE INDEX idx_gal_link ON guest_access_log (guest_link_id, accessed_at);
```
> Percobaan akses GAGAL (token salah/kadaluarsa/kode salah) **tidak** punya enum Lampiran A → ditulis ke **log keamanan aplikasi**, BUKAN `audit_log`/`guest_access_log` (MODULE_GUEST_ACCESS §9).
### 5.4 Domain Penempatan
```sql
CREATE TABLE placement_container (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  kode_kontainer  VARCHAR(13) UNIQUE,                              -- P-YYYY-NNNNN; NULL saat Draft, di-assign saat submit pertama (Draft->Menunggu Approval); immutable setelah assign (BR-KODE)
  nama            TEXT NOT NULL,
  perusahaan_id   BIGINT NOT NULL REFERENCES perusahaan(id),   -- IMMUTABLE setelah dibuat (enforce aplikasi)
  status          TEXT NOT NULL DEFAULT 'Draft'
        CHECK (status IN ('Draft','Menunggu Approval','Aktif','Arsip','Dibatalkan')),
  dibuat_oleh     BIGINT NOT NULL REFERENCES users(id),
  disetujui_oleh  BIGINT REFERENCES users(id),
  version         INTEGER NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_at     TIMESTAMPTZ,
  archived_at     TIMESTAMPTZ,
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_pc_status ON placement_container (status);
CREATE INDEX idx_pc_perusahaan ON placement_container (perusahaan_id);

CREATE TABLE placement_participants (   -- nama plural = kanonik PRD §5.4
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  placement_container_id BIGINT NOT NULL REFERENCES placement_container(id),  -- intra-modul (FK ok)
  candidate_id    BIGINT NOT NULL,             -- LINTAS-MODUL → TANPA FK
  source_participation_id BIGINT,              -- LINTAS-MODUL (→participation) TANPA FK; NULL ⇒ Force-Majeur
  kategori_force_majeur_id BIGINT REFERENCES kategori_force_majeur(id),  -- lookup (FK ok)
  alasan_force_majeur TEXT,
  jenis_visa_id   BIGINT NOT NULL REFERENCES jenis_visa(id),
  tanggal_mulai_kerja DATE,
  durasi_kontrak_bulan INTEGER,
  tanggal_berakhir_kontrak DATE,               -- default inklusif: mulai + durasi bulan - 1 hari; override >= mulai
  status_penempatan TEXT NOT NULL DEFAULT 'Bekerja'
        CHECK (status_penempatan IN ('Bekerja','Selesai Kontrak','Mengundurkan Diri','Dikeluarkan')),
  tanggal_status_final DATE,
  catatan_alasan  TEXT,                         -- wajib utk Mengundurkan Diri & Dikeluarkan (enforce aplikasi)
  disetujui_oleh  BIGINT REFERENCES users(id),
  version         INTEGER NOT NULL DEFAULT 0,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  -- Force-Majeur: ref null ⟺ kategori+alasan terisi
  CONSTRAINT pp_force_majeur_chk CHECK (
    (source_participation_id IS NULL) = (kategori_force_majeur_id IS NOT NULL AND alasan_force_majeur IS NOT NULL)
  )
);
-- Satu kandidat hanya boleh satu partisipasi 'Bekerja' aktif (lintas kontainer)
CREATE UNIQUE INDEX uq_pp_one_active_work ON placement_participants (candidate_id)
  WHERE status_penempatan = 'Bekerja';
CREATE INDEX idx_pp_container ON placement_participants (placement_container_id, id);
CREATE INDEX idx_pp_candidate ON placement_participants (candidate_id);
```
### 5.5 Master & Lookup
```sql
CREATE TABLE perusahaan (               -- master (PRD §5.4); BUKAN lookup generik
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama_ja         TEXT NOT NULL,        -- WAJIB (BR-I18N-01)
  nama_romaji     TEXT,                 -- opsional
  nama_id         TEXT,                 -- opsional
  negara_id       BIGINT REFERENCES negara(id),
  bidang_industri_id BIGINT REFERENCES bidang_industri_perusahaan(id),
  alamat          TEXT,
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,   -- soft-disable
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_perusahaan_active ON perusahaan (is_active);

-- ===== Skema umum 25 tabel lookup (MODULE_LOOKUP_DATA §5) =====
-- Pola dasar (diulang per tabel; kolom tambahan per-tabel di catatan):
CREATE TABLE <lookup> (
  id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code        VARCHAR(64) NOT NULL,
  label_id    VARCHAR(255) NOT NULL,
  label_ja    VARCHAR(255) NOT NULL,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  is_active   BOOLEAN NOT NULL DEFAULT TRUE,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT <lookup>_code_unique UNIQUE (code),
  CONSTRAINT <lookup>_code_not_empty CHECK (length(trim(code)) > 0)
);
```
**Kolom tambahan & CHECK format ****`code`**** per tabel lookup:**
<table header-row="true">
<tr>
<td>Tabel</td>
<td>Kolom tambahan</td>
<td>CHECK `code`</td>
</tr>
<tr>
<td>`negara`</td>
<td>`region TEXT`, `dial_code TEXT`</td>
<td>`~ '^[A-Z]{2}$'` (ISO 3166-1)</td>
</tr>
<tr>
<td>`bahasa`</td>
<td>—</td>
<td>`~ '^[a-z]{2}$'` (ISO 639-1)</td>
</tr>
<tr>
<td>`provinsi`</td>
<td>`negara_id BIGINT REFERENCES negara(id)`</td>
<td>`~ '^[A-Z0-9_]+$'`</td>
</tr>
<tr>
<td>`kota_kabupaten`</td>
<td>`provinsi_id BIGINT REFERENCES provinsi(id)`</td>
<td>idem</td>
</tr>
<tr>
<td>`kecamatan`</td>
<td>`kota_kabupaten_id BIGINT REFERENCES kota_kabupaten(id)`</td>
<td>idem</td>
</tr>
<tr>
<td>`posisi_pekerjaan`</td>
<td>`bidang_pekerjaan_id BIGINT REFERENCES bidang_pekerjaan(id)`</td>
<td>idem</td>
</tr>
<tr>
<td>`skill_ssw`</td>
<td>`bidang_id BIGINT REFERENCES bidang_pekerjaan(id)`, `is_shareable BOOLEAN NOT NULL DEFAULT FALSE`</td>
<td>idem</td>
</tr>
<tr>
<td>`kualifikasi_keahlian_lainnya`</td>
<td>`is_shareable BOOLEAN NOT NULL DEFAULT FALSE`</td>
<td>idem</td>
</tr>
<tr>
<td>`jenis_visa`</td>
<td>`kategori TEXT`</td>
<td>idem</td>
</tr>
<tr>
<td>16 lookup lainnya</td>
<td>— (skema dasar)</td>
<td>`~ '^[A-Z0-9_]+$'`</td>
</tr>
</table>
> 25 lookup: negara, bahasa, provinsi, kota_kabupaten, kecamatan, agama, golongan_darah, ukuran_sepatu, tingkat_penglihatan, asal_rekrutmen, status_keluarga, tingkat_pendidikan, jurusan, bidang_pekerjaan, posisi_pekerjaan, bidang_industri_perusahaan, bidang_diminati, jenis_kualifikasi_bahasa_inggris, jenis_kualifikasi_bahasa_jepang, skill_ssw, kualifikasi_mengemudi, kualifikasi_keahlian_lainnya, jenis_visa, **kategori_force_majeur**, **jenis_dokumen**. *(jenis_dokumen ditambahkan 2026-07-01; jenis_dokumen_identitas lama diganti generik.)*
```sql
CREATE TABLE company_request (          -- antrian request perusahaan baru (PRD §7.8)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nama_ja TEXT NOT NULL, nama_romaji TEXT, nama_id TEXT,
  requested_by BIGINT NOT NULL REFERENCES users(id),
  reason TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
  reviewed_by BIGINT REFERENCES users(id), note_checker TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), reviewed_at TIMESTAMPTZ, updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE lookup_request (           -- antrian request nilai lookup baru (PRD §7.8)
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  lookup_table TEXT NOT NULL, code TEXT, label_id TEXT, label_ja TEXT,
  extra JSONB,                          -- mis. {bidang_id:..} untuk posisi_pekerjaan/skill_ssw
  requested_by BIGINT NOT NULL REFERENCES users(id), reason TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
  reviewed_by BIGINT REFERENCES users(id), note_checker TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(), reviewed_at TIMESTAMPTZ, updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
### 5.6 Auth / RBAC (MODULE_AUTH)
```sql
CREATE TABLE users (
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name            TEXT NOT NULL,
  email           TEXT NOT NULL,                     -- satu-satunya login identifier MVP; normalisasi lowercase
  email_verified_at TIMESTAMPTZ,
  password        TEXT NOT NULL,                 -- bcrypt cost 12 (A-3)
  two_factor_secret TEXT,                        -- ENCRYPTED at-rest (cast encrypted)
  two_factor_recovery_codes TEXT,                -- ENCRYPTED; 8 kode single-use (A-5)
  two_factor_confirmed_at TIMESTAMPTZ,           -- anti lock-out enrolment
  must_change_password BOOLEAN NOT NULL DEFAULT TRUE,   -- PRD §6.1
  status_akun     TEXT NOT NULL DEFAULT 'Aktif' CHECK (status_akun IN ('Aktif','Nonaktif')),
  remember_token  VARCHAR(100),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
```
```sql
CREATE UNIQUE INDEX uq_users_email_lower ON users (lower(email));
```
- **Login identifier:** email saja; tidak ada username. Simpan email lowercase dan gunakan `lower(email)|ip` untuk throttle.
- **Lockout / rate-limit (A-4):** memakai RateLimiter Laravel berbasis **driver cache ****`redis`** (production, 2026-07-13). Key `lower(email)|ip`, 5 gagal/15 menit. Tabel `cache`/`cache_locks` tetap ada sebagai fallback non-prod.
- **sessions** (driver database):
```sql
CREATE TABLE sessions (
  id            VARCHAR(255) PRIMARY KEY,
  user_id       BIGINT REFERENCES users(id),
  ip_address    VARCHAR(45),
  user_agent    TEXT,
  payload       TEXT NOT NULL,
  last_activity INTEGER NOT NULL
);
CREATE INDEX idx_sessions_user ON sessions (user_id);
CREATE INDEX idx_sessions_last_activity ON sessions (last_activity);
```
- **cache & cache_locks** (driver database, juga dipakai lockout/rate-limit & cache lookup):
```sql
CREATE TABLE cache (key VARCHAR(255) PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL);
CREATE TABLE cache_locks (key VARCHAR(255) PRIMARY KEY, owner VARCHAR(255) NOT NULL, expiration INTEGER NOT NULL);
```
- **spatie/laravel-permission \^8.1** (skema standar; 6 role hardcode di-seed):
```sql
CREATE TABLE roles (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name VARCHAR(255) NOT NULL, guard_name VARCHAR(255) NOT NULL, created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ, UNIQUE (name, guard_name));
CREATE TABLE permissions (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, name VARCHAR(255) NOT NULL, guard_name VARCHAR(255) NOT NULL, created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ, UNIQUE (name, guard_name));
CREATE TABLE model_has_roles (role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE, model_type VARCHAR(255) NOT NULL, model_id BIGINT NOT NULL, PRIMARY KEY (role_id, model_id, model_type));
CREATE TABLE model_has_permissions (permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE, model_type VARCHAR(255) NOT NULL, model_id BIGINT NOT NULL, PRIMARY KEY (permission_id, model_id, model_type));
CREATE TABLE role_has_permissions (permission_id BIGINT NOT NULL REFERENCES permissions(id) ON DELETE CASCADE, role_id BIGINT NOT NULL REFERENCES roles(id) ON DELETE CASCADE, PRIMARY KEY (permission_id, role_id));
```
### 5.7 Lintas-modul & Infrastruktur
```sql
CREATE TABLE pending_request (          -- entitas Maker-Checker (PRD §7.4)
  id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  type            TEXT NOT NULL CHECK (type IN ('CANDIDATE_NEW','CANDIDATE_REVISION','IC_CREATE','PC_CREATE','PLACEMENT_BATCH','IC_CLOSE','IC_EXPEL','GUEST_LINK','PC_CANCEL_ACTIVE','PLACEMENT_RESIGN','PLACEMENT_EXPEL','FORCE_MAJEUR')),
  target_type     TEXT NOT NULL,        -- entitas sasaran (mis. interview_container)
  target_id       BIGINT NOT NULL,
  requested_by    BIGINT NOT NULL REFERENCES users(id),
  reason_maker    TEXT,
  checker_id      BIGINT REFERENCES users(id),
  note_checker    TEXT,
  payload         JSONB,                -- snapshot wajib utk PLACEMENT_BATCH/FORCE_MAJEUR/expel/resign/cancel
  status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  decided_at      TIMESTAMPTZ,
  updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Anti double-request: satu pending aktif per (type,target)
CREATE UNIQUE INDEX uq_pending_active ON pending_request (type, target_type, target_id)
  WHERE status = 'pending';
ALTER TABLE pending_request ADD CONSTRAINT pending_payload_required CHECK (
  type NOT IN ('PLACEMENT_BATCH','FORCE_MAJEUR','IC_EXPEL','PC_CANCEL_ACTIVE','PLACEMENT_RESIGN','PLACEMENT_EXPEL')
  OR payload IS NOT NULL
);
```
```sql
CREATE TABLE audit_log (                -- IMMUTABLE, Super Admin read-only (PRD §7.11/Lampiran A, ARCH D4)
  id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_id            BIGINT REFERENCES users(id),   -- NULLABLE (Tamu/sistem = NULL)
  actor_role_snapshot TEXT,                           -- SNAPSHOT peran aktor SAAT kejadian (bukan join live; peran bisa berubah). NULL utk Tamu/sistem.
  action_type         TEXT NOT NULL,                  -- backed-enum aplikasi (PHP 8.4), TANPA CHECK keras DB → §7 (GAP-DB4)
  entity_type         TEXT NOT NULL,
  entity_id           BIGINT,
  detail              JSONB,
  ip                  INET,
  user_agent          TEXT,                           -- opsional; forensik viewer audit S5
  created_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id);
CREATE INDEX idx_audit_actor  ON audit_log (actor_id);
CREATE INDEX idx_audit_action ON audit_log (action_type);
CREATE INDEX idx_audit_created ON audit_log (created_at);
```
Immutabilitas ditegakkan: REVOKE UPDATE/DELETE pada role aplikasi + trigger `BEFORE UPDATE OR DELETE` yang `RAISE EXCEPTION`. (Enumerasi `action_type` lengkap + CHECK opsional di §7.)
```sql
-- Infrastruktur Laravel (driver database) — ARCH D5/D6
CREATE TABLE notifications (            -- in-app (database notification channel)
  id UUID PRIMARY KEY, type VARCHAR(255) NOT NULL,
  notifiable_type VARCHAR(255) NOT NULL, notifiable_id BIGINT NOT NULL,
  data TEXT NOT NULL, read_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ, updated_at TIMESTAMPTZ
);
CREATE INDEX idx_notif_notifiable ON notifications (notifiable_type, notifiable_id);
CREATE TABLE jobs (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts SMALLINT NOT NULL, reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL);
CREATE INDEX idx_jobs_queue ON jobs (queue);
CREATE TABLE failed_jobs (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, uuid VARCHAR(255) NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE job_batches (id VARCHAR(255) PRIMARY KEY, name VARCHAR(255) NOT NULL, total_jobs INTEGER NOT NULL, pending_jobs INTEGER NOT NULL, failed_jobs INTEGER NOT NULL, failed_job_ids TEXT NOT NULL, options TEXT, cancelled_at INTEGER, created_at INTEGER NOT NULL, finished_at INTEGER);
```
---
## 6. Sequence / Counter Nomor Induk (BR-NIK)
Format `K-YYYY-NNNNN`; `YYYY` = tahun **Asia/Tokyo**; di-assign saat SUBMIT pertama (draft = NULL); gap diizinkan; keunikan permanen via UNIQUE `candidate.nomor_induk`.
```sql
-- dalam transaksi submit (atomik, anti-race via row lock UPSERT):
WITH y AS (SELECT EXTRACT(YEAR FROM (now() AT TIME ZONE 'Asia/Tokyo'))::smallint AS yr)
INSERT INTO nik_counter (year, last_value) SELECT yr, 1 FROM y
ON CONFLICT (year) DO UPDATE SET last_value = nik_counter.last_value + 1, updated_at = now()
RETURNING year, last_value;
-- nomor_induk := 'K-' || year || '-' || lpad(last_value::text, 5, '0')
```
Keputusan: **tabel counter** (bukan SEQUENCE per-tahun) — lebih auditable, reset per-tahun mudah, tanpa membuat objek SEQUENCE dinamis.
---
## 7. Audit Log — Enumerasi `action_type` lengkap (Lampiran A v0.3.11)
**Keputusan (GAP-DB4, dikonfirmasi user 2026-07-11):** `action_type` = **backed-enum aplikasi (PHP 8.4)**, BUKAN CHECK keras DB — agar penambahan event non-breaking. Daftar A.1 = **kontrak filter viewer audit S5**. `audit_log` menyimpan **snapshot peran aktor** (`actor_role_snapshot`) + `user_agent` (§5.7). Daftar kanonik FINAL:
- **Auth (v0.3.1):** `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGIN_LOCKED_OUT`, `LOGOUT`, `TWOFA_SETUP`, `TWOFA_VERIFIED`, `TWOFA_FAILED`, `TWOFA_RECOVERY_USED`, `PASSWORD_CHANGED`, `STEPUP_REAUTH`, `STEPUP_FAILED`.
- **User/Role:** `USER_CREATED`, `USER_UPDATED`, `USER_DEACTIVATED`, `USER_REACTIVATED`, `ROLE_ASSIGNED`, `ROLE_CHANGED`, `PASSWORD_RESET_BY_ADMIN`. *(v0.3.10: **`USER_UPDATED`**/**`USER_REACTIVATED`**/**`PASSWORD_RESET_BY_ADMIN`** ditambahkan; **`PASSWORD_RESET_BY_ADMIN`** = reset oleh admin, dibedakan dari **`PASSWORD_CHANGED`** self-service di domain Auth.)*
- **Lookup/Company:** `LOOKUP_CREATED`, `LOOKUP_UPDATED`, `LOOKUP_DEACTIVATED`, `LOOKUP_REACTIVATED`, `LOOKUP_REQUEST_SUBMITTED`, `LOOKUP_REQUEST_APPROVED`, `LOOKUP_REQUEST_REJECTED`, `COMPANY_CREATED`, `COMPANY_UPDATED`, `COMPANY_REQUESTED`, `COMPANY_APPROVED`, `COMPANY_REJECTED`, `COMPANY_DEACTIVATED`, `COMPANY_REACTIVATED`. *(v0.3.10: **`LOOKUP_UPDATED`**/**`LOOKUP_REACTIVATED`** — edit label & reaktivasi S1, **`code`** tetap immutable; **`COMPANY_CREATED`**/**`COMPANY_UPDATED`**/**`COMPANY_REACTIVATED`** — master perusahaan create/edit/soft-disable langsung Super Admin S3. Jalur request tetap **`COMPANY_REQUESTED/APPROVED/REJECTED`**; **`COMPANY_APPROVED`** = perusahaan lahir via request.)*
- **Kandidat:** `CANDIDATE_CREATED`, `CANDIDATE_SUBMITTED`, `CANDIDATE_APPROVED`, `CANDIDATE_REJECTED`, `CANDIDATE_REVISION_SUBMITTED`, `CANDIDATE_UPDATED`, `CANDIDATE_SOFT_DELETED` (reserved/deferred), `CANDIDATE_RESTORED` (reserved/deferred), `IDENTITY_DOC_VIEWED`, `CANDIDATE_PHOTO_UPLOADED`, `SIMILARITY_MATCH_SHOWN`, `CANDIDATE_ANONYMIZED`.
- **Wawancara:** `IC_CREATED`, `IC_SUBMITTED`, `IC_APPROVED`, `IC_REJECTED`, `IC_CANCELLED`, `IC_CLOSE_REQUESTED`, `IC_CLOSED`, `CANDIDATE_PULLED`, `PARTICIPATION_STATUS_CHANGED`, `EXPEL_REQUESTED`, `EXPEL_APPROVED`, `EXPEL_REJECTED`.
- **Tamu:** `GUEST_LINK_REQUESTED`, `GUEST_LINK_APPROVED`, `GUEST_LINK_REJECTED`, `GUEST_ACCESS` (actor_id NULL), `GUEST_DETAIL_VIEWED` (actor_id NULL — v0.3.11; pembukaan detail kandidat G3 oleh Tamu, forensik eksposur PII Nama+Foto+riwayat; `detail` = `{token_id, candidate_id, container_id, ip}`).
- **Penempatan:** `PC_CREATED`, `PC_SUBMITTED`, `PC_APPROVED`, `PC_REJECTED`, `PC_CANCELLED`, `BATCH_SENT`, `FORCE_MAJEUR_ADDED`, `FM_REJECTED`, `PLACEMENT_STATUS_CHANGED`, `RESIGN_REQUESTED`, `RESIGN_APPROVED`, `RESIGN_REJECTED`, `PLACEMENT_EXPEL_REQUESTED`, `PLACEMENT_EXPEL_APPROVED`, `PLACEMENT_EXPEL_REJECTED`, `CONTAINER_ARCHIVED`.
---
## 8. PII vs Operasional · Soft Delete vs Tombstone (PRD §7.9/§11)
<table header-row="true">
<tr>
<td>Aspek</td>
<td>`deleted_at` (soft delete)</td>
<td>`pii_anonymized_at` (tombstone PII)</td>
</tr>
<tr>
<td>Sifat</td>
<td>Operasional, **reversible**</td>
<td>PII, **permanen/irreversible**</td>
</tr>
<tr>
<td>Aktor</td>
<td>**Tidak aktif di MVP (reserved/deferred)**</td>
<td>**Super Admin + step-up**</td>
</tr>
<tr>
<td>Audit</td>
<td>`CANDIDATE_SOFT_DELETED` / `CANDIDATE_RESTORED`</td>
<td>`CANDIDATE_ANONYMIZED`</td>
</tr>
<tr>
<td>Efek kolom</td>
<td>Data tetap utuh, hanya tersembunyi</td>
<td>Kolom PII dikosongkan/scramble + foto R2 dihapus + link/berkas dokumen di Google Drive dihapus (foto+dokumen)</td>
</tr>
<tr>
<td>Bisa diedit/dipulihkan?</td>
<td>Secara konsep reserved; tidak ada route/button/Policy MVP</td>
<td>**Tidak**</td>
</tr>
<tr>
<td>Retensi</td>
<td>—</td>
<td>PII 5 thn aktif → anonimisasi ≤1 thn (v0.3.3; jadwal → DATA_RETENTION_AND_PRIVACY)</td>
</tr>
</table>
- **Guard anonimisasi:** service wajib memverifikasi availability `Tersedia`, tanpa participation aktif, placement `Bekerja`, pending terbuka, atau revision Draft/menunggu aktif; semua direvalidasi di transaksi tepat sebelum tombstone.
- **Soft-delete Kandidat:** `deleted_at` serta event `CANDIDATE_SOFT_DELETED`/`CANDIDATE_RESTORED` reserved/deferred; tidak ada route/button/policy pada MVP.
- **Pemisahan kolom:** kolom PII ditandai eksplisit di §5.1 (nama, DOB, tempat lahir, alamat, email, phone, line_id, data fisik sensitif, keluarga/kontak, imigrasi, dok identitas, foto). Kolom operasional (mis. `nomor_induk`, jejak partisipasi, `audit_log`) **permanen** untuk integritas riwayat.
- Cek-kemiripan **mengecualikan** baris `pii_anonymized_at IS NOT NULL` (§3.2).
---
## 9. Strategi Migrasi Laravel (urutan)
1. **Ekstensi:** `DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm')`.
2. **Infra & Auth:** `cache`, `cache_locks`, `jobs`, `failed_jobs`, `job_batches`, `users`, `sessions`, spatie (`roles`/`permissions`/pivot), `notifications`.
3. **Lookup (25 tabel) + ****`perusahaan`** — tanpa dependensi domain; lalu `company_request`, `lookup_request`.
4. **Domain Kandidat:** `candidate` (default Draft; FK self + partial unique active revision belakangan), tabel anak `candidate_*`, `nik_counter`.
5. **Domain Wawancara:** `interview_container`, `participation` + partial unique active participation.
6. **Akses Tamu:** `guest_link`, `guest_access_log`.
7. **Domain Penempatan:** `placement_container`, `placement_participants`.
8. **Lintas-modul:** `pending_request` (tipe approval lengkap + payload CHECK + partial unique aktif), `audit_log` (+ trigger immutability).
9. **Index & constraint berat di belakang:** GIN trigram, partial unique, composite pagination, FK self `candidate.parent_candidate_id` (migration terpisah `ALTER TABLE` agar urutan aman).
10. **CHECK & trigger:** CHECK enum/Force-Majeur via `DB::statement`; trigger immutability `audit_log`.
11. **Seeder:** lookup bilingual idempotent (`updateOrInsert` by `code`), **termasuk ****`kategori_force_majeur`** (SAKIT_BERAT/MENINGGAL/MASALAH_KELUARGA/BENCANA_ALAM/MASALAH_HUKUM_IMIGRASI/LAINNYA); 6 role + permission; provinsi ID (38).
> CHECK/partial index/GIN/SEQUENCE ditulis via `DB::statement` (raw) karena Blueprint Laravel tak meng-cover semuanya secara native.
---
## 10. ERD ringkas (relasi & batas modul)
```javascript
[AUTH]  users 1─* (created_by/approved_by/dibuat_oleh/...) ──► semua agregat domain (FK ke users diizinkan)
        users *─* roles *─* permissions (spatie)

[LOOKUP/MASTER]  negara◄provinsi◄kota_kabupaten◄kecamatan
                 bidang_pekerjaan◄posisi_pekerjaan ; bidang_pekerjaan◄skill_ssw
                 perusahaan►negara, ►bidang_industri_perusahaan
                 (semua FK lookup/master = intra/bersama → diizinkan)

[KANDIDAT]  candidate 1─* {education, work, qual_*(5), family, document} ; 1─1 {physical, self_promo, family_contact, immigration, photo}
            candidate_document = koleksi dokumen peserta (link Google Drive privat; incl Zairyu Card, ex-candidate_identity_doc) ; candidate.parent_candidate_id ─► candidate (draft-revisi)
            candidate ►lookup(negara/agama/asal_rekrutmen/provinsi/...)
            nik_counter (standalone)

[WAWANCARA] interview_container ►perusahaan,►posisi_pekerjaan,►jenis_visa
            participation ►interview_container (FK) ; participation.candidate_id ┄┄► candidate (TANPA FK, lintas-modul)

[TAMU]      guest_link.interview_container_id ┄┄► interview_container (TANPA FK) ; guest_access_log ►guest_link (FK)

[PENEMPATAN] placement_container ►perusahaan(immutable)
             placement_participants ►placement_container (FK), ►jenis_visa, ►kategori_force_majeur
             placement_participants.candidate_id ┄┄► candidate (TANPA FK)
             placement_participants.source_participation_id ┄┄► participation (TANPA FK; NULL⇒Force-Majeur)

[LINTAS]    pending_request (target_type/target_id polymorphic, TANPA FK domain) ; audit_log (immutable) ; notifications
```
`►` = FK fisik (intra-modul / lookup / users). `┄┄►` = referensi logis lintas-modul **tanpa FK** (divalidasi service, ARCH D2).
---
## 11. Catatan Performa VPS 4 vCPU / 8 GB (baseline 2026-07-13)
- **bigint PK** tetap dipilih (hemat index vs UUID) — tetap relevan meski RAM naik.
- **PostgreSQL tuning ringan (MVP):** `shared_buffers` \~2 GB; `effective_cache_size` \~6 GB; `work_mem` 16–32 MB (hati-hati concurrent sort); autovacuum default cukup; `pg_stat_statements` opsional untuk debug.
- **Redis co-located:** cache, session, queue, rate-limit. Alokasi Redis ≤1 GB. Tabel `cache`/`sessions`/`jobs` boleh tetap sebagai fallback non-prod; production default = Redis.
- **Queue:** driver redis + **2 worker** Supervisor. Anti-duplikasi bisnis tetap unique constraint + transaksi DB.
- **Batch bulk pull/kirim ≤ 50** (MODULE_PLACEMENT #5): tetap — membatasi lock `FOR UPDATE`, bukan karena RAM saja.
- **GIN trigram** dua index pada `candidate` (latin+katakana): cukup untuk volume 500–3.000 kandidat (PRD §11).
- **Partial index** (`WHERE deleted_at IS NULL AND pii_anonymized_at IS NULL`) menjaga index listing ramping.
- **audit_log** single table; partisi range bulanan tetap ditunda pasca-MVP.
---
## 12. GAP PRD / GAP MODUL
- **GAP-DB1 RESOLVED:** `candidate_participation` generik resmi disupersede; riwayat = union/service `participation` + `placement_participants`.
- **GAP-DB2 (MODUL minor):** field detail `candidate_immigration` (nomor paspor/zairyu, alamat zairyu, jenis visa saat ini) diturunkan dari daftar HIDE Tamu (MODULE_GUEST_ACCESS §3.3) + penggunaan lookup `jenis_visa` (lookup `jenis_dokumen_identitas` telah dihapus — lihat GAP-DB7); PRD §5.2 tidak merinci kolom imigrasi satu per satu. **Update 2026-06-30:** model penyimpanan Foto Zairyu (upload terenkripsi vs URL link) telah RESOLVED via Opsi A (lihat GAP-DB7); sisa detail kolom imigrasi non-Zairyu (mis. enkripsi field nomor paspor/zairyu — GAP-DB3) masih menunggu konfirmasi.
- **GAP-DB3 (kebijakan minor):** enkripsi kolom-level untuk `candidate_immigration.nomor_paspor`/`nomor_zairyu`. PRD §9.1 hanya mewajibkan **envelope encryption untuk file dokumen identitas**, bukan field string. Default: disimpan sebagai kolom PII biasa (tunduk anonimisasi). Bila perlu enkripsi field, tandai untuk DATA_RETENTION_AND_PRIVACY.
- **GAP-DB4 (RESOLVED 2026-07-11):** `audit_log.action_type` = **backed-enum aplikasi (PHP 8.4), TANPA CHECK keras DB** (penambahan event non-breaking) — dikonfirmasi user. Ditambahkan kolom **`actor_role_snapshot TEXT`** (snapshot peran aktor saat kejadian — bukan join live ke `users`, karena peran bisa berubah; NULL utk Tamu/sistem) & **`user_agent TEXT`** (forensik viewer S5). Enum audit dilengkapi untuk verifikasi UI S1/S3/S4: `LOOKUP_UPDATED`/`LOOKUP_REACTIVATED`, `COMPANY_CREATED`/`COMPANY_UPDATED`/`COMPANY_REACTIVATED`, `USER_UPDATED`/`USER_REACTIVATED`/`PASSWORD_RESET_BY_ADMIN` (§7 + PRD Lampiran A v0.3.10). Struktur `audit_log` final: `id, actor_id, actor_role_snapshot, action_type, entity_type, entity_id, detail(JSONB), ip, user_agent, created_at`.
- **GAP-DB5 (PRD — perlu update, catatan user 2026-06-30):** `candidate_work.nama_perusahaan` kini **ditampilkan ke Tamu** (instruksi user setelah diskusi dengan atasan). Ini **berbeda dari PRD Lampiran C** (`GuestCandidateView` saat ini: "Ringkasan Riwayat Pekerjaan = Bidang Pekerjaan + durasi", nama perusahaan disembunyikan) & §7.7. **Perlu update PRD Lampiran C** + entri DECISIONS_LOG agar konsisten. Field `candidate_work.perusahaan_penanggung` (nama TSK/Kumiai penanggung, opsional) ditambahkan sesuai PRD §5.2 (sebelumnya terlewat). **RESOLVED 2026-07-12 (v0.3.11):** PRD Lampiran C direstrukturisasi berjenjang — `nama_perusahaan` + `perusahaan_penanggung` tampil di **detail G3** (bukan daftar G2 yang pseudonim); dikunci di DECISIONS_LOG
- **GAP-DB6 (RESOLVED 2026-06-30):** Tipe tanggal diselaraskan ke PRD §5.2 "date" — `candidate_work` & `candidate_education` kini memakai `tanggal_masuk`/`tanggal_keluar DATE` (bukan kolom tahun `SMALLINT`). Field hasil invensi **dihapus** agar mengikuti PRD §5.2: `candidate_work.posisi`/`deskripsi`, `candidate_self_promo.motivasi`, `candidate_family.pekerjaan`/`keterangan`, `candidate_qual_*.nomor_sertifikat`. Field PRD yang sebelumnya terlewat **ditambahkan**: `candidate_physical.lingkar_perut_cm`; `candidate_qual_*.tanggal_akuisisi` + `url_file`; `candidate_self_promo.video_keahlian_url` + `final_laporan_psikotes`; `candidate_family.tanggal_lahir` (umur computed).
- **GAP-DB7 (penghapusan ****`candidate_identity_doc`**** — keputusan user 2026-06-30):** tabel multi-dokumen `candidate_identity_doc` **DIHAPUS** karena tidak sesuai PRD §5.2 (PRD hanya menyebut satu **"Foto Zairyu Card: URL link"**; Paspor = hanya nomor tanpa scan). Foto Zairyu kini = kolom `zairyu_*` (envelope encryption) pada `candidate_immigration`. Dampak: (a) MODULE_CANDIDATES diselaraskan; (b) lookup `jenis_dokumen_identitas` **DIHAPUS** (keputusan user 2026-06-30) — inventaris lookup 25→24; MODULE_LOOKUP_DATA diselaraskan (§4 tabel, seed Lampiran A.11, GAP-L6). §4 Master & lookup menjadi 28 tabel. **(Klarifikasi 2026-06-30, Opsi A — RESOLVED):** ambiguitas "Foto Zairyu Card: URL link" (§5.2) vs kolom upload `zairyu_*` ditutup — PRD v0.3.7 memperjelas §5.2 menjadi **unggah file + envelope encryption (§9.1/§9.8) + signed URL akses**. Kolom `zairyu_*` (envelope) pada `candidate_immigration` = implementasi yang BENAR & konsisten; tidak ada perubahan struktur. Widget upload di mockup sesuai skema.
- **Diturunkan dari modul (bukan GAP baru):** seed konten beberapa lookup (agama, golongan_darah, ukuran_sepatu, tingkat_penglihatan, status_keluarga, jenis_visa, asal_rekrutmen, bidang_diminati) menunggu konfirmasi kumiai (GAP-L2/L3/L4 MODULE_LOOKUP_DATA) — tidak mengubah struktur.
- **GAP-DB8 (restrukturisasi field geografis — keputusan user 2026-06-30, Opsi B):** memisahkan **Tempat Lahir** dari **Alamat**. Tempat Lahir kini = `tempat_lahir_kota_id` (FK→`kota_kabupaten`, dropdown Kota/Kabupaten saja), menggantikan kolom bebas `tempat_lahir TEXT` yang DIHAPUS. Alamat kini terstruktur: `alamat_provinsi_id`/`alamat_kota_kabupaten_id`/`alamat_kecamatan_id` (FK→provinsi/kota_kabupaten/kecamatan) + `alamat_detail TEXT` (teks bebas jalan/RT/RW), menggantikan kolom bebas `alamat TEXT` & ketiga FK lama `provinsi_id`/`kota_kabupaten_id`/`kecamatan_id` (yang sebelumnya dipakai Tempat Lahir). Selaras PRD v0.3.8 §5.2 + MODULE_LOOKUP_DATA §4 (provinsi=Alamat, kota_kabupaten=Tempat lahir+Alamat, kecamatan=Alamat) + revisi Q2. Dukungan multi-negara tetap via rantai `kota_kabupaten → provinsi.negara_code`.
- **GAP-DB9 (pembalikan "dokumen identitas" + arsitektur file Google Drive — keputusan user 2026-07-01):** arahan atasan sebelumnya (menghapus dokumen identitas) DIKOREKSI. "Dokumen identitas" ternyata = **koleksi dokumen peserta** (KTP/KK/Ijazah/dll). Ditambahkan tabel berulang **`candidate_document`** (`jenis_dokumen_id` → lookup baru `jenis_dokumen`; `url_dokumen` = link **Google Drive privat**), pola seperti `candidate_work`/`candidate_education`. **Foto Zairyu Card** kini jadi satu baris `candidate_document` (jenis `ZAIRYU_CARD`) → seluruh kolom `zairyu_*` (envelope) pada `candidate_immigration` **DIHAPUS**. **Arsitektur file baru:** semua referensi file = **URL input link Google Drive privat** ("tidak diset public"), KECUALI (a) **foto wajah** `candidate_photo` tetap **upload** R2+SSE, dan (b) **video** (`candidate_self_promo.video_*_url`) = **embed URL**. Subsistem **envelope encryption + R2 signed URL + aws-sdk/Flysystem untuk dokumen DIHAPUS** (R2 hanya tersisa untuk foto wajah). Sertifikat shareable ke Tamu via Drive **"anyone with link"** (opsi paling sederhana, keputusan user). Lookup `jenis_dokumen` di-add (24→25). **Men-supersede GAP-DB7** (penghapusan candidate_identity_doc/jenis_dokumen_identitas) & Opsi A Foto Zairyu (envelope).
---
## 13. HANDOFF — Ringkasan untuk DECISIONS_LOG
**Penguncian penamaan ****`source_participation_id`**** (DITEGASKAN):** kolom referensi partisipasi wawancara asal pada `placement_participants` = **`source_participation_id`** (kanonik PRD §5.4, nullable, NULL ⇒ Force-Majeur). Nama lama `ref_riwayat_partisipasi` (brief Candidates) **tidak dipakai** pada skema fisik. Ini menutup catatan tindak-lanjut MODULE_PLACEMENT (#2) yang ditujukan untuk DATABASE_SCHEMA.
**Keputusan teknis DATABASE_SCHEMA (D-DB1..D-DB5, mengikuti rekomendasi, disetujui user 2026-06-30):**
1. **PK = ****`bigint GENERATED ALWAYS AS IDENTITY`** semua tabel domain/lookup (hemat RAM/index VPS 2GB; UUIDv7 ditolak).
2. **Tabel anak kandidat = normalisasi penuh** (termasuk pemecahan kualifikasi jadi 5 tabel ber-FK bersih); bukan jsonb.
3. **`audit_log`**** = satu tabel + 4 index**; partisi bulanan ditunda (opsi pasca-MVP).
4. **Normalisasi nama = pg_trgm "apa adanya"**; `citext`/`unaccent` TIDAK di-enable (justifikasi BR-DUP).
5. **Nomor Induk = tabel ****`nik_counter`**** + UPSERT transaksional** per-tahun (JST); unique constraint permanen, gap diizinkan.
**Ekstensi PostgreSQL yang harus di-enable:** `pg_trgm` (satu-satunya).
**Daftar tabel final:** 16 Kandidat + 2 Wawancara + 2 Tamu + 2 Penempatan + 28 Master/lookup + 9 Auth/RBAC + 6 Lintas-modul/infra (lihat §4). *(candidate_document + lookup jenis_dokumen ditambahkan 2026-07-01, menggantikan candidate_identity_doc lama; kolom zairyu_* envelope dihapus.)\*
**Dampak ke modul lain:**
- MODULE_CANDIDATES: `candidate_participation` generik **diusulkan disupersede** oleh `participation` + `placement_participants` (GAP-DB1 — butuh approval user sebelum dikunci).
- MODULE_LOOKUP_DATA: lookup `jenis_dokumen` (generik) di-add (24→25) untuk `candidate_document`; seeder wajib menyertakan `kategori_force_majeur` + `jenis_dokumen` (KTP/KK/IJAZAH/ZAIRYU_CARD/dll). *(Riwayat: **`jenis_dokumen_identitas`** sempat dihapus 2026-06-30, kini diganti generik.)*
- MODULE_AUTH: lockout/rate-limit production via **Redis**; tabel `cache`/`cache_locks` fallback non-prod.
**Format entri DECISIONS_LOG (disiapkan, belum ditulis ke log — menunggu approval untuk GAP-DB1):**
> **2026-06-30 — DATABASE_**[**SCHEMA.md**](DATABASE_SCHEMA.md)** — Skema fisik PostgreSQL terkonsolidasi (D-DB1..D-DB5) draft**
> - Keputusan: PK bigint identity; normalisasi penuh anak kandidat; audit_log single-table; pg_trgm apa adanya (tanpa citext/unaccent); NIK via nik_counter UPSERT. Penamaan `source_participation_id` dikunci (PRD §5.4).
> - **Affects:** K2-MODULE_CANDIDATES (rekonsiliasi candidate_participation), K2-MODULE_LOOKUP_DATA (seeder kategori_force_majeur), K2-MODULE_AUTH (lockout via cache table), K2-MODULE_PLACEMENT (penamaan kolom), K4-DATA_RETENTION_AND_PRIVACY, K4-API_CONTRACTS.
> - **SUPERSEDES:** — (mengusulkan supersede tabel generik `candidate_participation` → menunggu approval user; tidak menghapus entri lama).
---
*Status: FINAL v1.0 — Design Ready for MVP, diverifikasi 2026-07-14. Selaras PRD_Kakehashi_v0_3_14 + dependency final.*
