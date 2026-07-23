---
title: "TECH_VERSION_SEED"
status: "Current baseline"
source_notion_title: "TECH_VERSION_SEED"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# TECH VERSION SEED — Kakehashi
> **Tujuan:** titik awal versi tech yang SUDAH diverifikasi ke sumber resmi, supaya agent<br>brainstormer tidak mulai dari pengetahuan usang. **Tetap WAJIB diverifikasi ulang** oleh<br>tiap agent saat brainstorm (minor version bergerak cepat).
	**Tanggal verifikasi:** 2026-06-28 (Asia/Jakarta). **Sumber:** situs resmi / GitHub releases /<br>[endoflife.date](http://endoflife.date) / Packagist (lihat kolom Sumber).<br>**Konteks proyek:** modular monolith, VPS **4 vCPU / 8 GB** (baseline 2026-07-13), Redis co-located, Cloudflare R2, bilingual ID/JP.
---
## 1. Core runtime (WAJIB — fondasi)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi direkomendasikan</td>
<td>Status (per 2026-06)</td>
<td>Catatan untuk Kakehashi</td>
<td>Sumber</td>
</tr>
<tr>
<td>**PHP**</td>
<td>**8.4** (alt: 8.5)</td>
<td>8.5 = latest (rilis Nov 2025, 8.5.7 Jun 2026, active s/d Des 2027); 8.4 active s/d **Des 2026**, security s/d Des 2028</td>
<td>Pilih **8.4** utk kematangan ekosistem package; **8.5** boleh jika semua dependency sudah kompatibel. Hindari 8.3 (security-only) utk proyek baru.</td>
<td>php.net/supported-versions, endoflife.date/php</td>
</tr>
<tr>
<td>**Laravel**</td>
<td>**13.x** (LTS-alt: 12.x)</td>
<td>13 rilis 17 Mar 2026 (latest 13.17.0, 23 Jun 2026); bugfix s/d Q3 2027, security s/d Mar 2028. Butuh PHP 8.3–8.5</td>
<td>**13** untuk proyek baru. Jika ingin window dukungan lebih konservatif, **12** (security s/d Feb 2027, PHP 8.2–8.5).</td>
<td>laravel.com/docs/13.x/releases, laravelversions.com</td>
</tr>
<tr>
<td>**PostgreSQL**</td>
<td>**18.x** (latest 18.4)</td>
<td>18.4 rilis 14 Mei 2026 (stable). PG 19 masih beta (Jun 2026). PG 14 EOL Nov 2026</td>
<td>Pakai **18** — dukungan jangka panjang (5 thn). `pg_trgm`, JSONB, GIN index semua tersedia. Jangan pakai PG 19 (beta) di produksi.</td>
<td>postgresql.org (news 2026-05-14)</td>
</tr>
</table>
---
## 2. Autentikasi & otorisasi (untuk MODULE_AUTH & ROLES_AND_PERMISSIONS)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi</td>
<td>Status</td>
<td>Catatan untuk Kakehashi</td>
<td>Sumber</td>
</tr>
<tr>
<td>**2FA TOTP — opsi A: Laravel Fortify**</td>
<td>bawaan Laravel 12/13</td>
<td>Aktif; 2FA TOTP kini built-in di starter kit</td>
<td>Paling “first-party”. TOTP (RFC 6238) sesuai keputusan PRD v0.3 (bukan Google OAuth). Pertimbangkan ini lebih dulu.</td>
<td>laravel.com/docs/13.x/fortify</td>
</tr>
<tr>
<td>**2FA TOTP — opsi B: pragmarx/google2fa-laravel**</td>
<td>**3.0.1** (core google2fa **9.0.0**, Sep 2025)</td>
<td>Aktif; mendukung Laravel s/d 13</td>
<td>RFC 4226 (HOTP) + **RFC 6238 (TOTP)**. Butuh QR (bacon/bacon-qr-code atau google2fa-qrcode v4). Pilih bila perlu kontrol lebih granular drpd Fortify.</td>
<td>packagist.org/packages/pragmarx/google2fa-laravel</td>
</tr>
<tr>
<td>**RBAC — spatie/laravel-permission**</td>
<td>**8.0.0** (30 Mei 2026)</td>
<td>Aktif; v8 kompatibel Laravel 13 (v7 utk PHP 8.4/Laravel 12)</td>
<td>Cocok untuk 6 role hardcode PRD. Catatan: PRD membatasi Super Admin hanya assign/unassign role, bukan bikin tipe baru — atur lewat kebijakan, bukan UI bebas.</td>
<td>github.com/spatie/laravel-permission/releases</td>
</tr>
</table>
---
## 3. Domain & state (untuk STATUS_STATE_MACHINE & BUSINESS_RULES)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi</td>
<td>Status</td>
<td>Catatan untuk Kakehashi</td>
<td>Sumber</td>
</tr>
<tr>
<td>**spatie/laravel-model-states**</td>
<td>**2.14.1** (22 Apr 2026)</td>
<td>Aktif</td>
<td>Implementasi state machine (state pattern) untuk status kontainer wawancara/penempatan, status_wawancara, status_penempatan. Validasi transisi otomatis (allowTransition).</td>
<td>github.com/spatie/laravel-model-states</td>
</tr>
<tr>
<td>**pg_trgm (cek-kemiripan)**</td>
<td>bawaan PostgreSQL 18 (F.35)</td>
<td>Aktif; “trusted extension”</td>
<td>`CREATE EXTENSION pg_trgm;` lalu **GIN index ****`gin_trgm_ops`** pada nama ter-normalisasi. Sesuai aturan PRD: similarity >= 0.4 + DOB + kewarganegaraan. GIN cocok utk read-heavy.</td>
<td>postgresql.org/docs/current/pgtrgm.html</td>
</tr>
<tr>
<td>**Konkurensi / optimistic lock**</td>
<td>fitur Eloquent + kolom `version`</td>
<td>n/a</td>
<td>Tidak perlu package khusus; gunakan kolom versi + cek di dalam transaksi (anti double-approval PRD §P2).</td>
<td>(pola, bukan package)</td>
</tr>
</table>
---
## 4. Penyimpanan file / Cloudflare R2 (untuk MODULE_GUEST_ACCESS, ARCHITECTURE, SECURITY)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi</td>
<td>Status</td>
<td>Catatan untuk Kakehashi</td>
<td>Sumber</td>
</tr>
<tr>
<td>**league/flysystem-aws-s3-v3**</td>
<td>**3.x** (flysystem 3.31.0, Jan 2026)</td>
<td>Aktif</td>
<td>Driver `s3` Laravel dipakai untuk R2 (S3-compatible).</td>
<td>flysystem.thephpleague.com/docs/adapter/aws-s3-v3</td>
</tr>
<tr>
<td>**⚠️ Caveat R2 + aws-sdk-php**</td>
<td>aws-sdk-php >= 3.337.0</td>
<td>PENTING</td>
<td>SDK baru mengaktifkan checksum integrity (`x-amz-checksum-crc32`) yang **belum didukung R2** → upload gagal. Solusi: set integrity ke `WHEN_REQUIRED` dan `'retain_visibility' => false` di config disk. WAJIB diuji saat implementasi.</td>
<td>github.com/thephpleague/flysystem/issues/1845</td>
</tr>
<tr>
<td>**Signed URL**</td>
<td>bawaan driver S3 (`temporaryUrl`)</td>
<td>Aktif</td>
<td>Sesuai PRD: bucket privat + SSE + signed URL 5–15 mnt.</td>
<td>(Laravel filesystem)</td>
</tr>
</table>
---
## 5. Frontend / UI — TERKUNCI (UI_WIREFRAME_NOTES FINAL): Livewire 4 + Blade custom + Tailwind 4. Filament & Inertia+Vue = opsi yang TIDAK dipilih untuk MVP.
<table header-row="true">
<tr>
<td>Stack</td>
<td>Versi</td>
<td>Status</td>
<td>Kapan dipilih</td>
<td>Sumber</td>
</tr>
<tr>
<td>**Livewire**</td>
<td>**4.x** (4.3.1, Jun 2026; LW4 rilis Jan 2026)</td>
<td>Aktif</td>
<td>Monolith “PHP-first”, minim JS — cocok untuk tim kecil & VPS kecil.</td>
<td>github.com/livewire/livewire/releases</td>
</tr>
<tr>
<td>**Filament (admin/UI)**</td>
<td>**5.x** (butuh Livewire 4; CVE-2026-33080 patched di 5.3.5/4.8.5)</td>
<td>Aktif</td>
<td>Bila ingin panel admin/CRUD cepat. Pastikan versi >= 5.3.5 utk fix XSS.</td>
<td>filamentphp.com, github.com/filamentphp/filament/releases</td>
</tr>
<tr>
<td>**Inertia.js + Vue 3**</td>
<td>Inertia **3.0** (26 Mar 2026; v2 security s/d Mar 2027)</td>
<td>Aktif</td>
<td>Bila butuh interaktivitas SPA lebih kaya & tim nyaman Vue.</td>
<td>inertiajs.com, laravel-news.com/inertia-3-0-0</td>
</tr>
<tr>
<td>**Tailwind CSS**</td>
<td>**4.x** (v4.1+)</td>
<td>Aktif</td>
<td>Default styling modern; v4 butuh browser modern (Safari 16.4+, Chrome 111+).</td>
<td>tailwindcss.com/blog/tailwindcss-v4</td>
</tr>
</table>
> Rekomendasi awal (terkunci di UI_WIREFRAME_NOTES): **Livewire 4 + Blade custom + Tailwind 4 (A2)**. Inertia+Vue tidak dipilih untuk MVP.
---
## 6. Async / queue / cache / notifikasi (untuk ARCHITECTURE)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Pilihan</td>
<td>Catatan untuk Kakehashi</td>
<td>Sumber</td>
</tr>
<tr>
<td>**Redis**</td>
<td>co-located di VPS ([localhost](http://localhost))</td>
<td>Baseline VPS **4C/8G** (2026-07-13). Dipakai untuk **cache, session, queue, rate limit**. Jangan expose port publik. Alokasi RAM kasar ≤1 GB.</td>
<td>[redis.io](http://redis.io) · [laravel.com/docs/13.x/redis](http://laravel.com/docs/13.x/redis)</td>
</tr>
<tr>
<td>**Queue driver**</td>
<td>**redis**  • **2 worker**</td>
<td>Email kritis via queue. Supervisor 2 proses. Unique constraint DB + transaksi **tetap** sumber kebenaran anti-duplikasi bisnis.</td>
<td>[laravel.com/docs/13.x/queues](http://laravel.com/docs/13.x/queues)</td>
</tr>
<tr>
<td>**Cache / session / rate limit**</td>
<td>**redis**</td>
<td>Lookup bilingual, permission, throttle login/tamu. Tabel `cache`/`sessions` boleh tetap sebagai fallback non-prod.</td>
<td>[laravel.com/docs/13.x/cache](http://laravel.com/docs/13.x/cache) · session · rate-limiting</td>
</tr>
<tr>
<td>**Notifikasi realtime**</td>
<td>in-app + polling ≤60 dtk</td>
<td>Sesuai PRD (tanpa websocket di MVP). Tidak perlu Reverb.</td>
<td>(keputusan PRD v0.3.12)</td>
</tr>
</table>
---
## 7. Catatan kepatuhan untuk agent (WAJIB)
1. Seed ini adalah **titik awal**, bukan kata akhir. Setiap agent tetap menjalankan<br>**PROTOKOL KESEGARAN TEKNOLOGI** dan mengisi TABEL VERIFIKASI TEKNOLOGI miliknya.
2. Selalu **pin versi mayor** di rekomendasi (mis. “Laravel 13.x”), verifikasi minor terkini saat implementasi.
3. Jika sebuah package tampak tidak dipelihara saat dicek ulang, laporkan + usulkan alternatif.
4. Patuhi batas infra PRD §9.6 (VPS **4C/8G**, Redis co-located, single VPS no HA); tandai trade-off bila pilihan butuh multi-server/HA/WebSocket (post-MVP).
5. Frontend (§5) SUDAH diputuskan: Livewire 4 + Blade custom + Tailwind 4 (UI_WIREFRAME_NOTES FINAL, dicatat di DECISIONS_LOG). Filament/Inertia+Vue = opsi yang tidak dipilih.
