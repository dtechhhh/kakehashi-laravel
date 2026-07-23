---
title: "MODULE_CANDIDATES"
status: "FINAL"
source_notion_title: "MODULE_CANDIDATES"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_CANDIDATES

> [!NOTE]
> **MODULE_**[**CANDIDATES.md**](MODULE_CANDIDATES.md) — Domain Kandidat (K2 Modul) · Status: **FINAL** (disetujui 2026-06-29)
> Kelompok: 2 Modul · Persona: Domain Lead / Senior Laravel Engineer · Tgl: 2026-06-29
> Sumber kebenaran: Notion page reference (tertinggi) · Dependency final: Notion page reference, Notion page reference, Notion page reference, Notion page reference, Notion page reference
>
## 1. Scope
Modul Kandidat adalah agregat inti sistem: menyimpan profil kandidat bilingual (ID/JP), koleksi dokumen peserta (KTP/KK/Ijazah/Zairyu Card/dll) berupa link Google Drive privat, foto wajah, video promosi opsional (embed URL), serta menautkan riwayat partisipasi lintas Kontainer Wawancara & Penempatan.
**Termasuk dalam modul ini:**
- Domain model Kandidat lengkap (PRD §5.2) + entitas anak (pendidikan, pekerjaan, kualifikasi, keluarga, dokumen peserta, foto/video).
- CRUD Kandidat per peran (Operator, Approver Kandidat, Asisten Manajer, Super Admin), Maker–Checker review, alur Revisi.
- Nomor Induk Kandidat `K-YYYY-NNNNN` (assign saat submit, sequence per-tahun).
- Cek-kemiripan duplikat (pg_trgm) — soft warning, mencakup draft.
- File handling: **foto wajah** = upload R2 privat (signed URL 15 mnt); **dokumen peserta** (`candidate_document`: KTP/KK/Ijazah/Zairyu Card/dll) = link **Google Drive privat** (URL input); **video** = embed URL. Tanpa envelope encryption.
- Optimistic locking (`version` → 409) + pessimistic `FOR UPDATE` untuk bulk pull.
- Pemisahan kolom operasional vs PII + anonimisasi. Soft-delete/restore Kandidat reserved/deferred dan tidak diekspos pada MVP.
- Audit log (termasuk `doc_view` dokumen identitas WAJIB tercatat).
- Step-up re-auth untuk anonimisasi PII.
**Di luar modul ini (delegasi):**
- Detail state machine Kontainer & efek samping batch kirim → Notion page reference.
- Force-Majeur Tambah Langsung (sisi kontainer/penempatan) → MODULE_PLACEMENT (modul ini hanya menyediakan service penulisan baris partisipasi).
- Autentikasi, 2FA, mekanik step-up → Notion page reference + MODULE_AUTH.
- Master data lookup (negara, bahasa, skill SSW, dst) → Notion page reference.
- Akses Tamu (token read-only) → MODULE_GUEST_ACCESS.
---
## 2. Keputusan terkunci (GATE 2026-06-29)
Enam keputusan terbuka telah disetujui user; semuanya **selaras dependency final** (tanpa SUPERSEDES):
1. **Konflik Tamu upload sertifikat** → **Ikuti final**: Tamu = aktor token **read-only, TANPA upload** (PRD §4.3, ROLES §10 / D-R7). Brief misi yang menyebut "Tamu — hanya skill cert upload" **ditolak** karena bertentangan dengan dependency final.
2. **Akses Manajer Job di Modul Kandidat + aktor bulk pull** → **Ikuti final**: **Asisten Manajer** yang melakukan bulk pull; **Manajer Job TIDAK punya akses modul Kandidat** (ROLES §5.1, STATUS_STATE_MACHINE). Brief yang menyebut "Manajer Job baca/edit" & "bulk pull oleh Manajer Job" **ditolak**.
3. **~~Envelope encryption dokumen identitas~~ — DISUPERSEDE 2026-07-01** → "dokumen identitas" ternyata = koleksi dokumen peserta; kini **link Google Drive privat** (URL input) di tabel `candidate_document`, **TANPA** envelope encryption/AES-256-GCM/KEK/R2. Lihat §6.
4. **~~TTL signed URL R2 bertingkat~~ — DISUPERSEDE 2026-07-01** → Dokumen & sertifikat kini link Google Drive privat (tanpa signed URL). **Foto wajah** tetap upload R2 privat + **signed URL TTL 15 menit**. Video = embed URL. Lihat §6.
5. **Optimistic locking** → **Manual kolom ****`version`** → HTTP 409 (selaras ARCHITECTURE D8 + BR-CON-01/02), bukan paket pihak ketiga.
6. **Cek-kemiripan nama Katakana** → **pg_trgm ****`similarity() >= 0.4`** pada nama latin & katakana apa adanya (sesuai BR-DUP), tanpa ambang adaptif terpisah.
---
## 3. Tabel Verifikasi Teknologi
Diverifikasi live ke sumber resmi pada **2026-06-28/29** (mulai dari Notion page reference).
<table fit-page-width="true" header-row="true">
<tr>
<td>Tech</td>
<td>Versi rekomendasi</td>
<td>Status maint</td>
<td>Caveat proyek</td>
<td>Sumber resmi (akses)</td>
</tr>
<tr>
<td>PostgreSQL + pg_trgm</td>
<td>18.x (extension pg_trgm 1.6)</td>
<td>Aktif (GA 2025, support s/d 2030)</td>
<td>`similarity()` 0–1; operator `%` pakai `pg_trgm.similarity_threshold` (default 0.3) → WAJIB pakai `similarity() >= 0.4` eksplisit. pg_trgm lowercases + abaikan non-alfanumerik; katakana pendek → trigram sedikit.</td>
<td>[postgresql.org/docs/current/pgtrgm.html](http://postgresql.org/docs/current/pgtrgm.html), /gin.html (29-06-2026)</td>
</tr>
<tr>
<td>Laravel</td>
<td>13.x</td>
<td>Aktif (security s/d Mar 2028)</td>
<td>PHP 8.4. Cast `encrypted` = AES-256-CBC + MAC (untuk DEK kecil, bukan stream file).</td>
<td>[laravel.com/docs/13.x](http://laravel.com/docs/13.x) (29-06-2026)</td>
</tr>
<tr>
<td>Flysystem S3 (R2)</td>
<td>league/flysystem-aws-s3-v3 \^3.x</td>
<td>Aktif</td>
<td>Bucket privat + signed URL (hanya **foto wajah**; dokumen peserta via link Google Drive privat); integrity checksum harus `WHEN_REQUIRED`.</td>
<td>[flysystem.thephpleague.com](http://flysystem.thephpleague.com), github flysystem#1845 (29-06-2026)</td>
</tr>
<tr>
<td>aws-sdk-php</td>
<td>\^3.337+</td>
<td>Aktif</td>
<td>Default CRC32 → R2 balas `501 NotImplemented: x-amz-checksum-crc32`. Set `request_checksum_calculation => when_required`, `response_checksum_validation => when_required`, `retain_visibility => false`.</td>
<td>[docs.aws.amazon.com/sdk-for-php/v3](http://docs.aws.amazon.com/sdk-for-php/v3) s3-checksums.html (29-06-2026)</td>
</tr>
<tr>
<td>spatie/laravel-model-states</td>
<td>2.14.1</td>
<td>Aktif (rilis Apr 2026)</td>
<td>Dipakai untuk status approval kandidat (Menunggu Tinjauan/Disetujui/Ditolak/Diterapkan). Butuh PHP 8.4.</td>
<td>[github.com/spatie/laravel-model-states](http://github.com/spatie/laravel-model-states) (29-06-2026)</td>
</tr>
<tr>
<td>optimistic-locking lib</td>
<td>— (manual)</td>
<td>n/a</td>
<td>Diputuskan MANUAL kolom `version` (int) + cek pada UPDATE → 409. Tidak pakai reshadman/laravel-optimistic-locking.</td>
<td>Keputusan #5 (29-06-2026)</td>
</tr>
<tr>
<td>Envelope encryption</td>
<td>— (OpenSSL AES-256-GCM native PHP)</td>
<td>n/a</td>
<td>**DISUPERSEDE 2026-07-01** — dokumen peserta kini link Google Drive privat (URL input); envelope encryption / KEK / AES-256-GCM TIDAK dipakai lagi. R2 hanya untuk foto wajah.</td>
<td>[php.net/manual/openssl](http://php.net/manual/openssl) (29-06-2026)</td>
</tr>
<tr>
<td>Image processing</td>
<td>intervention/image \^3.x (driver GD)</td>
<td>Aktif</td>
<td>Resize foto; GD tetap pilihan sederhana pada VPS 4C/8G.</td>
<td>[image.intervention.io](http://image.intervention.io) (29-06-2026)</td>
</tr>
</table>
---
## 4. Domain model lengkap (PRD §5.2)
> GAP-PRD (rujukan): atribut kandidat ada di **PRD §5.2** (brief misi menyebut "§7 Tabel 6" — koreksi rujukan, bukan perubahan isi).
Prinsip i18n (GLOSSARY final, D9): **store canonical enum, render glyph**. Nilai disimpan sebagai enum kanonik; glyph JP (歳/男/女/...) hanya untuk tampilan.
### 4.1 Agregat `candidate` (Data Card + administratif)
<table fit-page-width="true" header-row="true">
<tr>
<td>Atribut</td>
<td>Tipe / Enum kanonik</td>
<td>Catatan</td>
</tr>
<tr>
<td>`id`</td>
<td>bigint PK</td>
<td>Surrogate internal.</td>
</tr>
<tr>
<td>`nomor_induk`</td>
<td>varchar unik, nullable</td>
<td>`K-YYYY-NNNNN`; null saat DRAFT, terisi saat submit (§7).</td>
</tr>
<tr>
<td>`nama_alphabet`</td>
<td>text</td>
<td>Nama latin (untuk trigram).</td>
</tr>
<tr>
<td>`nama_katakana`</td>
<td>text</td>
<td>Nama JP katakana.</td>
</tr>
<tr>
<td>`kewarganegaraan`</td>
<td>FK lookup `negara` (ISO 3166-1 alpha-2)</td>
<td>Exact-match pada cek-kemiripan.</td>
</tr>
<tr>
<td>`asal_rekrutmen`</td>
<td>FK lookup</td>
<td>Lookup admin-editable.</td>
</tr>
<tr>
<td>`tanggal_lahir`</td>
<td>date</td>
<td>Exact-match pada cek-kemiripan.</td>
</tr>
<tr>
<td>`tempat_lahir`</td>
<td>text / FK geografi</td>
<td>Seed fokus Indonesia, mendukung multi-negara.</td>
</tr>
<tr>
<td>`umur`</td>
<td>computed (derive dari DOB)</td>
<td>Render glyph `歳`. Tidak disimpan redundan.</td>
</tr>
<tr>
<td>`jenis_kelamin`</td>
<td>enum `M`/`F` → 男/女</td>
<td>Backed enum PHP 8.4 + CHECK.</td>
</tr>
<tr>
<td>`status_pernikahan`</td>
<td>enum `MARRIED`/`SINGLE` → 既婚/未婚</td>
<td>Backed enum + CHECK.</td>
</tr>
<tr>
<td>`agama`</td>
<td>FK lookup</td>
<td>Seed menunggu konfirmasi kumiai (GAP lookup).</td>
</tr>
<tr>
<td>`alamat`</td>
<td>text (ID) + text (JP opsional)</td>
<td>Bilingual.</td>
</tr>
<tr>
<td>`email`, `phone`, `line_id`</td>
<td>text</td>
<td>Kontak.</td>
</tr>
<tr>
<td>`status_ketersediaan`</td>
<td>enum `TERSEDIA`/`SEDANG_DIPAKAI`</td>
<td>HANYA boleh diubah via public service Candidates (PRD §7.1 / ARCH D2). Bukan diedit langsung operator.</td>
</tr>
<tr>
<td>`status_approval`</td>
<td>state machine: `DRAFT`/`MENUNGGU_TINJAUAN`(BARU/REVISI)/`DISETUJUI`/`DITOLAK`/`DITERAPKAN`</td>
<td>spatie/laravel-model-states; lihat STATUS_STATE_MACHINE.</td>
</tr>
<tr>
<td>`version`</td>
<td>int, default 0</td>
<td>Optimistic lock.</td>
</tr>
<tr>
<td>`created_by`, `approved_by`</td>
<td>FK users</td>
<td>Jejak Maker–Checker.</td>
</tr>
<tr>
<td>`parent_candidate_id`</td>
<td>FK self, nullable</td>
<td>Baris draft-Revisi menunjuk ke baris utama; saat approve → `DITERAPKAN` (merged).</td>
</tr>
<tr>
<td>`deleted_at`</td>
<td>timestamp (soft delete)</td>
<td>Tombstone operasional.</td>
</tr>
<tr>
<td>`pii_anonymized_at`</td>
<td>timestamp, nullable</td>
<td>Penanda anonimisasi PII (§9.8).</td>
</tr>
</table>
### 4.2 Data Fisik (`candidate_physical`, 1:1)
Tinggi, berat, golongan darah (lookup), ukuran sepatu (lookup), tingkat penglihatan, **dominan tangan** `RIGHT`/`LEFT` → 右/左, dan flag boolean fisik (mis. buta warna, tato, dll) `YES`/`NO` → 有り/無し. Semua boolean fisik = backed enum render glyph.
### 4.3 Entitas anak (semua punya FK `candidate_id`)
<table fit-page-width="true" header-row="true">
<tr>
<td>Tabel</td>
<td>Kardinalitas</td>
<td>Isi utama</td>
</tr>
<tr>
<td>`candidate_education`</td>
<td>maks 5</td>
<td>Riwayat pendidikan.</td>
</tr>
<tr>
<td>`candidate_work`</td>
<td>maks 5</td>
<td>Riwayat pekerjaan.</td>
</tr>
<tr>
<td>`candidate_qualification`</td>
<td>English/Japanese; SSW maks 8; Driving maks 5; Other maks 5</td>
<td>Skill SSW rujuk lookup `skill_ssw` (kolom `bidang_id`). Sertifikat punya flag `is_shareable`.</td>
</tr>
<tr>
<td>`candidate_self_promo`</td>
<td>1:1</td>
<td>Video promosi opsional, skor IQ/MTK.</td>
</tr>
<tr>
<td>`candidate_family`</td>
<td>maks 10</td>
<td>Info keluarga.</td>
</tr>
<tr>
<td>`candidate_family_contact`</td>
<td>1</td>
<td>Kontak keluarga.</td>
</tr>
<tr>
<td>`candidate_immigration`</td>
<td>1:1</td>
<td>Info imigrasi (no. paspor/zairyu, alamat zairyu, jenis visa). Foto Zairyu Card kini **pindah** ke `candidate_document` (jenis `ZAIRYU_CARD`), bukan lagi kolom envelope.</td>
</tr>
<tr>
<td>`candidate_document`</td>
<td>berulang (0..N)</td>
<td>**Koleksi Dokumen Peserta** (KTP/KK/Ijazah/Zairyu Card/dll): `jenis_dokumen` (dropdown) + `url_dokumen` (link **Google Drive privat**). Pola seperti riwayat kerja/pendidikan; menggantikan `candidate_identity_doc` lama.</td>
</tr>
<tr>
<td>`candidate_photo`</td>
<td>1:1</td>
<td>Foto wajah (resize/quality).</td>
</tr>
</table>
---
## 5. CRUD per peran (verifikasi ke ROLES final)
> Diselaraskan dengan Notion page reference (FINAL, D-R1..D-R7). Enforcement hybrid: spatie/laravel-permission (role→permission) + Policy (scope/kepemilikan).
<table fit-page-width="true" header-row="true">
<tr>
<td>Peran</td>
<td>Create</td>
<td>Read</td>
<td>Update</td>
<td>Submit/Review</td>
<td>Delete</td>
</tr>
<tr>
<td>**Operator / Staf Input**</td>
<td>✅ buat draft</td>
<td>✅ miliknya + umum</td>
<td>✅ edit draft & Revisi</td>
<td>✅ Submit (Maker)</td>
<td>❌</td>
</tr>
<tr>
<td>**Approver Kandidat**</td>
<td>❌</td>
<td>✅ antrian tinjauan</td>
<td>❌ (tidak edit konten; minta Revisi)</td>
<td>✅ Approve / Reject (Checker)</td>
<td>❌</td>
</tr>
<tr>
<td>**Asisten Manajer**</td>
<td>❌</td>
<td>✅ kandidat Tersedia</td>
<td>❌</td>
<td>— (lakukan **bulk pull** ke kontainer, §11)</td>
<td>❌</td>
</tr>
<tr>
<td>**Manajer Job**</td>
<td>❌</td>
<td>❌ TANPA akses modul Kandidat</td>
<td>❌</td>
<td>❌</td>
<td>❌</td>
</tr>
<tr>
<td>**Super Admin**</td>
<td>❌ (bukan operasional)</td>
<td>✅</td>
<td>❌</td>
<td>—</td>
<td>❌ soft-delete/restore; ✅ **anonimisasi PII (step-up)**</td>
</tr>
<tr>
<td>**Tamu** (token)</td>
<td>❌</td>
<td>✅ read-only `GuestCandidateView` (data ter-whitelist)</td>
<td>❌</td>
<td>❌</td>
<td>❌</td>
</tr>
</table>
- **Maker–Checker** (BR-APV): Operator tidak boleh menyetujui kandidat buatannya sendiri; SoD di-hard-block (ROLES D-R5).
- **Alur Revisi**: kandidat baru/revision disimpan awal sebagai `DRAFT` dan belum masuk antrian. Revision approved candidate memakai row terpisah (`parent_candidate_id`, `nomor_induk=null`) + snapshot seluruh child collections; maksimum satu revision Draft/menunggu aktif. Submit membuat pending `CANDIDATE_REVISION`; approve mengganti field mutable+child collections main atomik, main tetap `DISETUJUI`, revision→`DITERAPKAN`, NIK/availability/operational history tidak berubah.
- **Tamu** murni read-only (keputusan #1) — tidak ada endpoint upload untuk Tamu di modul ini.
---
## 6. File handling + encryption
### 6.1 Foto kandidat
- Batas **≤ 5MB**, MIME `jpg/png/webp` (validasi magic-byte, bukan ekstensi saja).
- Resize ke target (mis. maks sisi 1024px) + kompres kualitas via intervention/image (GD).
- Simpan di R2 bucket privat; akses via **signed URL TTL 15 menit** (keputusan #4).
- Tidak ter-envelope-encrypt (bukan dokumen identitas), tapi tetap bucket privat + SSE R2.
- Audit `photo_upload`.
### 6.2 Dokumen Peserta (`candidate_document`) — link Google Drive privat
> **Keputusan user 2026-07-01:** "dokumen identitas" ternyata = **koleksi dokumen peserta** (KTP, KK, Ijazah, Zairyu Card, dll), pola berulang seperti riwayat kerja/pendidikan. Tiap dokumen = **jenis** (dropdown lookup `jenis_dokumen`) + **url_dokumen** (link **Google Drive PRIVAT**, "tidak diset public"). Foto Zairyu Card = salah satu baris (jenis `ZAIRYU_CARD`); kolom `zairyu_*` envelope pada `candidate_immigration` **DIHAPUS**.
- File **tidak** di-upload ke aplikasi (URL input); staf menempel link Google Drive. **Tanpa** envelope encryption / R2 / signed URL / KEK.
- `url_dokumen` wajib link Google Drive valid; dokumen = **HIDE Tamu**.
- Saat aplikasi mengungkap/membuka link dokumen sensitif, catat `IDENTITY_DOC_VIEWED`—siapa, kapan, dokumen apa. Event ini **bukan bukti** file benar-benar dibaca di Drive.
- Sertifikat shareable ke Tamu (bila `is_shareable`) memakai Google Drive **"anyone with link"** (opsi paling sederhana, keputusan user).
### 6.3 Konfigurasi R2 / aws-sdk-php (TECH_VERSION_SEED) — **hanya untuk foto wajah**
> Dokumen peserta **tidak** memakai R2/aws-sdk (link Google Drive privat). Config di bawah hanya untuk upload **foto wajah**.
Wajib di disk `r2` (config/filesystems.php) untuk hindari `501 NotImplemented`:
```php
's3' => [
    'driver' => 's3',
    'endpoint' => env('R2_ENDPOINT'),
    'bucket' => env('R2_BUCKET'),
    'use_path_style_endpoint' => true,
    // Caveat R2 + aws-sdk-php >= 3.337:
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
    'retain_visibility' => false,
],
```
---
## 7. Nomor Induk Kandidat (`K-YYYY-NNNNN`)
> Selaras BR-NIK-01..07. Rujukan kontrak antar-modul = **PRD §9.7**.
- Format: `K-` + tahun 4 digit + `-` + 5 digit zero-padded berurut.
- **Di-assign saat SUBMIT pertama** (bukan saat draft). Draft `nomor_induk = NULL`.
- **`YYYY`**** = tahun zona JST (Asia/Tokyo)**, bukan WIB.
- **PostgreSQL SEQUENCE per-tahun**: satu sequence per tahun (mis. `candidate_nik_seq_2026`), atau tabel counter `nik_counter(year, last_value)` dengan `INSERT ... ON CONFLICT (year) DO UPDATE SET last_value = last_value + 1 RETURNING last_value` dalam transaksi.
- **Anti-race**: increment counter bersifat atomik di level DB; bila pakai SEQUENCE, `nextval()` aman-konkuren (boleh ada **gap** bila transaksi rollback — diizinkan BR-NIK).
- **Unique constraint** pada `nomor_induk` sebagai jaring pengaman terakhir; nomor permanen & tidak dipakai ulang.
- Seluruh operasi assign + simpan dilakukan **dalam satu transaksi** dengan operasi submit.
```sql
-- Pendekatan tabel counter (lebih mudah RESTART per tahun & auditable):
INSERT INTO nik_counter (year, last_value) VALUES (:y, 1)
ON CONFLICT (year) DO UPDATE SET last_value = nik_counter.last_value + 1
RETURNING last_value;
-- format: 'K-' || :y || '-' || lpad(last_value::text, 5, '0')
```
---
## 8. Cek-kemiripan duplikat (pg_trgm)
> Selaras BR-DUP + **PRD §9.4**. Keputusan #6: pg_trgm 0.4 latin & katakana apa adanya.
- **Kriteria match** (soft warning, TIDAK memblokir — BR-DUP):
	- `similarity(nama_ternormalisasi, kandidat) >= 0.4` pada **nama latin ATAU katakana** (match bila salah satu ≥ 0.4), **DAN**
	- `tanggal_lahir` **sama persis**, **DAN**
	- `kewarganegaraan` **sama persis**.
- **Mencakup record DRAFT**; **mengecualikan** kandidat ter-anonimisasi (`pii_anonymized_at IS NOT NULL`).
- **WAJIB pakai ****`similarity() >= 0.4`**** eksplisit**, BUKAN operator `%` (yang bergantung `pg_trgm.similarity_threshold` default 0.3) → deterministik.
- **GIN index** `gin_trgm_ops` pada kolom nama ternormalisasi untuk akselerasi.
- Normalisasi: lowercase + trim (pg_trgm sendiri lowercase & abaikan non-alfanumerik). Caveat katakana: nama pendek → trigram sedikit → similarity bisa rendah; diterima apa adanya per keputusan #6.
- Perilaku UI: tampilkan kandidat mirip + minta **konfirmasi eksplisit**; override dicatat audit `similarity_match_shown`.
```sql
CREATE INDEX idx_candidate_nama_alpha_trgm
  ON candidate USING gin (lower(nama_alphabet) gin_trgm_ops);
CREATE INDEX idx_candidate_nama_kana_trgm
  ON candidate USING gin (nama_katakana gin_trgm_ops);

SELECT id, nomor_induk, nama_alphabet, nama_katakana
FROM candidate
WHERE tanggal_lahir = :dob
  AND kewarganegaraan = :nat
  AND pii_anonymized_at IS NULL
  AND (similarity(lower(nama_alphabet), lower(:nama_alpha)) >= 0.4
       OR similarity(nama_katakana, :nama_kana) >= 0.4);
```
---
## 9. Persistence high-level
- **Modular monolith** (internachi/modular); tabel kandidat dimiliki modul Candidates. **Larangan FK & akses tabel lintas-modul** (ARCH D2) — modul lain akses via public service.
- Tabel utama: `candidate` + tabel anak ternormalisasi, `candidate_document`, `candidate_photo`, `nik_counter`. **Tidak ada** tabel `candidate_participation`; riwayat adalah service/read-model union `participation` + `placement_participants`.
- **CHECK constraint** untuk enum kanonik (jenis_kelamin, status_pernikahan, dominan_tangan, boolean fisik) + state machine status_approval.
- Detail DDL final → **DATABASE_**[**SCHEMA.md**](../technical/DATABASE_SCHEMA.md) (K3). Modul ini menetapkan kontrak; skema fisik dirinci di sana.
### 9.1 Lifecycle data + PII retention (PRD §9.8 / §7.9 / §11; BR-PII)
- **Soft-delete/restore Kandidat tidak diekspos pada MVP:** tidak ada route, tombol, atau Policy aktif. `deleted_at` dan event soft-delete/restore hanya reserved/deferred.
- **Pisahkan kolom operasional (permanen)** — mis. `nomor_induk`, jejak partisipasi, audit — dari **kolom PII** (nama, alamat, kontak, dokumen identitas, foto).
- `deleted_at` hanya reserved/deferred; tidak ada use-case soft-delete/restore aktif di MVP.
- **Retensi PII (dikunci PRD v0.3.3)**: simpan PII **5 tahun** sejak keterikatan terakhir, lalu **anonimisasi** (soft tombstone) dalam tenggang **≤ 1 tahun**. Rincian jadwal → DATA_RETENTION_AND_PRIVACY (pending DPO).
- **Anonimisasi**: sebelum tombstone, wajib revalidasi dalam transaksi bahwa availability `Tersedia`, tidak ada participation aktif, placement `Bekerja`, pending request terbuka, atau revision Draft/menunggu aktif. Lalu kosongkan/scramble PII + hapus foto R2 + putus link dokumen Drive, set `pii_anonymized_at`. Kandidat anonim tidak bisa diedit/dipulihkan. Audit `CANDIDATE_ANONYMIZED`.
---
## 10. Audit events
> Audit = service custom ke tabel `audit_log` JSONB, immutable, Super Admin read-only (ARCH D4, PRD Lampiran A).
**Event domain Kandidat:** `CANDIDATE_SUBMITTED`, `CANDIDATE_APPROVED`, `CANDIDATE_REJECTED`, `CANDIDATE_REVISION_SUBMITTED`, `CANDIDATE_UPDATED`, `CANDIDATE_ANONYMIZED`, `CANDIDATE_CREATED` (draft); `CANDIDATE_SOFT_DELETED`/`CANDIDATE_RESTORED` reserved/deferred; `IDENTITY_DOC_VIEWED` (akses dokumen identitas — **WAJIB**), `CANDIDATE_PHOTO_UPLOADED`, `SIMILARITY_MATCH_SHOWN`. Keenam event terakhir ditambahkan via adendum v0.3.5 (disetujui user 2026-06-29, tercatat di DECISIONS_LOG).
Setiap entri mencatat: actor (user/role), waktu, target (`candidate_id`/`nomor_induk`), dan `detail` JSONB (mis. dokumen yang diakses pada `IDENTITY_DOC_VIEWED`).
---
## 11. Konkurensi
> ARCH D8 + BR-CON-01/02/03.
- **Optimistic locking (keputusan #5)**: kolom `version` int. Setiap UPDATE menyertakan `WHERE id=:id AND version=:v`; bila 0 baris terpengaruh → konflik → **HTTP 409**.
- Partial unique database menegakkan maksimum satu revision `Draft`/menunggu aktif per main candidate. Naikkan `version` tiap update sukses.
- **Pessimistic ****`SELECT ... FOR UPDATE`**** (BR-CON-03)** khusus **bulk pull** kandidat ke kontainer oleh **Asisten Manajer** (keputusan #2): kunci baris kandidat yang ditarik dalam transaksi untuk cegah double-pull/inkonistensi ketersediaan. Verifikasi `pending_request`/ketersediaan di dalam transaksi (PRD §7.10).
- Perubahan `status_ketersediaan` hanya via public service Candidates (cegah race lintas-modul).
---
## 12. Step-up re-auth
> Selaras ROLES D-R3/§8.2 + MODULE_AUTH (A-1/A-6) + PRD §4.6/Lampiran D.
- Pemicu step-up di domain Kandidat = **anonimisasi PII** (Super Admin), bukan operasi CRUD biasa.
- **Catatan koreksi vs brief**: brief menyebut "Step-up re-auth: aksi Hapus". Sesuai final, **soft delete biasa BUKAN pemicu step-up**; yang memicu step-up ke-5 adalah **anonimisasi PII (§7.9)** — lihat GAP §18. Approve/Reject kandidat = approval rutin, TIDAK memicu step-up.
- Mekanik step-up (password + TOTP, TTL 5 menit, per-aksi) di MODULE_AUTH.
---
## 13. Riwayat Partisipasi (read-model lintas modul)
- Tidak ada tabel generik `candidate_participation`.
- Riwayat kandidat dibaca melalui service/read-model union atas `participation` (Wawancara) dan `placement_participants` (Penempatan).
- Force-Majeur hidup di `placement_participants` dengan `source_participation_id=NULL`; orchestration milik MODULE_PLACEMENT.
- Candidates hanya menyediakan availability/read services; tidak menulis tabel Jobs/Placement.
> GAP-PRD (rujukan): Force-Majeur ada di **PRD §6.4 Sub-flow 2b** (brief menyebut "§9.3" — koreksi rujukan).
---
## 14. Integrasi modul lain
- **MODULE_LOOKUP_DATA**: semua FK lookup (negara, bahasa, skill_ssw, agama, golongan darah, dst) + helper `lookup_label()`/glyph.
- **MODULE_PLACEMENT / Jobs (Wawancara)**: bulk pull (Asisten Manajer), riwayat partisipasi, Force-Majeur, efek `Terkirim`.
- **MODULE_AUTH**: step-up, 2FA, session.
- **MODULE_GUEST_ACCESS**: `GuestCandidateView` read-only (whitelist field; sertifikat `is_shareable=true` saja). Tamu **tidak** upload.
- **Audit service** (ARCH D4): seluruh event §10.
- Semua komunikasi via **public service/facade sinkron** (ARCH D2), tanpa FK lintas-modul.
---
## 15. Invariants
1. `nomor_induk` NULL untuk kandidat/revision `Draft`; main candidate mendapat NIK hanya saat submit pertama, lalu immutable & unik. Revision selalu memakai NIK main pada tampilan dan tidak menyalinnya.
2. Kandidat `DISETUJUI` wajib punya `approved_by` ≠ `created_by` (Maker–Checker).
3. Dokumen peserta (`candidate_document`) disimpan sebagai **link Google Drive privat** ("tidak diset public"); aplikasi tidak menyimpan berkas dokumen at-rest.
4. Setiap akses dokumen sensitif (mis. Zairyu Card, KTP) menghasilkan tepat satu entri audit `IDENTITY_DOC_VIEWED`.
5. `status_ketersediaan` hanya berubah via public service Candidates.
6. Update apa pun yang gagal cek `version` → 409, tanpa partial write.
7. Kandidat ter-anonimisasi: sebelum eksekusi tidak boleh memiliki proses/pending/revision aktif; setelahnya PII kosong/scrambled, objek R2 terhapus, tidak dapat diedit/dipulihkan.
8. Soft-delete/restore Kandidat reserved/deferred dan tidak memiliki route/button/Policy aktif di MVP.
9. Tamu tidak pernah punya jalur tulis ke agregat kandidat.
10. Cek-kemiripan tidak pernah memblokir submit (soft warning + konfirmasi).
---
## 16. Edge cases
- **Submit ganda cepat (double-submit)**: idempotensi via transaksi + unique constraint `nomor_induk`; assign NIK hanya sekali.
- **Rollback setelah ****`nextval()`**: gap nomor diizinkan (BR-NIK).
- **Pergantian tahun JST saat submit tengah malam**: tahun diambil saat commit, zona Asia/Tokyo.
- **Katakana sangat pendek** (1–2 char): trigram minim → similarity rendah; diterima (keputusan #6), tetap cek DOB+nationality.
- **Foto korup / MIME palsu**: validasi magic-byte foto → tolak + audit.
- **URL dokumen tidak valid**: `url_dokumen` wajib link Google Drive; format URL divalidasi saat simpan.
- **R2 ****`501 checksum`**: dicegah oleh config `when_required` (§6.3).
- **Konflik bulk pull vs edit kandidat**: `FOR UPDATE` mengunci; editor lain dapat 409.
- **Approve baris Revisi yang induknya berubah**: cek `version` induk; bila berubah → 409, minta refresh.
---
## 17. Test plan
**Unit / Feature (PHPUnit):**
- NIK: format, per-tahun (JST), gap saat rollback, unik, hanya assign saat submit.
- Cek-kemiripan: ambang 0.4 latin & katakana, sertakan draft, kecualikan anonim, soft warning (tidak block).
- Optimistic lock: dua update paralel → satu 409.
- Maker–Checker: Operator tak bisa approve miliknya; SoD hard-block.
- Tamu: semua endpoint tulis kandidat → 403.
- Dokumen peserta: `url_dokumen` wajib link Google Drive valid; akses dokumen sensitif → audit `IDENTITY_DOC_VIEWED`.
- File: batas 5MB/10MB, MIME magic-byte, resize foto.
- Audit: setiap aksi (terutama `IDENTITY_DOC_VIEWED`) menghasilkan entri.
- Anonimisasi: kandidat dengan participation/placement/pending/revision aktif ditolak; kandidat eligible mengosongkan PII, menghapus objek R2, tidak bisa edit/restore, dan memerlukan step-up.
- Soft-delete/restore: pastikan seluruh route/button/permission tidak tersedia pada MVP.
- Revision: maksimum satu aktif; approve mengganti field mutable+seluruh child collections atomik tanpa mengubah NIK/availability/history.
**Integration:**
- Bulk pull `FOR UPDATE` cegah double-pull (uji konkuren).
- Force-Majeur menulis partisipasi `ref_riwayat_partisipasi=NULL` + audit dua lapis, tanpa step-up.
- R2 (foto wajah): unggah/ambil signed URL TTL 15 menit, config checksum `when_required` (no 501); signed URL kedaluwarsa setelah TTL.
**Security:**
- Dokumen peserta = link Google Drive privat (tidak public); akses tanpa hak → 403 + audit.
- Step-up wajib untuk anonimisasi.
---
## 18. GAP PRD & pertanyaan terbuka
1. **Koreksi rujukan §PRD** (bukan perubahan isi): atribut kandidat = **§5.2** (bukan "§7 Tabel 6"); Force-Majeur = **§6.4 Sub-flow 2b** (bukan §9.3); envelope encryption = **§9.1/§9.8** & canonical enum+glyph = **§9.4** (brief menyebut §9.6 = Tech Stack); kontrak antar-modul/NIK = **§9.7**; retensi PII = **§7.9/§11**.
2. **Konflik brief vs final (di-resolve ke FINAL, keputusan #1 & #2)**: Tamu read-only tanpa upload; Manajer Job tanpa akses Kandidat; bulk pull oleh Asisten Manajer. **Tidak perlu SUPERSEDES** (menyelaraskan ke dependency final, bukan menimpa).
3. **GAP-AUDIT — SUDAH DITUTUP**: keenam event (`CANDIDATE_CREATED`, `CANDIDATE_SOFT_DELETED`, `CANDIDATE_RESTORED`, `IDENTITY_DOC_VIEWED`, `CANDIDATE_PHOTO_UPLOADED`, `SIMILARITY_MATCH_SHOWN`) **telah ditambahkan ke PRD Lampiran A v0.3.5** (disetujui user 2026-06-29; tercatat di DECISIONS_LOG). Non-breaking.
4. **GAP-STEPUP**: brief minta step-up pada "Hapus"; final hanya step-up pada **anonimisasi PII (§7.9)**. Dikonfirmasi mengikuti final.
5. **GAP-LOOKUP**: seed beberapa lookup (agama, golongan darah, ukuran sepatu, asal rekrutmen, bidang diminati) menunggu konfirmasi kumiai (lihat MODULE_LOOKUP_DATA).
6. **Rincian jadwal retensi PII**: menunggu konfirmasi DPO (DATA_RETENTION_AND_PRIVACY).
