# DESIGN.md — Kakehashi

<aside>
🎨

[**DESIGN.md](http://DESIGN.md) — Kakehashi (jangkar token desain).** Dasar tema **Enterprise-netral (zinc/slate, gaya shadcn)** — dipilih user 2026-06-30. **Diadaptasi** ke Kakehashi: font JP, warna badge dipetakan ke STATUS_STATE_MACHINE (bukan warna brand mentah), kontras WCAG AA, komponen admin. **Disuntik UTUH ke setiap prompt Stitch** (jangan meniru HTML sebelumnya — cegah drift).

**Stack terkunci: Livewire 4 + Blade custom + Tailwind 4 · Bahasa default ID (Tamu JP).** Hierarki final: PRD v0.3.14 > fondasi domain/schema/modul/API/security/privacy > UI_WIREFRAME_NOTES > Approved HTML non-authoritative.

*Katalog publik ([getdesign.md](http://getdesign.md)) = inspirasi/titik awal, BUKAN design system resmi merek; file ini = jangkar resmi proyek hasil adaptasi.*

</aside>

## 0. Cara pakai

- Salin **seluruh isi [DESIGN.md](http://DESIGN.md) ini** ke bagian `Apply this design system exactly:` pada tiap prompt Stitch.
- Stitch → output **HTML + Tailwind CSS** skeleton. Aksesibilitas/state/logika ditambah engineer (catat di NOTES).
- Token diselaraskan ke **Tailwind 4** (skala warna zinc/slate/amber/emerald/red/blue standar Tailwind) agar markup Stitch langsung kompatibel dengan stack A2.

---

## 1. Prinsip Visual

1. **Netral dulu, warna untuk makna.** Kanvas abu-abu (zinc); warna **hanya** untuk status & aksi — bukan dekorasi.
2. **Data-dense, tenang.** Padding kompak, garis tipis > bayangan tebal (flat enterprise), banyak tabel.
3. **Bilingual presisi.** Latin = Inter; **JP = Noto Sans JP**; angka = tabular-nums.
4. **Status tak pernah warna-saja.** Badge selalu = ikon + teks + warna (aksesibilitas).
5. **Desktop-first**, responsif sekunder.

---

## 2. Warna (token — light theme MVP)

```css
/* Surface & teks (zinc) */
--bg-page:      #FAFAFA;  /* zinc-50  */
--bg-surface:   #FFFFFF;  /* kartu/tabel */
--bg-subtle:    #F4F4F5;  /* zinc-100 row hover/header */
--border:       #E4E4E7;  /* zinc-200 */
--border-strong:#D4D4D8;  /* zinc-300 */
--text-primary: #18181B;  /* zinc-900 */
--text-secondary:#52525B; /* zinc-600 */
--text-muted:   #71717A;  /* zinc-500 */
/* Aksi (netral, bukan brand) */
--primary:      #18181B;  /* tombol utama solid (zinc-900) */
--primary-hover:#27272A;  /* zinc-800 */
--on-primary:   #FFFFFF;
--focus-ring:   #2563EB;  /* blue-600 — fokus keyboard */
--link:         #2563EB;
/* Semantik status (pasangan bg-100 / text-800 = AA) */
--success:#16A34A; --success-bg:#DCFCE7; --success-text:#166534;
--warning:#D97706; --warning-bg:#FEF3C7; --warning-text:#92400E;
--info:   #2563EB; --info-bg:   #DBEAFE; --info-text:   #1E40AF;
--danger: #DC2626; --danger-bg: #FEE2E2; --danger-text: #991B1B;
--accent2:#0D9488; --accent2-bg:#CCFBF1; --accent2-text:#115E59; /* teal: terminal-positif */
--exit:   #EA580C; --exit-bg:   #FFEDD5; --exit-text:   #9A3412; /* orange: mundur sukarela */
--neutral-bg:#F4F4F5; --neutral-text:#3F3F46; /* zinc: draft/arsip/kadaluarsa */
```

Dark mode = opsi pasca-MVP (zinc-950 surface); MVP light-only.

---

## 3. Pemetaan Badge → STATUS_STATE_MACHINE (adaptasi inti)

> Warna **dipetakan ke makna status**, konsisten lintas 7 mesin. Selalu **ikon + teks + warna**. Render label terlokalisasi (ID/JP), simpan enum kanonik (D9).
>

> **Ikon kanonik badge** (`stroke=currentColor`, ~14px, mewarisi warna teks; ditetapkan 2026-07-01 — badge lama berbasis dot diganti ikon): **Menunggu Tinjauan / Menunggu Approval / Menunggu Wawancara = jam (clock)**; **Disetujui / Aktif / Lulus / Bekerja = check-circle**; **Tersedia = check (polos)**; **Ditolak / Dikeluarkan / Dibatalkan / Tidak Lolos = x-circle**; **Mengundurkan Diri = arrow-uturn-left (keluar)**; **Terkirim / Selesai Kontrak / Diterapkan = badge-check (teal)**; **Sedang Dipakai = lock (gembok)**; **Draft / Arsip / Kadaluarsa / Ditutup = neutral (dot/garis)**.
>

| Mesin | Status | Token | bg / text |
| --- | --- | --- | --- |
| Kontainer Wawancara | Draft | neutral | #F4F4F5 / #3F3F46 |
|  | Menunggu Approval | warning | #FEF3C7 / #92400E |
|  | Aktif | success | #DCFCE7 / #166534 |
|  | Ditutup | neutral (solid garis) | #E4E4E7 / #27272A |
|  | Dibatalkan | danger | #FEE2E2 / #991B1B |
| Kontainer Penempatan | Draft / Arsip | neutral | #F4F4F5 / #3F3F46 |
|  | Menunggu Approval | warning | #FEF3C7 / #92400E |
|  | Aktif | success | #DCFCE7 / #166534 |
|  | Dibatalkan | danger | #FEE2E2 / #991B1B |
| status_wawancara | Menunggu Wawancara | warning | #FEF3C7 / #92400E |
|  | Lulus | success | #DCFCE7 / #166534 |
|  | Proses Dokumen / Siap Dikirim | info | #DBEAFE / #1E40AF |
|  | Terkirim | accent2 (teal, terminal+) | #CCFBF1 / #115E59 |
|  | Tidak Lolos / Dikeluarkan | danger | #FEE2E2 / #991B1B |
|  | Mengundurkan Diri | exit (orange) | #FFEDD5 / #9A3412 |
| status_penempatan | Bekerja | success | #DCFCE7 / #166534 |
|  | Selesai Kontrak | accent2 (teal) | #CCFBF1 / #115E59 |
|  | Mengundurkan Diri | exit (orange) | #FFEDD5 / #9A3412 |
|  | Dikeluarkan | danger | #FEE2E2 / #991B1B |
| Approval Kandidat | Menunggu Tinjauan-BARU / -REVISI | warning | #FEF3C7 / #92400E |
|  | Disetujui | success | #DCFCE7 / #166534 |
|  | Ditolak | danger | #FEE2E2 / #991B1B |
|  | Diterapkan | accent2 (teal) | #CCFBF1 / #115E59 |
| Link Tamu | Menunggu Approval | warning | #FEF3C7 / #92400E |
|  | Aktif | success | #DCFCE7 / #166534 |
|  | Kadaluarsa | neutral | #F4F4F5 / #3F3F46 |
| Ketersediaan kandidat | Tersedia | success | #DCFCE7 / #166534 |
|  | Sedang Dipakai | neutral (zinc) | #F4F4F5 / #3F3F46 |

> Catatan makna: **danger (merah)** = gagal/paksa/batal (Ditolak, Tidak Lolos, Dikeluarkan, Dibatalkan); **exit (oranye)** = mundur sukarela (Mengundurkan Diri) — dibedakan dari merah; **accent2 (teal)** = terminal-positif (Terkirim, Selesai Kontrak, Diterapkan).
>

---

## 4. Tipografi (bilingual)

```css
--font-sans: "Inter", "Noto Sans JP", system-ui, sans-serif; /* JP glyph via Noto Sans JP */
--font-mono: "JetBrains Mono", ui-monospace, monospace;       /* NIK K-YYYY-NNNNN, token_id */
--num: tabular-nums;  /* kolom angka/tanggal sejajar */
```

| Token | Ukuran / line-height | Pemakaian |
| --- | --- | --- |
| text-xs | 12 / 16 | badge, meta, caption tabel |
| text-sm | 13–14 / 20 | **base tabel & form** (data-dense) |
| text-base | 14–16 / 24 | body, label |
| text-lg | 16–18 / 28 | judul section form |
| text-xl / 2xl | 20–24 | judul halaman |
- **JP wajib**: `Noto Sans JP` (fallback `Noto Sans CJK JP`) agar 歳 / 男・女 / 既婚・未婚 / 右・左 / 有り・無し dan `YYYY年MM月DD日` render benar. **Jangan terjemahkan glyph ke Latin.**
- Angka/tanggal pakai `tabular-nums`.

---

## 5. Spacing, Radius, Elevation

```css
--space-base: 4px;            /* skala 4-8-12-16-24-32 */
--radius-sm: 4px; --radius-md: 6px; --radius-lg: 8px; --radius-full: 9999px;
--row-height-dense: 40px;     /* baris tabel */
--field-height: 36px;         /* input/select */
--shadow-sm: 0 1px 2px rgba(24,24,27,.06);   /* kartu */
--shadow-md: 0 4px 12px rgba(24,24,27,.10);  /* modal/dropdown */
```

- **Garis > bayangan**: kartu pakai `border` + `shadow-sm`; bayangan tebal hanya modal/dropdown.
- Card padding 16–24px; section gap 24–32px; max-width konten 1280px (desktop-first), tabel boleh full-width.

---

## 6. Komponen Admin (spesifikasi untuk Stitch)

### 6.1 Tabel data (list)

- Header `--bg-subtle` sticky; baris tinggi 40px; hover `#F4F4F5`; pemisah garis tipis `--border`.
- **Sort/filter hanya kolom whitelist (§8.4)**; ikon sort hanya pada kolom itu.
- Footer pagination **25/halaman** (server-side): ‹ Prev / nomor / Next › + total.
- Kolom angka/tanggal rata-kanan + tabular-nums; kolom status = komponen Badge (§3).

### 6.2 Badge status

- Pill `--radius-full`, `text-xs`, padding 2×8px, **ikon + teks** + warna token (§3). Dot kecil opsional untuk varian (mis. BARU vs REVISI).

### 6.3 Tombol

- **Primary** solid `--primary` teks putih; **Secondary** outline `--border-strong`; **Destructive** `--danger` (Dikeluarkan/Hapus); **Ghost** untuk aksi tabel. Ukuran sm(32px)/md(36px). Fokus ring 2px `--focus-ring`.

### 6.4 Form multi-section (Kandidat)

- Section ber-judul (`text-lg`) + deskripsi; grid 2 kolom desktop, 1 kolom sempit; label di atas field; helper & **error text merah** di bawah.
- Banner **cek-kemiripan = soft warning** (warning, **tidak memblok**, ada tombol "Lanjutkan").
- **Foto wajah:** upload R2 privat ≤5MB (`jpg/png/webp`) + progress; signed URL 15 menit.
- **Dokumen Peserta:** blok repeatable `Jenis Dokumen + URL Google Drive privat + Catatan`; bukan upload, tanpa envelope/signed URL aplikasi.
- Tombol simpan menampilkan indikator **optimistic version**; konflik → lihat 6.8.

### 6.5 Modal Step-up Re-auth (HANYA 5 trigger)

- Overlay `rgba(24,24,27,.5)`; kartu terpusat; judul "Verifikasi Ulang"; field **password + kode TOTP 6 digit**; tombol Konfirmasi (primary) / Batal. Catatan TTL 5 menit (engineer). **Hanya muncul** untuk: ubah role/nonaktifkan akun, tutup kontainer wawancara, keluarkan/cabut kandidat (Wawancara & Penempatan), kelola lookup/config + master perusahaan, anonimisasi PII.

### 6.6 Dropdown Notifikasi

- Ikon lonceng + badge angka belum-dibaca; panel daftar item (judul, waktu `YYYY年MM月DD日`, status baca). **Polling ≤60 dtk tanpa websocket** (`wire:poll`) = perilaku engineer.

### 6.7 Input / Select / Date

- Tinggi 36px, border `--border-strong`, fokus ring biru. **Select lookup bilingual** menampilkan label sesuai bahasa aktif. **Date picker** render `YYYY年MM月DD日` saat locale JP.

### 6.8 Toast & Konflik 409 (optimistic lock, D8)

- Toast success/error/info pojok kanan-atas. **Konflik 409** = banner/dialog jelas: "Data telah diubah pihak lain. Muat ulang lalu coba lagi." + tombol Muat Ulang.

### 6.9 Pending overlay (⏳ Maker–Checker)

- Label overlay "Menunggu Persetujuan …" di header detail; status agregat tetap.

---

## 7. i18n & Glyph (§9.4)

- Toggle ID/JP di top-bar (semua role internal); **Tamu = JP terkunci**, tanpa toggle.
- Glyph kanonik: 歳 / 男・女 / 既婚・未婚 / 右・左 / 有り・無し; tanggal `YYYY年MM月DD日`.
- Sumber Laravel `lang/id` + `lang/ja`; fallback berjenjang → bahasa lain → `code` mentah. **Simpan enum kanonik, render glyph** (D9).

---

## 8. Aksesibilitas (WCAG AA — wajib di engineering)

- Teks normal ≥ **4.5:1**, teks besar/elemen UI ≥ **3:1** (semua pasangan badge bg-100/text-800 di §3 lolos AA).
- **Status tak pernah warna-saja** (ikon + teks).
- Fokus keyboard terlihat (ring 2px `--focus-ring`); urutan tab logis; label `<label>`/`aria-*` pada semua field & ikon-tombol; modal step-up = focus-trap.
- Tabel: header `<th scope>`, kaitan baris jelas.

> Stitch hanya skeleton — ARIA, focus-trap, live-region notif, dan kontras final **diverifikasi/ditambah engineer** (dicatat per layar di NOTES [6d]).
>

---

## 9. Do / Don't

- ✅ Pakai warna **hanya** untuk status/aksi; ✅ garis tipis untuk memisah; ✅ Noto Sans JP untuk semua glyph; ✅ tabular-nums.
- ❌ Jangan tambah warna brand mentah yang bentrok badge; ❌ jangan terjemahkan glyph JP ke Latin; ❌ jangan padatkan hingga < 4.5:1; ❌ jangan munculkan step-up di luar 5 trigger; ❌ (layar Tamu) jangan tampilkan field di luar GuestCandidateView atau tombol upload/aksi.

---

## 10. Shell & Kontrak Komponen (diekstrak dari ANCHOR — WAJIB disuntik tiap prompt)

> Deskripsi struktur reusable dari layar anchor (Kandidat Form). **Suntik bagian ini ke tiap prompt Stitch** agar semua layar mewarisi shell yang sama. **Jangan menyalin HTML lama mentah** (cegah drift) — cukup ikuti kontrak ini + token §2–§9.
>

### 10.1 App Shell (top bar, sticky)

- Kiri: kotak logo `架` (bg `--primary`, teks putih) + “Kakehashi” + subjudul abu opsional.
- Kanan: **toggle ID/JP** (pill; aktif = solid `--primary`), **lonceng notifikasi** + badge angka belum-dibaca (merah), **menu user** (avatar inisial + nama + peran).

### 10.2 Kerangka Halaman

- `max-width 1280`, padding 24. **Halaman form/detail** = 2 kolom: **left in-page section nav** (`w-56`, sticky) + main. **Halaman list** = full-width (tanpa section nav).
- Page-header strip: breadcrumb + judul H1 bilingual + (form/detail) Nomor Induk mono + badge status di kanan.

### 10.3 Section Nav (scrollspy)

- Kartu border; item = dot + label; **item aktif** = highlight `--bg-subtle` + border-left `--primary` + dot terisi. Reveal-on-scroll opsional (nonaktif bila `prefers-reduced-motion`).

### 10.4 Section Card

- `--bg-surface` + border 1px + radius-lg + padding 16–24. Judul **H2 bilingual** (ID + `jp` abu kecil) + counter kanan opsional (mis. “2 / maks 5”).

### 10.5 Baris Field

- **Form**: label di atas (12px, `--text-secondary`) + kontrol tinggi 36px; grid 2 kolom desktop, 1 kolom untuk field panjang; wajib = penanda `*` merah; error = teks merah + border merah di bawah.
- **Read-only (detail)**: baris **label → value** 2 kolom; tanpa input; berkas = tautan “Lihat berkas/dokumen”.

### 10.6 Badge Status

- Pill `--radius-full`, `text-xs`, **ikon + teks + warna** sesuai peta §3 (per mesin status). Dot varian opsional.

### 10.7 Action Bar (sticky bottom)

- Kiri: hint status ringkas. Kanan: tombol **Secondary (outline)** + **Primary (solid)**; aksi destruktif = `--danger`. Untuk 5 trigger step-up → munculkan **modal step-up** (§6.5), selain itu approval rutin tanpa modal.

### 10.8 Blok Repeatable

- Kartu ber-border + tombol hapus (ikon) pojok kanan-atas + tombol “+ Tambah … (maks N)” di bawah; hint jumlah “n / maks N” di header sub-bagian.

### 10.9 Inline Request Data Baru (Select master-data Kelas-2)

- Tautan kecil “+ Tidak ada di daftar? Ajukan data baru” di bawah Select; expand mini-form (Label ID + Label 日本語 + alasan opsional) + tombol Ajukan/Batal; setelah diajukan tampil chip warning “Menunggu Super Admin”. **Tidak** pada enum hardcode.

### 10.10 Foto / Dokumen Peserta

- Foto: dropzone upload R2 ≤5MB + progress + signed URL 15 menit.
- Dokumen Peserta: repeatable card berisi Select Jenis Dokumen, input URL Google Drive privat, dan Catatan opsional. Tampilkan hint permission Drive privat; tanpa badge envelope, tanpa upload dokumen, tanpa signed URL dokumen.
- Aksi “Lihat dokumen” mencatat `IDENTITY_DOC_VIEWED`, yang berarti aplikasi membuka/mengungkap link—bukan bukti file dibaca di Drive.

### 10.11 Banner Cek-Kemiripan

- Banner **warning (amber)**, non-blok, daftar kandidat mirip (nama + DOB + kewarganegaraan + skor) + tombol “Lanjutkan”.

### 10.12 Tabel (halaman list)

- Header sticky `--bg-subtle`; baris 40px + hover; **sort/filter hanya kolom whitelist**; kolom angka/tanggal rata-kanan + tabular-nums; kolom status = Badge; footer **pagination 25/halaman** (‹ Prev / nomor / Next › + total).

### 10.13 Cross-cutting

- **Modal step-up** (§6.5) hanya 5 trigger; **dropdown notifikasi** polling ≤60 dtk (§6.6); **toast + banner konflik 409** (§6.8); **pending overlay** “Menunggu Persetujuan …” (§6.9); **empty state** ringkas + ikon; fokus keyboard terlihat di semua kontrol.

---

*Status: LOCKED v1.1 — Batch B 2026-07-14 (tema Enterprise-netral, diadaptasi Kakehashi; disetujui user 2026-06-30). Aturan tetap: tiap prompt Stitch menyuntik [DESIGN.md](http://DESIGN.md) ini SECARA UTUH + memakai nama field dari DATABASE_SCHEMA untuk daftar field. Lanjut ke ANCHOR [5c] (Kandidat Form). Selaras PRD v0.3.14 + STATUS_STATE_MACHINE + ROLES + ARCHITECTURE D8/D9.*
