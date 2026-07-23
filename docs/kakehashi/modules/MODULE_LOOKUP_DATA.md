---
title: "MODULE_LOOKUP_DATA"
status: "FINAL"
source_notion_title: "MODULE_LOOKUP_DATA"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_LOOKUP_DATA

> [!NOTE]
> **MODULE_LOOKUP_**[**DATA.md**](MODULE_LOOKUP_DATA.md)** — Kakehashi.** Buku aturan kanonik master data lookup (referensi/dropdown) yang dipakai modul Kandidat, Wawancara (Job), dan Penempatan, plus aturan rendering bilingual ID/JP + canonical enum (歳, 男/女, 既婚/未婚, 右/左, 有り/無し, 年月日). **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.10.** Jika konflik, PRD berlaku. Status state machine → STATUS_STATE_MACHINE; siapa boleh CRUD → ROLES_AND_PERMISSIONS; skema fisik tabel → DATABASE_SCHEMA.
>
## 0. Cakupan & Dependency
- **In-scope:** inventaris seluruh tabel lookup, skema umum, aturan canonical-enum-vs-label bilingual + glyph, strategi seeder, RBAC CRUD lookup, validasi, caching (Redis production), rendering helper, test plan, seed sampel.
- **Out-of-scope (file lain):** transisi status (STATUS_STATE_MACHINE), implementasi auth/step-up (MODULE_AUTH), skema fisik & index detail (DATABASE_SCHEMA), token tamu (MODULE_GUEST_ACCESS).
- **Dependency hulu (final):** PRD v0.3.10 (§5.1–5.4, §7.8, §9.4, Lampiran C), GLOSSARY (canonical enum + glyph), ROLES_AND_PERMISSIONS (§5.5 CRUD lookup + 🔒 step-up), DECISIONS_LOG, ARCHITECTURE (D4 audit, D9 i18n), TECH_VERSION_SEED.
> **Koreksi rujukan §PRD (bukan perubahan isi):** aturan canonical enum + glyph ada di **PRD §9.4 (Bahasa Antarmuka/i18n)**, bukan §9.6 (Tech Stack). Brief misi menyebut §9.6 — diperlakukan sebagai typo rujukan.
---
## 1. Keputusan Teknis (disetujui user 2026-06-29)
<table header-row="true">
<tr>
<td>Kode</td>
<td>Topik</td>
<td>Keputusan</td>
</tr>
<tr>
<td>D-L1</td>
<td>Caching</td>
<td>Driver **`redis`** (production, PRD v0.3.12 / VPS 4C/8G) + cache key **per-tabel per-bahasa**  • `Cache::forget()` on write. Cache tags Redis boleh dipakai; pola key eksplisit tetap aman sebagai fallback.</td>
</tr>
<tr>
<td>D-L2</td>
<td>Format `code` kanonik</td>
<td>**Hybrid ISO**: `negara` = ISO 3166-1 alpha-2; `bahasa` = ISO 639-1; tabel lain = `SCREAMING_SNAKE_CASE`.</td>
</tr>
<tr>
<td>D-L3</td>
<td>Enum kanonik fixed vs lookup table</td>
<td>**Hybrid**: nilai fixed ber-glyph (gender, marital, handedness, boolean fisik, jenis wawancara) = **PHP 8.4 backed enum** (hardcode); daftar terbuka admin-editable = **DB lookup table**.</td>
</tr>
<tr>
<td>D-L4</td>
<td>Skill SSW</td>
<td>**Satu tabel** `skill_ssw`  • kolom `bidang_id` (terkategori per bidang), bukan tabel per-bidang.</td>
</tr>
<tr>
<td>D-L5</td>
<td>Seed geografi</td>
<td>Provinsi Indonesia lengkap (38) di-seed; kota/kabupaten & kecamatan menyusul (sampel saja di MVP awal).</td>
</tr>
<tr>
<td>D-L6</td>
<td>CRUD lookup</td>
<td>**Mengikuti ROLES_AND_PERMISSIONS §5.5** (tidak diubah): Super Admin CRUD + 🔒 step-up; Staf Input & Asisten Manajer hanya *request*.</td>
</tr>
</table>
## 2. Tabel Verifikasi Teknologi (live 2026-06-29)
<table header-row="true">
<tr>
<td>Tech</td>
<td>Versi rekomen</td>
<td>Status maint</td>
<td>Caveat proyek</td>
<td>Sumber resmi (akses 2026-06-29)</td>
</tr>
<tr>
<td>Laravel</td>
<td>13.x (pin `^13`)</td>
<td>✅ Aktif (security s/d Mar 2028)</td>
<td>Lookup via Eloquent + Laravel localization (`lang/id`, `lang/ja`)</td>
<td>[laravel.com/docs/13.x](http://laravel.com/docs/13.x)</td>
</tr>
<tr>
<td>PHP</td>
<td>8.4</td>
<td>✅ Aktif (s/d Des 2028)</td>
<td>Backed enum string utk enum kanonik fixed</td>
<td>[php.net/manual/en/language.enumerations.backed.php](http://php.net/manual/en/language.enumerations.backed.php)</td>
</tr>
<tr>
<td>Laravel cache driver</td>
<td>**`redis`** (production)</td>
<td>✅ Aktif (2026-07-13)</td>
<td>Redis co-located. Pola key-per-tabel + `forget()` on write tetap. Cache tags Redis opsional.</td>
<td>[laravel.com/docs/13.x/cache](http://laravel.com/docs/13.x/cache)</td>
</tr>
<tr>
<td>Filament / Livewire</td>
<td>Filament 5.x (≥5.3.5) · Livewire 4.x</td>
<td>✅ Aktif</td>
<td>Bilingual via Laravel localization; `Select::options()` di-render terlokalisasi</td>
<td>[filamentphp.com/docs](http://filamentphp.com/docs) · [livewire.laravel.com/docs/4.x](http://livewire.laravel.com/docs/4.x)</td>
</tr>
<tr>
<td>spatie/laravel-model-states</td>
<td>2.14.x</td>
<td>✅ Aktif</td>
<td>Khusus status **state machine** (hardcode), BUKAN lookup label</td>
<td>[github.com/spatie/laravel-model-states](http://github.com/spatie/laravel-model-states)</td>
</tr>
</table>
> Tidak ada perubahan versi mayor dari TECH_VERSION_SEED (2026-06-28).
---
## 3. Dua Kelas Data Referensi
PRD §7.8 + GLOSSARY ("store canonical enum, render glyph") membedakan dua kelas tegas:
### 3.1 🔒 Kelas 1 — Enum Kanonik FIXED (hardcode, BUKAN lookup CRUD)
Disimpan sebagai **PHP 8.4 backed enum** + CHECK constraint DB. Tidak bisa diubah Super Admin lewat dashboard. Di-render bilingual + glyph.
<table header-row="true">
<tr>
<td>Field</td>
<td>Enum kanonik</td>
<td>Render JP</td>
<td>Render ID</td>
<td>§PRD</td>
</tr>
<tr>
<td>Jenis Kelamin</td>
<td>`M` / `F`</td>
<td>男 / 女</td>
<td>Laki-laki / Perempuan</td>
<td>§5.2</td>
</tr>
<tr>
<td>Status Pernikahan</td>
<td>`MARRIED` / `SINGLE`</td>
<td>既婚 / 未婚</td>
<td>Menikah / Lajang</td>
<td>§5.2</td>
</tr>
<tr>
<td>Dominan Tangan</td>
<td>`RIGHT` / `LEFT`</td>
<td>右 / 左</td>
<td>Kanan / Kiri</td>
<td>§5.2</td>
</tr>
<tr>
<td>Boolean fisik (Buta Warna, Merokok, Minum Sake, Pembatasan Makanan, Riwayat Penyakit, Riwayat Operasi)</td>
<td>`YES` / `NO`</td>
<td>有り / 無し</td>
<td>Ya / Tidak</td>
<td>§5.2</td>
</tr>
<tr>
<td>Jenis Wawancara</td>
<td>`OFFLINE` / `ONLINE`</td>
<td>オフライン / オンライン</td>
<td>Offline / Online</td>
<td>§5.3</td>
</tr>
<tr>
<td>Satuan umur (render)</td>
<td>(angka)</td>
<td>歳</td>
<td>tahun</td>
<td>§5.2</td>
</tr>
</table>
> **Catatan GLOSSARY:** brief misi menulis contoh `MALE`/`FEMALE`, tetapi GLOSSARY (final) mengunci enum kanonik **`M`****/****`F`**. Kita ikut GLOSSARY. `歳` (bukan `才`), okurigana `有り/無し` dipertahankan.
> Status state machine (status_wawancara, status_penempatan, status kontainer, approval kandidat, link tamu, role) = hardcode, **milik STATUS_STATE_MACHINE** — tidak diulang di sini.
### 3.2 📋 Kelas 2 — Lookup Tables (admin-editable, bilingual)
Nilai dropdown label deskriptif yang boleh ditambah/dinonaktifkan Super Admin. Skema bilingual `code` / `label_id` / `label_ja`.
---
## 4. Inventaris Tabel Lookup (Kelas 2)
<table header-row="true">
<tr>
<td>#</td>
<td>Tabel</td>
<td>Dipakai di</td>
<td>Kolom tambahan</td>
<td>`code`</td>
</tr>
<tr>
<td>1</td>
<td>`negara`</td>
<td>Kewarganegaraan, master perusahaan (negara)</td>
<td>`region`, `dial_code`</td>
<td>ISO 3166-1 alpha-2</td>
</tr>
<tr>
<td>2</td>
<td>`bahasa`</td>
<td>(referensi internal i18n/kualifikasi)</td>
<td>—</td>
<td>ISO 639-1</td>
</tr>
<tr>
<td>3</td>
<td>`provinsi`</td>
<td>Alamat (provinsi)</td>
<td>`negara_code` (FK)</td>
<td>SNAKE</td>
</tr>
<tr>
<td>4</td>
<td>`kota_kabupaten`</td>
<td>Tempat lahir (kota) + Alamat (kota/kabupaten)</td>
<td>`provinsi_id` (FK)</td>
<td>SNAKE</td>
</tr>
<tr>
<td>5</td>
<td>`kecamatan`</td>
<td>Alamat (kecamatan)</td>
<td>`kota_kabupaten_id` (FK)</td>
<td>SNAKE</td>
</tr>
<tr>
<td>6</td>
<td>`agama`</td>
<td>Kandidat</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>7</td>
<td>`golongan_darah`</td>
<td>Data fisik</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>8</td>
<td>`ukuran_sepatu`</td>
<td>Data fisik</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>9</td>
<td>`tingkat_penglihatan`</td>
<td>Data fisik (mata kiri/kanan)</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>10</td>
<td>`asal_rekrutmen`</td>
<td>Kandidat</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>11</td>
<td>`status_keluarga`</td>
<td>Info keluarga + kontak keluarga</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>12</td>
<td>`tingkat_pendidikan`</td>
<td>Riwayat pendidikan</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>13</td>
<td>`jurusan`</td>
<td>Riwayat pendidikan</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>14</td>
<td>`bidang_pekerjaan`</td>
<td>Riwayat pekerjaan, perusahaan</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>15</td>
<td>`posisi_pekerjaan`</td>
<td>Sub-jenis pekerjaan</td>
<td>`bidang_pekerjaan_id` (FK)</td>
<td>SNAKE</td>
</tr>
<tr>
<td>16</td>
<td>`bidang_industri_perusahaan`</td>
<td>Master perusahaan</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>17</td>
<td>`bidang_diminati`</td>
<td>Promosi diri</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>18</td>
<td>`jenis_kualifikasi_bahasa_inggris`</td>
<td>Kualifikasi</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>19</td>
<td>`jenis_kualifikasi_bahasa_jepang`</td>
<td>Kualifikasi</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>20</td>
<td>`skill_ssw`</td>
<td>Kualifikasi Keahlian Jepang/SSW</td>
<td>`bidang_id`, `is_shareable`</td>
<td>SNAKE</td>
</tr>
<tr>
<td>21</td>
<td>`kualifikasi_mengemudi`</td>
<td>Kualifikasi mengemudi</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>22</td>
<td>`kualifikasi_keahlian_lainnya`</td>
<td>Kualifikasi lain</td>
<td>`is_shareable`</td>
<td>SNAKE</td>
</tr>
<tr>
<td>23</td>
<td>`jenis_visa`</td>
<td>Imigrasi, kontainer wawancara/penempatan</td>
<td>`kategori`</td>
<td>SNAKE</td>
</tr>
<tr>
<td>24</td>
<td>`kategori_force_majeur`</td>
<td>Kontainer Penempatan (alasan force-majeur saat kandidat tanpa `source_participation_id` / dikeluarkan)</td>
<td>—</td>
<td>SNAKE</td>
</tr>
<tr>
<td>25</td>
<td>`jenis_dokumen`</td>
<td>Koleksi Dokumen Peserta (`candidate_document`) — dropdown jenis dokumen (KTP/KK/IJAZAH/ZAIRYU_CARD/dll)</td>
<td>—</td>
<td>SNAKE</td>
</tr>
</table>
> **Master Perusahaan** (`nama_ja` wajib, `nama_romaji`/`nama_id` opsional) = entitas master tersendiri (PRD §5.4), **bukan** tabel lookup generik — di-dokumentasikan di MODULE_PLACEMENT/DATABASE_SCHEMA; di sini hanya dirujuk karena memakai lookup `bidang_industri_perusahaan` & `negara`.
> **`is_shareable`** pada `skill_ssw` & `kualifikasi_keahlian_lainnya` mendukung whitelist Tamu (PRD Lampiran C / `GuestCandidateView`): hanya sertifikat ber-flag shareable yang boleh tampil ke Tamu.
> **`kategori_force_majeur`** (#24) ditambahkan menyusul finalisasi MODULE_PLACEMENT (2026-06-29): kategori alasan force-majeur dipilih bersama free-text (dua-duanya wajib saat `source_participation_id` NULL). CRUD = Super Admin + 🔒 step-up (ikut D-L6 / ROLES §5.5); audit memakai `LOOKUP_*` generik.
> **`jenis_dokumen`** (#25) ditambahkan 2026-07-01 untuk koleksi **Dokumen Peserta** (`candidate_document`): dropdown jenis (KTP/KK/IJAZAH/ZAIRYU_CARD/PASPOR/dll). Tiap dokumen = link **Google Drive privat** (`url_dokumen`), HIDE Tamu. Foto Zairyu Card kini nilai `ZAIRYU_CARD`, menggantikan kolom `zairyu_*` envelope lama.
---
## 5. Skema Umum Tabel Lookup
Setiap tabel Kelas 2 mengikuti skema dasar berikut:
```sql
CREATE TABLE <lookup> (
  id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code         VARCHAR(64) NOT NULL,            -- kanonik, stabil, tidak berubah
  label_id     VARCHAR(255) NOT NULL,           -- Bahasa Indonesia (wajib)
  label_ja     VARCHAR(255) NOT NULL,           -- Bahasa Jepang (wajib)
  sort_order   INTEGER NOT NULL DEFAULT 0,
  is_active    BOOLEAN NOT NULL DEFAULT TRUE,   -- soft-disable; JANGAN hard-delete bila dirujuk
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT <lookup>_code_unique UNIQUE (code),
  CONSTRAINT <lookup>_code_not_empty CHECK (length(trim(code)) > 0)
);
```
**Kolom tambahan per-tabel** (lihat §4): `region`/`dial_code` (negara), FK hierarki (provinsi/kota/kecamatan, posisi_pekerjaan, skill_ssw), `kategori` (jenis_visa), `is_shareable`.
> **Referensi dari tabel induk:** kolom kandidat menyimpan **`code`** lookup (bukan id auto-increment) agar stabil & portabel antar-environment seed. FK opsional ke `code` untuk integritas (atau aplikasi-level bila perlu fleksibilitas). Keputusan FK fisik final → DATABASE_SCHEMA.
---
## 6. Aturan Canonical Enum + Bilingual + Glyph
1. **Simpan kanonik, render terlokalisasi** (PRD §9.4): DB hanya menyimpan `code` (Kelas 2) atau backed-enum value (Kelas 1). **JANGAN simpan glyph Jepang sebagai value.**
2. **Glyph = presentation layer.** 歳 / 男 / 女 / 既婚 / 未婚 / 右 / 左 / 有り / 無し / `YYYY年MM月DD日` hanya muncul saat render `app()->getLocale() === 'ja'`.
3. **Bahasa render:** ID & JP toggle bebas semua role internal; **Tamu = JP saja** (PRD §4.3).
4. **Fallback label:** jika label salah satu bahasa kosong (seharusnya tidak terjadi karena keduanya wajib), helper jatuh ke label bahasa lain, lalu ke `code` mentah (lihat §11).
5. **Kelas 1 ber-CHECK constraint** di DB selain backed enum di aplikasi, untuk pertahanan ganda.
```php
// app/Enums/JenisKelamin.php
enum JenisKelamin: string {
    case M = 'M';
    case F = 'F';
    public function labelJa(): string => match ($this) { self::M => '男', self::F => '女' };
    public function labelId(): string => match ($this) { self::M => 'Laki-laki', self::F => 'Perempuan' };
    public function label(?string $lang = null): string =>
        ($lang ?? app()->getLocale()) === 'ja' ? $this->labelJa() : $this->labelId();
}
```
```php
// Boolean fisik ber-glyph 有り/無し
enum BooleanFisik: string {
    case YES = 'YES';
    case NO  = 'NO';
    public function labelJa(): string => match ($this) { self::YES => '有り', self::NO => '無し' };
    public function labelId(): string => match ($this) { self::YES => 'Ya', self::NO => 'Tidak' };
}
```
---
## 7. Strategi Seeder
- **Bahasa default seed:** ID + JA terisi keduanya (wajib) sejak seed awal.
- **Idempotent:** seeder pakai `updateOrInsert` berbasis `code` agar aman dijalankan ulang (tidak menduplikasi).
- **Sumber daftar dasar:** PRD §5.2/§5.3/§5.4 + glyph GLOSSARY; standar eksternal untuk `negara` (ISO 3166-1) & `bahasa` (ISO 639-1).
- **Geografi (D-L5):** `provinsi` Indonesia lengkap (38); `kota_kabupaten` & `kecamatan` di-seed sampel + dilengkapi via impor data Kemendagri pasca-MVP.
- **`sort_order`** diisi eksplisit agar urutan dropdown deterministik.
```php
// database/seeders/LookupSeeder.php (pola)
foreach ($rows as $i => $r) {
    DB::table('agama')->updateOrInsert(
        ['code' => $r['code']],
        ['label_id' => $r['id'], 'label_ja' => $r['ja'], 'sort_order' => $i, 'is_active' => true, 'updated_at' => now()]
    );
}
```
Sampel seed lengkap (5–10 entri/tabel) → **Lampiran A**.
---
## 8. Admin CRUD Lookup + RBAC + Audit
Mengikuti **ROLES_AND_PERMISSIONS §5.5** (final — tidak diubah file ini):
<table header-row="true">
<tr>
<td>Aksi</td>
<td>Staf Input</td>
<td>Asisten Manajer</td>
<td>Super Admin</td>
</tr>
<tr>
<td>Ajukan request nilai lookup baru</td>
<td>✅</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Ajukan request perusahaan baru</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Buat / nonaktifkan nilai lookup</td>
<td>—</td>
<td>—</td>
<td>✅ 🔒</td>
</tr>
<tr>
<td>Setujui request lookup</td>
<td>—</td>
<td>—</td>
<td>✅ 🔒</td>
</tr>
<tr>
<td>Kelola master perusahaan</td>
<td>—</td>
<td>—</td>
<td>✅ 🔒</td>
</tr>
</table>
- **🔒 step-up re-auth** (password + TOTP ulang) wajib untuk semua mutasi lookup/config — PRD §4.6 butir 4 & Lampiran D butir 4.
- **Mekanisme request (PRD §7.8):** Staf Input/Asisten Manajer mengajukan nilai baru langsung dari form yang sedang diisi → masuk antrian Super Admin → approve/reject.
- **Audit log (PRD Lampiran A):** setiap mutasi mencatat `action_type` domain Lookup/Company:
	- `LOOKUP_CREATED`, `LOOKUP_DEACTIVATED`, `LOOKUP_REQUEST_SUBMITTED`, `LOOKUP_REQUEST_APPROVED`, `LOOKUP_REQUEST_REJECTED`
	- `COMPANY_REQUESTED`, `COMPANY_APPROVED`, `COMPANY_REJECTED`, `COMPANY_DEACTIVATED`
	- Skema `detail` JSONB `LOOKUP_DEACTIVATED` = `{ "lookup_category": "...", "code": "...", "label_id": "...", "label_ja": "..." }`
- **UI admin:** Filament 5 Resource per tabel lookup (atau satu Resource generik ber-parameter kategori), dengan kolom `label_id`, `label_ja`, `code` (read-only setelah dibuat), `sort_order`, toggle `is_active`.
---
## 9. Aturan Validasi
<table header-row="true">
<tr>
<td>Aturan</td>
<td>Detail</td>
</tr>
<tr>
<td>`code` unik & non-empty</td>
<td>UNIQUE + CHECK `length(trim(code)) > 0`; immutable setelah dibuat (UI lock)</td>
</tr>
<tr>
<td>`label_id`  • `label_ja` wajib</td>
<td>`required` keduanya; tidak boleh string kosong</td>
</tr>
<tr>
<td>Soft-disable, bukan hard-delete</td>
<td>Nilai yang sudah pernah dirujuk **hanya** boleh `is_active=false`; hard-delete diblokir bila ada referensi</td>
</tr>
<tr>
<td>Format `code`</td>
<td>`negara`=regex `^[A-Z]{2}$`; `bahasa`=`^[a-z]{2}$`; lainnya `^[A-Z0-9_]+$`</td>
</tr>
<tr>
<td>FK hierarki</td>
<td>`provinsi.negara_code`, `kota_kabupaten.provinsi_id`, `kecamatan.kota_kabupaten_id`, `posisi_pekerjaan.bidang_pekerjaan_id`, `skill_ssw.bidang_id` wajib valid & aktif</td>
</tr>
<tr>
<td>`is_shareable`</td>
<td>hanya pada tabel sertifikat skill; default `false` (aman utk Tamu)</td>
</tr>
</table>
---
## 10. Strategi Caching (D-L1 — Redis production, 2026-07-13)
Production memakai **driver Redis** (co-located). Tetap gunakan pola key eksplisit agar mudah di-debug dan aman sebagai fallback:
- **Key:** `lookup:{table}:{lang}` (mis. `lookup:negara:ja`).
- **TTL:** 24 jam (data jarang berubah) + invalidasi eksplisit.
- **Invalidate on write:** setiap CRUD lookup memanggil `flush($table)` yang `forget()` semua bahasa untuk tabel itu.
- **Dataset kecil:** seluruh tabel lookup muat di memori; cache menyimpan koleksi `is_active=true` ter-sort.
```php
class LookupRepository {
    public function all(string $table, ?string $lang = null): Collection {
        $lang = $lang ?? app()->getLocale();
        return Cache::remember("lookup:{$table}:{$lang}", now()->addDay(), fn () =>
            DB::table($table)->where('is_active', true)->orderBy('sort_order')->get()
        );
    }
    public function options(string $table, ?string $lang = null): array {
        $lang = $lang ?? app()->getLocale();
        $col  = $lang === 'ja' ? 'label_ja' : 'label_id';
        return $this->all($table, $lang)->pluck($col, 'code')->toArray();
    }
    public function flush(string $table): void {
        foreach (['id', 'ja'] as $lang) Cache::forget("lookup:{$table}:{$lang}");
    }
}
```
> **Caveat queue/atomicity (ARCHITECTURE D5, 2026-07-13):** Redis boleh untuk cache/queue/lock bantu; anti-duplikasi bisnis tetap via unique constraint DB + transaksi. Cache hanya optimisasi baca, bukan sumber kebenaran.
---
## 11. Rendering Helper (Bilingual + Fallback)
```php
// app/Support/lookup_helpers.php
function lookup_label(string $table, ?string $code, ?string $lang = null): string {
    if (blank($code)) return '';
    $lang = $lang ?? app()->getLocale();
    $row  = app(LookupRepository::class)->all($table, $lang)->firstWhere('code', $code);
    if (! $row) return $code;                               // fallback: code mentah
    $label = $lang === 'ja' ? $row->label_ja : $row->label_id;
    return filled($label) ? $label : ($row->label_id ?: $row->label_ja ?: $code); // fallback berjenjang
}
```
```php
// Blade directive — AppServiceProvider::boot()
Blade::directive('lookup', fn ($expr) => "<?php echo e(lookup_label($expr)); ?>");
// Pemakaian:  @lookup('negara', $kandidat->kewarganegaraan_code)
```
```php
// Filament 5 / Livewire 4 select bilingual (label mengikuti locale aktif)
use Filament\Forms\Components\Select;
Select::make('kewarganegaraan_code')
    ->label(__('kandidat.kewarganegaraan'))
    ->options(fn () => app(LookupRepository::class)->options('negara'))
    ->searchable()
    ->required();
```
- **Enum Kelas 1** pakai method enum (`JenisKelamin::from($v)->label()`), bukan `lookup_label()`.
- **Tamu = JP:** komponen read-model `GuestCandidateView` memanggil helper dengan `lang='ja'` eksplisit, tidak bergantung locale sesi.
---
## 12. Test Plan
<table header-row="true">
<tr>
<td>#</td>
<td>Uji</td>
<td>Ekspektasi</td>
</tr>
<tr>
<td>T1</td>
<td>Seed idempotent</td>
<td>Jalankan seeder 2x → tidak ada duplikat `code`</td>
</tr>
<tr>
<td>T2</td>
<td>Unique & non-empty `code`</td>
<td>Insert `code` duplikat/kosong → ditolak constraint</td>
</tr>
<tr>
<td>T3</td>
<td>Label wajib</td>
<td>Simpan tanpa `label_id` atau `label_ja` → validasi gagal</td>
</tr>
<tr>
<td>T4</td>
<td>Soft-disable</td>
<td>Nonaktifkan nilai yang dirujuk → row tetap ada, hilang dari dropdown aktif; hard-delete diblokir</td>
</tr>
<tr>
<td>T5</td>
<td>Render bilingual</td>
<td>`lookup_label('negara','JP','ja')`=日本 ; `'id'`=Jepang</td>
</tr>
<tr>
<td>T6</td>
<td>Fallback</td>
<td>`code` tak dikenal → kembalikan code mentah; label kosong → fallback berjenjang</td>
</tr>
<tr>
<td>T7</td>
<td>Glyph enum Kelas 1</td>
<td>`JenisKelamin::M->label('ja')`=男 ; boolean fisik=有り/無し</td>
</tr>
<tr>
<td>T8</td>
<td>Cache invalidation</td>
<td>Update lookup → `forget` dipanggil → query berikutnya ambil data baru</td>
</tr>
<tr>
<td>T9</td>
<td>RBAC + step-up</td>
<td>Non-Super-Admin CRUD lookup → 403; Super Admin tanpa step-up valid → ditolak</td>
</tr>
<tr>
<td>T10</td>
<td>Audit</td>
<td>CRUD lookup mencatat `LOOKUP_*` dengan `detail` JSONB sesuai Lampiran A</td>
</tr>
<tr>
<td>T11</td>
<td>FK hierarki</td>
<td>`kota_kabupaten` dgn `provinsi_id` nonaktif/invalid → ditolak</td>
</tr>
<tr>
<td>T12</td>
<td>Tamu shareable</td>
<td>Hanya sertifikat `is_shareable=true` muncul di `GuestCandidateView`</td>
</tr>
</table>
---
## 13. GAP PRD & Pertanyaan Terbuka
*(Q1 & Q2 telah diputuskan user 2026-06-29 — lihat di bawah.)*
- **\[GAP-L1 — minor rujukan\]** PRD §9.4 (bukan §9.6) memuat aturan canonical enum + glyph. Brief misi salah rujuk. Tidak mengubah kebenaran inti.
- **\[GAP-L2\]** PRD tidak merinci daftar nilai `golongan_darah`, `ukuran_sepatu`, `tingkat_penglihatan`, `agama`, `status_keluarga` — di-seed berdasarkan praktik umum (lihat Lampiran A); perlu konfirmasi konten final dari kumiai.
- **\[GAP-L3\]** `jenis_visa`/status tinggal Jepang berubah seiring kebijakan imigrasi (SSW, Ginō Jisshū, dll) — di-seed sebagai lookup editable; daftar awal perlu validasi pihak kumiai/legal.
- **\[GAP-L4\]** PRD menyebut "Asal Rekrutmen" & "Bidang Diminati" sebagai dropdown master tanpa daftar nilai — di-seed minimal; konten final menunggu user.
- **\[GAP-L5\]** `kategori_force_majeur` (#24) ditambahkan menyusul keputusan MODULE_PLACEMENT (2026-06-29); daftar kategori awal (SAKIT_BERAT / MENINGGAL / MASALAH_KELUARGA / BENCANA_ALAM / MASALAH_HUKUM_IMIGRASI / LAINNYA) di-seed minimal & perlu validasi kumiai. PRD belum memuat enum ini → kandidat addendum PRD.
- **\[GAP-L6 — DIBUKA KEMBALI & RESOLVED 2026-07-01\]** Lookup dokumen **di-add kembali** sebagai **`jenis_dokumen`** (generik: KTP/KK/IJAZAH/ZAIRYU_CARD/PASPOR/dll), inventaris **24→25**. Koreksi arahan atasan: "dokumen identitas" ternyata = **koleksi dokumen peserta** (bukan hanya Zairyu). DATABASE_SCHEMA menambah tabel berulang `candidate_document` (`jenis_dokumen_id` + `url_dokumen` = link Google Drive privat). *(Riwayat: 2026-06-30 **`jenis_dokumen_identitas`** sempat dihapus 25→24 mengikuti penghapusan **`candidate_identity_doc`**; kini diganti nama generik & di-add kembali.)* Disetujui user 2026-07-01.
- **\[Q1\]** **DIPUTUSKAN (user 2026-06-29):** Ya — `bahasa` (ISO 639-1) tetap tabel mandiri untuk mendukung pencatatan kualifikasi bahasa kandidat (mis. level Jepang/Inggris), bukan sekadar string i18n antarmuka.
- **\[Q2\]** **DIPUTUSKAN (user 2026-06-29; DIREVISI 2026-06-30 — Opsi B):** Tempat lahir kini = dropdown **Kota/Kabupaten saja** (bukan lagi hierarki Kecamatan/Kota/Provinsi); Provinsi/Kota-Kabupaten/Kecamatan menjadi komponen **Alamat** terstruktur. Dukungan multi-negara tetap terakomodasi via rantai `kota_kabupaten → provinsi.negara_code`; seed awal fokus Indonesia, negara/wilayah lain ditambahkan saat dibutuhkan. Lihat PRD v0.3.8 + DATABASE_SCHEMA GAP-DB8.
---
## Lampiran A — Sampel Seed (5–10 entri/tabel)
### A.1 `negara` (ISO 3166-1 alpha-2)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
<td>region</td>
<td>dial_code</td>
</tr>
<tr>
<td>ID</td>
<td>Indonesia</td>
<td>インドネシア</td>
<td>Asia Tenggara</td>
<td>+62</td>
</tr>
<tr>
<td>JP</td>
<td>Jepang</td>
<td>日本</td>
<td>Asia Timur</td>
<td>+81</td>
</tr>
<tr>
<td>VN</td>
<td>Vietnam</td>
<td>ベトナム</td>
<td>Asia Tenggara</td>
<td>+84</td>
</tr>
<tr>
<td>PH</td>
<td>Filipina</td>
<td>フィリピン</td>
<td>Asia Tenggara</td>
<td>+63</td>
</tr>
<tr>
<td>CN</td>
<td>Tiongkok</td>
<td>中国</td>
<td>Asia Timur</td>
<td>+86</td>
</tr>
<tr>
<td>MM</td>
<td>Myanmar</td>
<td>ミャンマー</td>
<td>Asia Tenggara</td>
<td>+95</td>
</tr>
<tr>
<td>NP</td>
<td>Nepal</td>
<td>ネパール</td>
<td>Asia Selatan</td>
<td>+977</td>
</tr>
</table>
### A.2 `bahasa` (ISO 639-1)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>id</td>
<td>Bahasa Indonesia</td>
<td>インドネシア語</td>
</tr>
<tr>
<td>ja</td>
<td>Bahasa Jepang</td>
<td>日本語</td>
</tr>
<tr>
<td>en</td>
<td>Bahasa Inggris</td>
<td>英語</td>
</tr>
</table>
### A.3 `provinsi` (sampel; 38 lengkap di seeder)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
<td>negara_code</td>
</tr>
<tr>
<td>JABAR</td>
<td>Jawa Barat</td>
<td>西ジャワ州</td>
<td>ID</td>
</tr>
<tr>
<td>JATENG</td>
<td>Jawa Tengah</td>
<td>中部ジャワ州</td>
<td>ID</td>
</tr>
<tr>
<td>JATIM</td>
<td>Jawa Timur</td>
<td>東ジャワ州</td>
<td>ID</td>
</tr>
<tr>
<td>DKI</td>
<td>DKI Jakarta</td>
<td>ジャカルタ首都特別州</td>
<td>ID</td>
</tr>
<tr>
<td>BANTEN</td>
<td>Banten</td>
<td>バンテン州</td>
<td>ID</td>
</tr>
<tr>
<td>DIY</td>
<td>DI Yogyakarta</td>
<td>ジョグジャカルタ特別州</td>
<td>ID</td>
</tr>
</table>
### A.4 `agama`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>ISLAM</td>
<td>Islam</td>
<td>イスラム教</td>
</tr>
<tr>
<td>KRISTEN</td>
<td>Kristen Protestan</td>
<td>キリスト教（プロテスタント）</td>
</tr>
<tr>
<td>KATOLIK</td>
<td>Katolik</td>
<td>カトリック</td>
</tr>
<tr>
<td>HINDU</td>
<td>Hindu</td>
<td>ヒンドゥー教</td>
</tr>
<tr>
<td>BUDDHA</td>
<td>Buddha</td>
<td>仏教</td>
</tr>
<tr>
<td>KONGHUCU</td>
<td>Konghucu</td>
<td>儒教</td>
</tr>
</table>
### A.5 `golongan_darah`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>A</td>
<td>A</td>
<td>A型</td>
</tr>
<tr>
<td>B</td>
<td>B</td>
<td>B型</td>
</tr>
<tr>
<td>O</td>
<td>O</td>
<td>O型</td>
</tr>
<tr>
<td>AB</td>
<td>AB</td>
<td>AB型</td>
</tr>
</table>
### A.6 `tingkat_pendidikan`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>SD</td>
<td>SD</td>
<td>小学校</td>
</tr>
<tr>
<td>SMP</td>
<td>SMP</td>
<td>中学校</td>
</tr>
<tr>
<td>SMA</td>
<td>SMA/SMK</td>
<td>高等学校</td>
</tr>
<tr>
<td>D3</td>
<td>Diploma (D3)</td>
<td>短期大学・専門学校</td>
</tr>
<tr>
<td>S1</td>
<td>Sarjana (S1)</td>
<td>大学（学士）</td>
</tr>
<tr>
<td>S2</td>
<td>Magister (S2)</td>
<td>大学院（修士）</td>
</tr>
</table>
### A.7 `bidang_pekerjaan`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>KAIGO</td>
<td>Perawatan (Kaigo)</td>
<td>介護</td>
</tr>
<tr>
<td>KONSTRUKSI</td>
<td>Konstruksi</td>
<td>建設</td>
</tr>
<tr>
<td>PERTANIAN</td>
<td>Pertanian</td>
<td>農業</td>
</tr>
<tr>
<td>MANUFAKTUR</td>
<td>Manufaktur</td>
<td>製造業</td>
</tr>
<tr>
<td>PERIKANAN</td>
<td>Perikanan</td>
<td>漁業</td>
</tr>
<tr>
<td>FNB</td>
<td>Makanan & Minuman</td>
<td>外食業</td>
</tr>
</table>
### A.8 `skill_ssw` (kolom `bidang_id` → `bidang_pekerjaan`, `is_shareable`)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
<td>bidang</td>
<td>is_shareable</td>
</tr>
<tr>
<td>SSW_KAIGO</td>
<td>SSW Kaigo</td>
<td>特定技能・介護</td>
<td>KAIGO</td>
<td>true</td>
</tr>
<tr>
<td>SSW_KONSTRUKSI</td>
<td>SSW Konstruksi</td>
<td>特定技能・建設</td>
<td>KONSTRUKSI</td>
<td>true</td>
</tr>
<tr>
<td>SSW_PERTANIAN</td>
<td>SSW Pertanian</td>
<td>特定技能・農業</td>
<td>PERTANIAN</td>
<td>true</td>
</tr>
<tr>
<td>SSW_FNB</td>
<td>SSW Makanan & Minuman</td>
<td>特定技能・外食業</td>
<td>FNB</td>
<td>true</td>
</tr>
<tr>
<td>SSW_MANUFAKTUR</td>
<td>SSW Manufaktur</td>
<td>特定技能・製造業</td>
<td>MANUFAKTUR</td>
<td>true</td>
</tr>
</table>
### A.9 `kualifikasi_mengemudi`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>SIM_A</td>
<td>SIM A (Mobil)</td>
<td>普通自動車免許</td>
</tr>
<tr>
<td>SIM_B1</td>
<td>SIM B1</td>
<td>中型自動車免許</td>
</tr>
<tr>
<td>SIM_B2</td>
<td>SIM B2</td>
<td>大型自動車免許</td>
</tr>
<tr>
<td>SIM_C</td>
<td>SIM C (Motor)</td>
<td>普通二輪免許</td>
</tr>
</table>
### A.10 `jenis_visa` (Status Tinggal — kolom `kategori`)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
<td>kategori</td>
</tr>
<tr>
<td>SSW1</td>
<td>SSW Tipe 1</td>
<td>特定技能1号</td>
<td>SSW</td>
</tr>
<tr>
<td>SSW2</td>
<td>SSW Tipe 2</td>
<td>特定技能2号</td>
<td>SSW</td>
</tr>
<tr>
<td>GINOU</td>
<td>Magang (Ginō Jisshū)</td>
<td>技能実習</td>
<td>MAGANG</td>
</tr>
<tr>
<td>RYUGAKU</td>
<td>Pelajar</td>
<td>留学</td>
<td>STUDI</td>
</tr>
<tr>
<td>GIJINKOKU</td>
<td>Engineer/Humaniora/Int'l</td>
<td>技術・人文知識・国際業務</td>
<td>KERJA</td>
</tr>
</table>
### A.11 `jenis_dokumen` (koleksi dokumen peserta — `candidate_document`)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>KTP</td>
<td>KTP</td>
<td>身分証明書（KTP）</td>
</tr>
<tr>
<td>KK</td>
<td>Kartu Keluarga</td>
<td>家族カード（KK）</td>
</tr>
<tr>
<td>IJAZAH</td>
<td>Ijazah</td>
<td>卒業証書</td>
</tr>
<tr>
<td>PASPOR</td>
<td>Paspor</td>
<td>パスポート</td>
</tr>
<tr>
<td>ZAIRYU_CARD</td>
<td>Kartu Zairyu (Foto Zairyu Card)</td>
<td>在留カード</td>
</tr>
<tr>
<td>SKCK</td>
<td>SKCK</td>
<td>無犯罪証明（SKCK）</td>
</tr>
<tr>
<td>LAINNYA</td>
<td>Dokumen Lainnya</td>
<td>その他書類</td>
</tr>
</table>
> `url_dokumen` = link **Google Drive privat** ("tidak diset public"). Dokumen = HIDE Tamu; akses sensitif diaudit `IDENTITY_DOC_VIEWED`.
### A.12 `status_keluarga`
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>AYAH</td>
<td>Ayah</td>
<td>父</td>
</tr>
<tr>
<td>IBU</td>
<td>Ibu</td>
<td>母</td>
</tr>
<tr>
<td>SAUDARA</td>
<td>Saudara Kandung</td>
<td>兄弟姉妹</td>
</tr>
<tr>
<td>PASANGAN</td>
<td>Suami/Istri</td>
<td>配偶者</td>
</tr>
<tr>
<td>ANAK</td>
<td>Anak</td>
<td>子</td>
</tr>
</table>
### A.13 `tingkat_penglihatan` (mata kiri/kanan)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>NORMAL</td>
<td>Normal</td>
<td>正常</td>
</tr>
<tr>
<td>MINUS_RINGAN</td>
<td>Minus Ringan</td>
<td>軽度近視</td>
</tr>
<tr>
<td>MINUS_SEDANG</td>
<td>Minus Sedang</td>
<td>中等度近視</td>
</tr>
<tr>
<td>MINUS_BERAT</td>
<td>Minus Berat</td>
<td>強度近視</td>
</tr>
</table>
### A.14 `kategori_force_majeur` (alasan force-majeur penempatan)
<table header-row="true">
<tr>
<td>code</td>
<td>label_id</td>
<td>label_ja</td>
</tr>
<tr>
<td>SAKIT_BERAT</td>
<td>Sakit Berat / Alasan Kesehatan</td>
<td>重病・健康上の理由</td>
</tr>
<tr>
<td>MENINGGAL</td>
<td>Meninggal Dunia</td>
<td>死亡</td>
</tr>
<tr>
<td>MASALAH_KELUARGA</td>
<td>Keadaan Darurat Keluarga</td>
<td>家族の緊急事情</td>
</tr>
<tr>
<td>BENCANA_ALAM</td>
<td>Bencana Alam</td>
<td>自然災害</td>
</tr>
<tr>
<td>MASALAH_HUKUM_IMIGRASI</td>
<td>Masalah Hukum / Imigrasi</td>
<td>法的・在留資格上の問題</td>
</tr>
<tr>
<td>LAINNYA</td>
<td>Lainnya (wajib free-text)</td>
<td>その他（自由記述必須）</td>
</tr>
</table>
*(Tabel lain — **`kota_kabupaten`**, **`kecamatan`**, **`jurusan`**, **`posisi_pekerjaan`**, **`bidang_industri_perusahaan`**, **`bidang_diminati`**, **`asal_rekrutmen`**, **`ukuran_sepatu`**, **`jenis_kualifikasi_bahasa_inggris/jepang`**, **`kualifikasi_keahlian_lainnya`** — mengikuti pola skema §5; nilai final menunggu konfirmasi kumiai, lihat GAP-L2/L4.)*
---
*Status: FINAL (2026-06-29) — keputusan teknis D-L1..D-L6 + pertanyaan terbuka Q1/Q2 disetujui user. Selaras PRD Kakehashi v0.3.10 + GLOSSARY (final) + ROLES_AND_PERMISSIONS (final) + ARCHITECTURE (final). Catatan non-pemblokir: konten seed beberapa lookup (GAP-L2/L3/L4) menunggu validasi kumiai/legal dan dapat dilengkapi via CRUD admin tanpa mengubah struktur.*
