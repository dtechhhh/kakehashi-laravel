---
title: "MODULE_GUEST_ACCESS"
status: "FINAL"
source_notion_title: "MODULE_GUEST_ACCESS"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_GUEST_ACCESS

> [!NOTE]
> **Status:** FINAL · **Kelompok:** K2 (Modul Domain) · **Bahasa surface Tamu:** 日本語 (Jepang) — PRD §4.1/§9.4<br>**Sumber kebenaran:** PRD_Kakehashi_v0_3_14 (§4.3, §5.3, §6.3 Sub-flow 3, §7.7, §9.8, Lampiran A/B.6/C/D)<br>**Dependency final:** ROLES_AND_PERMISSIONS (D-R7, §10) · GLOSSARY (Tamu) · BUSINESS_RULES · MODULE_JOBS (siklus guest_link) · MODULE_CANDIDATES (read-model + no-upload)<br>**Prinsip:** Setiap bit data bocor = kesalahan modul ini. Minimisasi data + hardening token. Saat ragu expose vs hide → **DEFAULT HIDE.**
>
## Tabel Verifikasi Teknologi (akses 2026-06-29)
Frontend terkunci: Livewire 4 + Blade custom + Tailwind 4 (ARCHITECTURE §5.1) → pilihan CSP nonce-vs-hash ditandai `[TERGANTUNG UI]`, tidak diasumsikan.
<table header-row="true">
<tr>
<td>Tech</td>
<td>Versi rekomen</td>
<td>Status maint</td>
<td>Caveat proyek</td>
<td>Sumber resmi</td>
</tr>
<tr>
<td>Laravel</td>
<td>13.x (`^13`), PHP 8.4</td>
<td>Aktif</td>
<td>`Storage::temporaryUrl()` driver `s3`→R2; route Tamu form-less</td>
<td>[laravel.com/docs/13.x/filesystem](http://laravel.com/docs/13.x/filesystem)</td>
</tr>
<tr>
<td>Signed URL R2 (flysystem-aws-s3-v3 `^3.x`)</td>
<td>aws-sdk-php ≥3.337</td>
<td>Aktif</td>
<td>Expiry R2 valid **1 dtk–7 hari (max 604.800 dtk)**; presigned URL membocorkan account-id+bucket → **wajib custom domain R2 / proxy**; checksum `WHEN_REQUIRED`  • `retain_visibility=false`</td>
<td>[developers.cloudflare.com/r2/api/s3/presigned-urls/](http://developers.cloudflare.com/r2/api/s3/presigned-urls/)</td>
</tr>
<tr>
<td>Laravel RateLimiter / throttle</td>
<td>bawaan L13</td>
<td>Aktif</td>
<td>`throttle` per-IP untuk Tamu anonim; cache driver `redis` (Redis co-located (2026-07-13), ARCH D5)</td>
<td>[laravel.com/docs/13.x/rate-limiting](http://laravel.com/docs/13.x/rate-limiting)</td>
</tr>
<tr>
<td>Security headers / CSP</td>
<td>`spatie/laravel-csp` v3 (Mar 2025) / middleware custom</td>
<td>Aktif</td>
<td>Nonce vs hash `[TERGANTUNG UI]`; HSTS/XFO/XCTO/Referrer-Policy via middleware route Tamu</td>
<td>[github.com/spatie/laravel-csp](http://github.com/spatie/laravel-csp)</td>
</tr>
</table>
---
## 1. Sifat Akses Tamu & Batas Modul
**Tamu** = aktor token eksternal (perusahaan Jepang), **bukan** kandidat, **bukan** akun internal, anonim, **READ-ONLY TOTAL** (GLOSSARY; ROLES §10/D-R7). Tamu tidak menulis, tidak mengunggah, tidak memberi komentar/feedback apa pun.
**Cakupan MODULE_GUEST_ACCESS:**
- Surface Tamu (halaman publik bertoken berbahasa Jepang).
- Validasi token + kode tambahan opsional.
- Read-model `GuestCandidateView` (whitelist Lampiran C).
- Signed URL handling untuk aset whitelist.
- Rate limit / anti-enumerasi, security headers, cache policy.
- Audit event `GUEST_ACCESS`.
**DI LUAR modul ini (domain MODULE_JOBS — rujuk, jangan definisikan ulang):**
- CRUD & approval link Tamu: request **Asisten Manajer** → approve **Manajer Job** → generate token.
- Lifecycle status link (`Menunggu Approval`→`Aktif`→`Kadaluarsa`) — Lampiran B.6.
- Event `GUEST_LINK_REQUESTED` / `GUEST_LINK_APPROVED` / `GUEST_LINK_REJECTED` (Lampiran A) dicatat di sisi internal.
Kontrak masuk dari MODULE_JOBS: objek `guest_link` aktif `{ token, interview_container_id, tanggal_kadaluarsa, kode_tambahan?, status_link }`.
---
## 2. Alur Validasi Token (PRD §6.3 Sub-flow 3, §7.7)
Satu token = satu kontainer wawancara. Token = **string acak panjang tak tertebak** (mis. 256-bit base64url), **BUKAN** ID kontainer. Tidak ada listing/browse lintas-kontainer, tidak ada filter publik.
Urutan validasi (gagal di tahap mana pun → tampilkan halaman tolak generik berbahasa Jepang, **tanpa membocorkan alasan spesifik**):
1. **Token ada?** Lookup `guest_link` by `token` (hash-compare). Tidak ada → 404 generik.
2. **Belum kadaluarsa?** `now() < tanggal_kadaluarsa`. Lewat → halaman "リンクの有効期限が切れています".
3. **Kontainer masih ****`Aktif`****?** Status kontainer wawancara = `Aktif`. Jika ditutup/dibatalkan → tolak (link otomatis tidak dapat diakses, Lampiran B.6).
4. **Kode tambahan (opsional per link):** jika `kode_tambahan` di-set → minta input, bandingkan (hash-compare, constant-time). Salah → tolak + hitung percobaan (lihat §6). Tidak di-set → lewati. **Rekomendasi operasional:** untuk kontainer berisi data sensitif, kode tambahan SANGAT DIANJURKAN diaktifkan (karena link mudah diteruskan via WA/chat), walau statusnya tetap opsional per link.
5. Lolos semua → buka sesi Tamu (read-only) untuk kontainer tsb + tulis audit `GUEST_ACCESS` (lihat §9).
Catatan: halaman tolak harus **seragam & generik** untuk semua kegagalan agar tidak membantu enumerasi (jangan beda-bedakan "token tidak ada" vs "kadaluarsa" pada response time/teks yang bisa dieksploitasi; gunakan constant-time compare + pesan netral).
---
## 3. Read-Model `GuestCandidateView` (Whitelist — PRD Lampiran C)
> **ATURAN:** Hanya field di bawah yang boleh keluar ke Tamu. Apa pun yang tidak tercantum → **HIDE**. Read-model di-resolve server-side; objek kandidat penuh **tidak pernah** dikirim ke klien.
### 3.1 Layar — Info Kontainer (header halaman)
<table header-row="true">
<tr>
<td>Field</td>
<td>Render</td>
<td>Catatan</td>
</tr>
<tr>
<td>Nama perusahaan tujuan</td>
<td>`nama_ja` (Jepang)</td>
<td>Hanya nama tujuan</td>
</tr>
<tr>
<td>Tanggal wawancara</td>
<td>tanggal</td>
<td></td>
</tr>
<tr>
<td>Jenis wawancara</td>
<td>Offline / Online</td>
<td></td>
</tr>
</table>
**HIDE di header:** deskripsi/catatan job internal, kebutuhan internal, kuota, catatan rekrutmen — **tidak di-whitelist**.
### 3.2 Layar — Daftar Kandidat (G2 — PSEUDONIM, anti-PII)
> **v0.3.11 (arahan atasan 2026-07-12):** daftar G2 **tidak** menampilkan nama & foto — memakai **kode kandidat** (Nomor Induk) sebagai identifier. Nama/foto/riwayat penuh dipindah ke **detail G3** (§3.2b).
<table header-row="true">
<tr>
<td>Field</td>
<td>Render</td>
<td>Catatan</td>
</tr>
<tr>
<td>Kode kandidat</td>
<td>`K-YYYY-NNNNN` (Nomor Induk)</td>
<td>Identifier daftar — BUKAN nama</td>
</tr>
<tr>
<td>Umur</td>
<td>`{n}歳`</td>
<td>computed dari tanggal lahir</td>
</tr>
<tr>
<td>Jenis Kelamin</td>
<td>`男` / `女`</td>
<td></td>
</tr>
<tr>
<td>Kewarganegaraan</td>
<td>label</td>
<td></td>
</tr>
<tr>
<td>Level Bahasa Jepang</td>
<td>jenis + score</td>
<td></td>
</tr>
<tr>
<td>Kualifikasi Keahlian Jepang / SSW · Bidang Diminati</td>
<td>jenis / teks</td>
<td></td>
</tr>
</table>
> **TIDAK di daftar G2:** nama, foto, riwayat kerja/pendidikan, sertifikat detail — hanya di detail G3.
### 3.2b Layar — Detail Kandidat (G3 — DIPERLUAS, read-only)
> **v0.3.11 (arahan atasan 2026-07-12):** detail G3 membuka **Nama + Foto + Riwayat Kerja/Pendidikan penuh**. Mewarisi seluruh field G2, ditambah baris di bawah. Idealnya drawer di dalam G2; enforcement whitelist tetap server-side.
<table header-row="true">
<tr>
<td>Field</td>
<td>Render</td>
<td>Catatan</td>
</tr>
<tr>
<td>Nama Alphabet + Nama Katakana</td>
<td>teks</td>
<td>**BARU** — dibuka di detail</td>
</tr>
<tr>
<td>Foto kandidat</td>
<td>signed URL R2 (expiry, lihat §5)</td>
<td>**BARU** — TTL 15 mnt, di-scope sesi token</td>
</tr>
<tr>
<td>Level Bahasa Inggris</td>
<td>jenis + score</td>
<td>hanya jika ada</td>
</tr>
<tr>
<td>Kualifikasi Mengemudi</td>
<td>jenis</td>
<td></td>
</tr>
<tr>
<td>Riwayat Pekerjaan PENUH</td>
<td>Nama Perusahaan + Penanggung TSK/Kumiai + Bidang Pekerjaan + Tanggal Masuk/Keluar</td>
<td>**BARU/diperluas** — seluruh info</td>
</tr>
<tr>
<td>Riwayat Pendidikan PENUH</td>
<td>Jenis Pendidikan + Jurusan + Nama Lembaga + Tanggal Masuk/Keluar</td>
<td>**BARU** — termasuk nama lembaga</td>
</tr>
<tr>
<td>URL Video Jikoshokai/Keahlian</td>
<td>embed URL (opsional)</td>
<td>**Default OFF**, hanya bila diaktifkan per link</td>
</tr>
<tr>
<td>Dokumen ber-flag `is_shareable`</td>
<td>link Google Drive "anyone with link"</td>
<td>**HANYA** dokumen ber-flag shareable</td>
</tr>
</table>
### 3.3 HIDE — Tidak Pernah Keluar ke Tamu (PRD §7.7, BUSINESS_RULES)
> **v0.3.11:** **nama, foto, nama lembaga pendidikan, dan nama perusahaan riwayat kerja BUKAN lagi field HIDE** — kini tampil di **detail G3** (§3.2b). Daftar G2 tetap pseudonim (identifier = kode kandidat).
- Catatan internal apa pun.
- Status partisipasi detail & riwayat perubahan/alasan.
- Data keluarga & kontak keluarga/darurat.
- Email, nomor telepon, Line ID.
- Tanggal lahir mentah (hanya **umur** yang tampil), alamat lengkap & tempat lahir.
- Imigrasi & dokumen peserta: Foto Zairyu, no. paspor, no. zairyu, alamat zairyu, jenis visa saat ini, seluruh `candidate_document`.
- Data fisik sensitif & kesehatan.
- IQ / MTK Score & Final Laporan Psikotes.
- Dokumen/sertifikat **tanpa** flag `is_shareable`; video **default OFF**.
---
## 4. Daftar Peserta + Pagination (PRD §8.4)
- Pagination **server-side**, default **25 per halaman** (berlaku juga untuk halaman Tamu).
- Sort/filter **hanya** pada kolom aman daftar G2 (mis. Umur, Level Bahasa Jepang, Kewarganegaraan, Bidang Diminati). **Nama, foto, nama lembaga, dan nama perusahaan — meski kini tampil di detail G3 — TETAP TIDAK boleh menjadi parameter sort/filter** (cegah inferensi/enumerasi PII). Kolom HIDE juga tidak boleh jadi sort/filter.
- Parameter query divalidasi ketat (allowlist nama kolom); input tak dikenal → diabaikan/ditolak, bukan diteruskan ke query.
- Scope query selalu dibatasi `interview_container_id` dari sesi token (tidak pernah dari parameter klien).
---
## 5. Akses Aset Tamu — foto R2 signed URL + dokumen Google Drive (PRD §9.8, v0.3.9)
> **Perubahan 2026-07-01 (PRD v0.3.9):** dokumen/sertifikat shareable kini **link Google Drive "anyone with link"** (bukan signed URL R2); **video** = **embed URL**. Signed URL R2 kini **hanya untuk foto thumbnail wajah**.
- **Foto wajah (detail G3, v0.3.11):** signed URL R2 ber-expiry pendek, di-generate ulang tiap reload, **terikat sesi ****`guest_link`**** Aktif & belum kadaluarsa**; **TTL 15 menit**. Foto kini tampil di **detail G3** (bukan lagi thumbnail daftar). Endpoint signed-URL menolak permintaan tanpa sesi token valid & di luar scope kontainer token.
- **Sertifikat shareable:** link Google Drive `anyone with link` hanya bila `is_shareable=true`; tidak pernah untuk `candidate_document`. Link dapat diteruskan, sehingga hanya materi yang memang disetujui untuk dibagikan.
- **Video (Jikoshokai/Keahlian):** **embed URL** (default OFF, aktif per link).
- TTL **tidak** disamakan dengan masa berlaku link bila link > batas hard R2 (**max 7 hari / 604.800 dtk**). TTL pendek + refresh menghindari batas ini sekaligus meminimalkan window kebocoran.
- Signed URL hanya digenerate setelah validasi sesi token sukses; tidak ada endpoint signed-URL yang bisa dipanggil tanpa sesi token valid.
- Konfigurasi R2 (foto wajah): `request_checksum_calculation=when_required`, `response_checksum_validation=when_required`, `retain_visibility=false` (hindari 501 NotImplemented).
- **Infra (keputusan DEPLOYMENT, perlu verifikasi — bukan asumsi):** presigned URL R2 mengekspos account-id + bucket → gunakan **custom domain R2** atau proxy. Tandai untuk ARCHITECTURE/DEPLOYMENT.
---
## 6. Rate Limit / Anti-Enumerasi (dua lapis: ketat untuk tebakan, longgar untuk tamu sah)
- **Lapis 1 — percobaan token TIDAK valid (anti-enumerasi):** **ketat 10 percobaan/menit/IP**. Hanya menghitung hit dengan token tidak ada / format salah / sudah kadaluarsa. Lewat → HTTP 429 generik.
- **Lapis 2 — buka link SAH (token valid + kontainer Aktif):** **longgar 60 request/menit per token** (di-scope per token, bukan per IP). Mencegah kantor perusahaan Jepang ber-NAT (banyak orang, 1 IP publik) salah kena blokir saat membuka link yang benar.
- **Kode tambahan:** **5 percobaan gagal → lockout 15 menit** (mirror MODULE_AUTH A-4), dihitung per token + per IP.
- Constant-time compare untuk token & kode tambahan; tidak ada pesan beda yang membantu tebak.
- Tidak ada autocomplete/listing token. Token tidak pernah dimuat dari ID kontainer.
- **Token tidak pernah ditulis ke log mentah:** access log server & log aplikasi hanya menyimpan `token_id` (referensi), bukan string token. Percobaan abnormal di-backoff + dicatat ke log keamanan (lihat §9).
- Cache driver `redis` (ARCH D5, Redis co-located (2026-07-13)) — RateLimiter Laravel kompatibel.
---
## 7. Security Headers (route Tamu)
<table header-row="true">
<tr>
<td>Header</td>
<td>Nilai</td>
</tr>
<tr>
<td>Strict-Transport-Security</td>
<td>`max-age=63072000; includeSubDomains; preload`</td>
</tr>
<tr>
<td>Content-Security-Policy</td>
<td>ketat; `default-src 'self'`; img/media dari domain R2 custom; **nonce vs hash ****`[TERGANTUNG UI]`** (sesuaikan stack FE saat dikunci)</td>
</tr>
<tr>
<td>X-Frame-Options</td>
<td>`DENY`</td>
</tr>
<tr>
<td>X-Content-Type-Options</td>
<td>`nosniff`</td>
</tr>
<tr>
<td>Referrer-Policy</td>
<td>`no-referrer`</td>
</tr>
<tr>
<td>Permissions-Policy</td>
<td>minimal (nonaktifkan kamera/mikrofon/geolokasi)</td>
</tr>
</table>
Diterapkan via middleware khusus group route Tamu, terpisah dari route internal.
---
## 8. Cache Strategy
- Halaman Tamu bertoken **TIDAK boleh** di-cache publik/CDN (PII + per-token). Header: `Cache-Control: no-store, private`.
- Signed URL / aset bertanda-tangan **tidak** di-cache bersama.
- Aset statis netral (CSS/JS/ikon tanpa PII) boleh di-cache.
- **CDN untuk aset statis = keputusan infra** (PRD §9.6 tidak menyebut CDN) → verifikasi ke ARCHITECTURE/DEPLOYMENT, jangan asumsikan.
---
## 9. Audit Events (PRD Lampiran A — JANGAN mengarang)
- **`GUEST_ACCESS`** dicatat modul ini saat Tamu berhasil membuka link.
	- Detail: `{ "token_id": ..., "ip": "...", "container_id": ... }`
	- `actor_id` = **null** (Tamu anonim).
	- Disimpan ke `guest_access_log` (PRD §5.3): `token_id` FK, Waktu akses (auto), IP pengakses (opsional teks).
- **Tidak diduplikasi di sini** (terjadi di MODULE_JOBS): `GUEST_LINK_REQUESTED`, `GUEST_LINK_APPROVED`, `GUEST_LINK_REJECTED`.
- **Percobaan akses GAGAL (token salah/kadaluarsa/kode salah) TIDAK punya enum di Lampiran A** → JANGAN mengarang event audit. Catat ke **log keamanan aplikasi** (terpisah dari `audit_log`) untuk deteksi brute-force. Bila ingin event audit resmi → usulan kecil untuk PRD (perlu approval user).
- **Privasi log akses:** `guest_access_log.ip` = data pribadi → masa simpan & anonimisasi mengikuti DATA_RETENTION_AND_PRIVACY (jangan disimpan selamanya).
---
## 10. Edge Cases
- Token tidak ada / format salah → 404 generik (constant-time).
- Token kadaluarsa → halaman Jepang "有効期限切れ".
- Kontainer ditutup/dibatalkan setelah link dibuat → tolak (link otomatis mati).
- Kode tambahan diminta tapi kosong/salah → hitung percobaan; ke-5 gagal → lockout.
- Link valid tapi kontainer kosong (belum ada peserta) → tampilkan header kontainer + daftar kosong (tanpa error info).
- Reload setelah signed URL expiry → regenerate selama sesi token masih valid.
- Akses dokumen non-shareable via URL tebakan → ditolak (signed URL hanya untuk aset whitelist).
- Banyak percobaan token tidak valid → throttle 429 (Lapis 1). Kantor ber-NAT membuka link SAH → tidak kena blokir (Lapis 2 per-token).
- Race: link expired tepat saat dibuka → revalidasi sebelum render tiap request.
- Kandidat `pii_anonymized_at IS NOT NULL` dikeluarkan dari G2/G3. Request detail langsung ditolak generik; jangan render kartu anonim.
---
## 11. Test Plan (termasuk negative & PII-leak)
**Positif**
- Token valid + kontainer Aktif + tanpa kode → render GuestCandidateView, audit `GUEST_ACCESS` tertulis.
- Token valid + kode tambahan benar → akses sukses.
- Pagination default 25; halaman ke-2 server-side.
**Negatif / keamanan**
- Token tidak ada / acak → 404 generik, tidak ada beda timing.
- Token kadaluarsa → tolak.
- Kontainer ditutup → tolak.
- Kode tambahan salah 5× → lockout 15 menit.
-
	> 10 req/menit/IP → 429.
- Tebak ID kontainer sebagai token → gagal (token ≠ ID).
- 11 percobaan token tidak valid/menit dari 1 IP → 429 (Lapis 1).
- 30 orang buka link SAH dari 1 IP kantor (NAT) → semua berhasil, tidak kena 429 (Lapis 2 per-token).
- Token tidak muncul di access log / log aplikasi (hanya `token_id`).
**PII-leak (wajib lulus semua)**
- Response G2 memakai **Nomor Induk ****`K-YYYY-NNNNN`** sebagai identifier—tidak ada `CAND-*` baru—dan tidak mengandung nama, foto, atau riwayat kerja/pendidikan. Response **kedua profil** tidak mengandung: email, telepon, Line ID, alamat, tempat lahir, tanggal lahir mentah, imigrasi/dokumen peserta, IQ/MTK/psikotes, data keluarga, catatan internal. *(v0.3.11: detail G3 kini MENAMPILKAN nama, foto, nama lembaga pendidikan, nama perusahaan; kode kandidat = identifier G2.)*
- Sort/filter pada kolom HIDE → ditolak/diabaikan.
- Dokumen non-shareable tidak pernah menghasilkan signed URL.
- Signed URL foto thumbnail expiry sesuai TTL (15 mnt); URL kedaluwarsa → akses ditolak R2. Dokumen shareable = link Google Drive (anyone-with-link); video = embed URL (bukan signed URL R2).
- Video default OFF → tidak ada URL video kecuali diaktifkan per link.
- Kandidat anonim tidak muncul di list/detail Guest; direct detail request menghasilkan respons generik.
- Header `Cache-Control: no-store` ada di semua response halaman Tamu.
- Security headers (HSTS/CSP/XFO/XCTO/Referrer) terpasang di route Tamu.
---
## 12. Keputusan Final & GAP PRD
**Keputusan (terkonfirmasi user 2026-06-29):**
1. TTL signed URL Tamu: pendek + refresh — dokumen 5 mnt, foto/video 15 mnt.
2. URL Video Jikoshokai/Keahlian: **default OFF**, aktif per link.
3. Rate-limit endpoint token: **dua lapis** — percobaan token tidak valid 10/mnt/IP (anti-enumerasi) + buka link sah 60/mnt **per token** (aman untuk kantor ber-NAT) + kode tambahan 5 gagal → lockout 15 mnt.
4. Kode tambahan WA: **opsional per link** (ikut PRD §4.3/§5.3); **disarankan aktif** untuk kontainer berisi data sensitif (link mudah di-forward).
5. GAP PRD feedback: Tamu tetap **read-only murni**, tanpa kanal tulis (final).
6. **Nama perusahaan riwayat kerja DITAMPILKAN ke Tamu** (keputusan user 2026-06-30, setelah diskusi atasan) — `GuestCandidateView` §3.2 & PRD v0.3.6 Lampiran C menampilkan *Nama Perusahaan + Bidang Pekerjaan + durasi* (sebelumnya disembunyikan). Nama lembaga pendidikan tetap HIDE.
7. **Arsitektur file (PRD v0.3.9, keputusan user 2026-07-01):** dokumen/sertifikat shareable ke Tamu = **link Google Drive "anyone with link"** (bukan signed URL R2); foto thumbnail tetap signed URL R2 (TTL 15 mnt); video = embed URL. Dokumen peserta sensitif tetap HIDE.
**GAP PRD / infra terbuka untuk modul lain:**
- Custom domain R2 / proxy untuk presigned URL (sembunyikan account-id+bucket) → **DEPLOYMENT/ARCHITECTURE**.
- CDN aset statis netral → **DEPLOYMENT** (PRD §9.6 tidak menyebut, jangan asumsikan).
- CSP nonce vs hash → final saat **stack FE** dikunci (UI_WIREFRAME_NOTES).
- **Izin/consent kandidat** untuk menampilkan foto + atribut ke perusahaan Jepang (berbagi data ke pihak ketiga, APPI/UU PDP) → **DATA_RETENTION_AND_PRIVACY**.
- **Masa simpan ****`guest_access_log.ip`** (data pribadi) → **DATA_RETENTION_AND_PRIVACY**.
- **Event audit untuk percobaan akses gagal** belum ada di enum Lampiran A → log keamanan non-audit (default) atau usulan kecil untuk PRD (perlu approval user).
- **Event audit granular ****`GUEST_DETAIL_VIEWED`** (buka detail kandidat G3) — **DITAMBAHKAN ke enum audit** (keputusan user 2026-07-12, atas rekomendasi agent). Actor NULL; `detail` = `{token_id, candidate_id, container_id, ip}`; dicatat di PRD Lampiran A (A.1/A.2) & DATABASE_SCHEMA §7. Melengkapi `GUEST_ACCESS` (sukses buka link) dengan jejak per-kandidat untuk forensik eksposur PII (APPI/UU PDP).
