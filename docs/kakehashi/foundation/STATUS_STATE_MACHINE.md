---
title: "STATUS_STATE_MACHINE"
status: "FINAL"
source_notion_title: "STATUS_STATE_MACHINE"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# STATUS_STATE_MACHINE

> [!NOTE]
> **STATUS_STATE_**[**MACHINE.md**](STATUS_STATE_MACHINE.md)** — Kakehashi.** Sumber tunggal status, transisi sah, guard, aktor, & efek samping seluruh mesin status. **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.14; dependency final = GLOSSARY.** Jika konflik, PRD berlaku. Transisi rinci milik file ini; aturan bisnis non-status (cek-kemiripan, nomor induk, dst) milik BUSINESS_RULES; bentuk kolom/constraint milik DATABASE_SCHEMA.
>
## 0. Konvensi & cara baca
- Tiap mesin: **tabel transisi** (Dari → Ke \| Pemicu/aksi \| Aktor \| Guard/prasyarat \| Efek samping/audit) + **status terminal** + **transisi TERLARANG eksplisit** + diagram teks.
- **Approval ✔/✘** & **Step-up ✔/✘** adalah atribut transisi (Lamp. D PRD), bukan status. **`pending_request`**** adalah sumber keputusan Checker untuk seluruh approval domain.** Untuk submit kandidat/kontainer, pending + status `Menunggu*` dibuat dalam satu transaksi; untuk command sensitif, status agregat tetap dan pending tampil sebagai overlay.
- Status bernama sama lintas mesin (`Mengundurkan Diri`/`Dikeluarkan`) WAJIB ber-qualifier `status_wawancara.X` vs `status_penempatan.X` (GLOSSARY §4).
- Penanda: **\[ASUMSI\]**, **\[GAP-PRD\]**, **\[→BUSINESS_RULES\]**, **\[→DATABASE_SCHEMA\]**.
- Penulisan ketersediaan kandidat (`Tersedia`/`Sedang Dipakai`) **hanya** via service publik modul Kandidat `markAvailable()` / `markInUse()` (§7.1, §9.7) — bukan UPDATE lintas-modul.
- **Presentasi (→ **`**DESIGN.md**`** §3):** `Tersedia` dan `Sedang Dipakai` WAJIB dibedakan warnanya — **Tersedia = success (hijau)**, **Sedang Dipakai = neutral/zinc (abu)**, bukan teal/info. Otoritas token warna = `DESIGN.md`; dicatat di sini demi konsistensi ketersediaan lintas layar (disetujui user 2026-07-01).
### Keputusan GAP yang mengikat file ini (disetujui user 2026-06-29)
<table header-row="true">
<tr>
<td>GAP</td>
<td>Keputusan</td>
</tr>
<tr>
<td>GAP-1</td>
<td>`status_wawancara` "status aktif" untuk transisi keluar = \{Menunggu Wawancara, Lulus, Proses Dokumen, Siap Dikirim\}; **Terkirim dikecualikan** (terminal).</td>
</tr>
<tr>
<td>GAP-2</td>
<td>Link tamu ditolak → **tidak ada record ****`guest_link`**; penolakan hidup di `pending_request`, token tak pernah lahir.</td>
</tr>
<tr>
<td>GAP-3</td>
<td>Pembekuan partisipasi saat kontainer wawancara `Ditutup` = **guard turunan** (blok semua transisi `status_wawancara`), availability→`Tersedia`. Tanpa status baru.</td>
</tr>
<tr>
<td>GAP-4</td>
<td>Kontainer Penempatan `Aktif` → `Dibatalkan` **hanya** bila belum pernah ada `placement_participant` (escape ber-approval). **RESOLVED** di PRD §7.6/B.2 (v0.3.3+; tetap di v0.3.14).</td>
</tr>
<tr>
<td>GAP-5</td>
<td>`Siap Dikirim → Terkirim` **hanya** efek samping approval batch kirim Penempatan (cross-module); tak ada aksi manual `Terkirim` di modul Wawancara.</td>
</tr>
<tr>
<td>GAP-6</td>
<td>Baris draft-revisi kandidat yang disetujui → status terminal internal **`Diterapkan`** (merged); data utama tetap `Disetujui`.</td>
</tr>
</table>
---
## 1. Mesin: KONTAINER WAWANCARA (5 status)
**Status:** `Draft` · `Menunggu Approval` · `Aktif` · `Ditutup` · `Dibatalkan` (§7.5, Lamp. B.1)
**Terminal:** `Ditutup` (irreversible), `Dibatalkan`
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
<td>Step-up</td>
</tr>
<tr>
<td>(baru)</td>
<td>Draft</td>
<td>Simpan sebagai Draft</td>
<td>Asisten Manajer</td>
<td>form minimal valid</td>
<td>buat record, `version=1`; audit `IC_CREATED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Draft</td>
<td>Menunggu Approval</td>
<td>Submit</td>
<td>Asisten Manajer</td>
<td>form lengkap; jika resubmit: **ada perubahan** dari versi ditolak</td>
<td>dalam satu transaksi: status→`Menunggu Approval`  • buat pending `IC_CREATE`; notif Manajer Job; audit `IC_SUBMITTED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Draft</td>
<td>Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>belum pernah `Aktif`</td>
<td>audit `IC_CANCELLED` (terminal)</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Aktif</td>
<td>Setujui</td>
<td>Manajer Job</td>
<td>`pending_request` masih `pending` (cek dalam transaksi); `version` cocok</td>
<td>status→`Aktif`; siap terima kandidat; notif pembuat; audit `IC_APPROVED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Draft</td>
<td>Tolak (catatan wajib)</td>
<td>Manajer Job</td>
<td>catatan_tolak ≠ null; pending masih pending</td>
<td>notif pembuat; audit `IC_REJECTED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Dibatalkan</td>
<td>Batalkan sebelum diputuskan</td>
<td>Asisten Manajer (pembuat)</td>
<td>pending masih pending</td>
<td>batalkan `pending_request`; audit `IC_CANCELLED` (terminal)</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Aktif</td>
<td>Ditutup</td>
<td>Tutup Kontainer disetujui</td>
<td>Manajer Job (approve) ← request Asisten Manajer</td>
<td>`pending_request` CLOSE pending; **alasan maker + catatan checker**; irreversible</td>
<td>freeze partisipasi non-terminal (lihat §3 GAP-3); `markAvailable()` semua kandidat aktif → `Tersedia`; audit `IC_CLOSE_REQUESTED`→`IC_CLOSED`</td>
<td>✔</td>
<td>✔</td>
</tr>
</table>
> Overlay *Menunggu Persetujuan Penutupan* = `pending_request`, **bukan status**; status tetap `Aktif` sampai disetujui.
**Transisi TERLARANG (eksplisit):** `Aktif→Dibatalkan` · `Aktif→Draft` · `Aktif→Menunggu Approval` · `Draft→Aktif` (wajib lewat Menunggu Approval) · `Menunggu Approval→Ditutup` (tutup hanya dari Aktif) · `Ditutup→*` · `Dibatalkan→*`.
```javascript
(baru) → Draft ⇄ Menunggu Approval → Aktif → Ditutup[T]
           │            │                 (Apr+Step-up, irreversible)
           └→ Dibatalkan[T] ←┘
   (Menunggu Approval→Draft = tolak; →Aktif = setuju)
```
---
## 2. Mesin: KONTAINER PENEMPATAN (5 status)
**Status:** `Draft` · `Menunggu Approval` · `Aktif` · `Arsip` · `Dibatalkan` (§7.6, Lamp. B.2)
**Terminal:** `Arsip` (irreversible), `Dibatalkan`
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
<td>Step-up</td>
</tr>
<tr>
<td>(baru)</td>
<td>Draft</td>
<td>Simpan sebagai Draft</td>
<td>Asisten Manajer</td>
<td>perusahaan tujuan wajib (1 kontainer = 1 perusahaan)</td>
<td>`version=1`; audit `PC_CREATED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Draft</td>
<td>Menunggu Approval</td>
<td>Submit</td>
<td>Asisten Manajer</td>
<td>form lengkap; jika resubmit: ada perubahan</td>
<td>dalam satu transaksi: status→`Menunggu Approval`  • buat pending `PC_CREATE`; notif; audit `PC_SUBMITTED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Draft</td>
<td>Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>belum pernah `Aktif`</td>
<td>audit `PC_CANCELLED` (terminal)</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Dibatalkan</td>
<td>Batalkan sebelum diputuskan</td>
<td>Asisten Manajer (pembuat)</td>
<td>pending masih pending</td>
<td>batalkan pending; audit `PC_CANCELLED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Aktif</td>
<td>Setujui</td>
<td>Manajer Job</td>
<td>pending pending; `version` cocok</td>
<td>status→`Aktif`; notif; audit `PC_APPROVED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Draft</td>
<td>Tolak (catatan wajib)</td>
<td>Manajer Job</td>
<td>catatan ≠ null</td>
<td>notif; audit `PC_REJECTED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Aktif</td>
<td>Arsip</td>
<td>**OTOMATIS**</td>
<td>Sistem</td>
<td>kandidat aktif (`status_penempatan.Bekerja`) terakhir → terminal; cek **setelah seluruh batch diproses** (anti arsip prematur); guard: pernah ada ≥1 `placement_participant`</td>
<td>status→`Arsip`; timestamp; audit `CONTAINER_ARCHIVED`</td>
<td>— (sistem)</td>
<td>✘</td>
</tr>
<tr>
<td>Aktif</td>
<td>Dibatalkan</td>
<td>Batalkan (escape) disetujui</td>
<td>Manajer Job (approve) ← request Asisten Manajer</td>
<td>**`COUNT(placement_participant)=0`** (belum pernah ada kandidat)</td>
<td>audit `PC_CANCELLED`. **GAP-4 RESOLVED** di PRD §7.6 + Lampiran B.2 (v0.3.3+).</td>
<td>✔</td>
<td>✘</td>
</tr>
</table>
**Transisi TERLARANG:** penutupan manual (tidak ada status `Ditutup`) · `Aktif→Arsip` selama masih ada kandidat `Bekerja` · `Aktif→Dibatalkan` bila sudah pernah ada kandidat (hanya escape `count=0`) · `Arsip→*` · `Dibatalkan→*` · ubah perusahaan tujuan setelah dibuat **\[→BUSINESS_RULES / →DATABASE_SCHEMA: immutable FK\]**.
```javascript
(baru) → Draft ⇄ Menunggu Approval → Aktif → Arsip[T] (otomatis, kandidat aktif terakhir → terminal)
           │            │              │
           └→ Dibatalkan[T] ←──────────┘ (escape: hanya bila count kandidat = 0, ber-approval; GAP-4 RESOLVED di PRD)
```
---
## 3. Mesin: `status_wawancara` (8 status — per partisipasi)
**Status:** `Menunggu Wawancara` · `Lulus` · `Proses Dokumen` · `Siap Dikirim` · `Terkirim` · `Tidak Lolos` · `Mengundurkan Diri` · `Dikeluarkan` (§7.2, Lamp. B.3)
**Terminal:** `Terkirim`, `Tidak Lolos`, `Mengundurkan Diri`, `Dikeluarkan`
**"Status aktif" (GAP-1):** \{`Menunggu Wawancara`, `Lulus`, `Proses Dokumen`, `Siap Dikirim`\} — sumber sah transisi keluar Tidak Lolos/Mengundurkan Diri/Dikeluarkan.
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
<td>Step-up</td>
</tr>
<tr>
<td>(baru)</td>
<td>Menunggu Wawancara</td>
<td>Tarik kandidat (pull)</td>
<td>Asisten Manajer</td>
<td>kandidat `Disetujui`+`Tersedia`; kontainer `Aktif`; `SELECT FOR UPDATE` saat validasi</td>
<td>buat `participation`; `markInUse()`→`Sedang Dipakai`; audit `CANDIDATE_PULLED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Wawancara</td>
<td>Lulus</td>
<td>Update status (maju ketat)</td>
<td>Asisten Manajer</td>
<td>kontainer `Aktif` (tidak `Ditutup`)</td>
<td>audit `PARTICIPATION_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Lulus</td>
<td>Proses Dokumen</td>
<td>Update status</td>
<td>Asisten Manajer</td>
<td>kontainer `Aktif`</td>
<td>audit `PARTICIPATION_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Proses Dokumen</td>
<td>Siap Dikirim</td>
<td>Update status</td>
<td>Asisten Manajer</td>
<td>kontainer `Aktif`</td>
<td>kandidat **siap ditarik** ke Penempatan (belum pindah); audit `PARTICIPATION_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Siap Dikirim</td>
<td>Terkirim</td>
<td>**Efek samping approval pending ****`PLACEMENT_BATCH`** (GAP-5)</td>
<td>Sistem (dipicu approval Manajer Job di modul Penempatan)</td>
<td>source participation=`Siap Dikirim`, availability=`Sedang Dipakai`, source milik kandidat yang sama, tanpa placement `Bekerja`; revalidasi+lock dalam transaksi</td>
<td>transfer ownership: source→`Terkirim`, buat placement `Bekerja`; availability tetap `Sedang Dipakai` dan `markInUse()` tidak dipakai untuk flip; audit `BATCH_SENT`  • `PARTICIPATION_STATUS_CHANGED`</td>
<td>✔ (di Penempatan)</td>
<td>✘</td>
</tr>
<tr>
<td>\{aktif\}</td>
<td>Tidak Lolos</td>
<td>Update terminal (jalur alami)</td>
<td>Asisten Manajer</td>
<td>status ∈ aktif; kontainer `Aktif`</td>
<td>`markAvailable()`→`Tersedia`; audit `PARTICIPATION_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>\{aktif\}</td>
<td>Mengundurkan Diri</td>
<td>Update terminal (jalur alami)</td>
<td>Asisten Manajer</td>
<td>status ∈ aktif; kontainer `Aktif`</td>
<td>`markAvailable()`→`Tersedia`; audit `PARTICIPATION_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>\{aktif\}</td>
<td>Dikeluarkan</td>
<td>Keluarkan kandidat (jalur paksa) disetujui</td>
<td>Manajer Job (approve) ← request Asisten Manajer</td>
<td>**alasan maker + catatan checker**; pending pending; status ∈ aktif</td>
<td>`markAvailable()`→`Tersedia`; audit `EXPEL_REQUESTED`→`EXPEL_APPROVED` (dua lapis)</td>
<td>✔</td>
<td>✔</td>
</tr>
</table>
**Freeze saat kontainer ****`Ditutup`**** (GAP-3):** jika kontainer wawancara = `Ditutup`, **semua transisi ****`status_wawancara`**** diblok** (guard turunan dari status kontainer — bukan status baru). Partisipasi non-terminal tetap di status terakhir; availability di-set `Tersedia` oleh transisi penutupan kontainer (§1).
**Transisi TERLARANG:** semua **rollback** (mis. `Lulus→Menunggu Wawancara`, `Proses Dokumen→Lulus`, `Siap Dikirim→Proses Dokumen`) · semua **loncat** maju (mis. `Menunggu Wawancara→Proses Dokumen/Siap Dikirim`) · `Terkirim→{Tidak Lolos/Mengundurkan Diri/Dikeluarkan}` (Terkirim terminal) · set `Terkirim` manual di modul Wawancara (GAP-5) · transisi apapun saat kontainer `Ditutup` (GAP-3) · dari terminal manapun → status lain.
> **\[→BUSINESS_RULES\]** kandidat yang kembali `Tersedia` dapat ikut proses baru lewat **baris partisipasi BARU** (mesin per-baris; baris lama tetap di status terminal/beku).
```javascript
Menunggu Wawancara → Lulus → Proses Dokumen → Siap Dikirim →(approval batch Penempatan)→ Terkirim[T]
     └──────────────┴──────────────┴───────────────┘
         ↘ (dari status aktif manapun)
            Tidak Lolos[T] · Mengundurkan Diri[T]        (jalur alami, tanpa approval)
            Dikeluarkan[T]  (jalur paksa: Apr+Step-up, alasan 2 lapis)
```
---
## 4. Mesin: `status_penempatan` (4 status — per placement_participant)
**Status:** `Bekerja` · `Selesai Kontrak` · `Mengundurkan Diri` · `Dikeluarkan` (§7.3, Lamp. B.4)
**Terminal:** `Selesai Kontrak`, `Mengundurkan Diri`, `Dikeluarkan` · satu-satunya status aktif = `Bekerja`
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
<td>Step-up</td>
</tr>
<tr>
<td>(baru, batch normal)</td>
<td>Bekerja</td>
<td>Approval batch kirim</td>
<td>Manajer Job ← request Asisten Manajer</td>
<td>source participation `Siap Dikirim`  • availability `Sedang Dipakai`; source aktif milik kandidat; tanpa placement `Bekerja`; pending `PLACEMENT_BATCH`; batch atomik; kontainer `Aktif`</td>
<td>buat `placement_participant` (`source_participation_id`=partisipasi asal); source→`Terkirim`; availability tetap `Sedang Dipakai` (tanpa flip `markInUse()`); audit `BATCH_SENT`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>(baru, Force-Majeur 2b)</td>
<td>Bekerja</td>
<td>Tambah Langsung disetujui</td>
<td>Manajer Job ← request Asisten Manajer</td>
<td>kandidat `Tersedia`+`Disetujui`; **alasan wajib**; `source_participation_id=null`; atomik</td>
<td>buat `placement_participant`; `markInUse()`→`Sedang Dipakai`; audit `FORCE_MAJEUR_ADDED` (dua lapis)</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Selesai Kontrak</td>
<td>Jalur 1 (langsung efektif)</td>
<td>Asisten Manajer</td>
<td>konfirmasi singkat</td>
<td>`markAvailable()`→`Tersedia`; **cek arsip otomatis** (setelah batch); audit `PLACEMENT_STATUS_CHANGED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Mengundurkan Diri</td>
<td>Jalur 2 disetujui</td>
<td>Manajer Job ← request Asisten Manajer</td>
<td>**alasan wajib**; pending pending (ada kontrak resmi)</td>
<td>`markAvailable()`→`Tersedia`; cek arsip; audit `RESIGN_REQUESTED`→`RESIGN_APPROVED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Bekerja</td>
<td>Dikeluarkan</td>
<td>Jalur 3 disetujui</td>
<td>Manajer Job ← request Asisten Manajer</td>
<td>**alasan dua lapis**; pending pending</td>
<td>`markAvailable()`→`Tersedia`; cek arsip; audit `PLACEMENT_EXPEL_REQUESTED`→`PLACEMENT_EXPEL_APPROVED`</td>
<td>✔</td>
<td>✔</td>
</tr>
</table>
> **Force-Majeur step-up — DITETAPKAN:** **tanpa step-up** (cukup approval Manajer Job + alasan wajib + audit `FORCE_MAJEUR_ADDED`). Dikunci PRD v0.3.2 Lampiran D + DECISIONS_LOG; bukan asumsi terbuka.
> Qualifier (GLOSSARY §4): `status_penempatan.Mengundurkan Diri`/`Dikeluarkan` ≠ konteks `status_wawancara`.
**Transisi TERLARANG:** `Selesai Kontrak/Mengundurkan Diri/Dikeluarkan→*` (terminal) · `Bekerja→Bekerja` · reaktivasi baris yang sama (kandidat `Tersedia` masuk via baris/kontainer baru) · membuat `Bekerja` tanpa approval.
```javascript
(batch normal | Force-Majeur, keduanya Apr) → Bekerja
     Bekerja → Selesai Kontrak[T]      (tanpa approval)
     Bekerja → Mengundurkan Diri[T]    (approval)
     Bekerja → Dikeluarkan[T]          (approval + step-up, alasan 2 lapis)
→ tiap terminal: markAvailable→Tersedia + cek arsip kontainer (setelah batch)
```
---
## 5. Mesin: STATUS APPROVAL KANDIDAT (4 + terminal internal draft)
**Status:** `Draft` · `Menunggu Tinjauan-BARU` · `Menunggu Tinjauan-REVISI` · `Disetujui` · `Ditolak` · (+ internal `Diterapkan` untuk revision, GAP-6) (§5.2, §6.2, Lamp. B.5)
Dua jenis baris: **baris utama** (data kandidat) & **baris draft-revisi** (FK ke utama).
### 5.1 Baris utama (kandidat baru)
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
</tr>
<tr>
<td>(baru)</td>
<td>Draft</td>
<td>Simpan draft</td>
<td>Staf Input</td>
<td>data minimal valid; `nomor_induk=null`</td>
<td>belum ada pending/antrian; audit `CANDIDATE_CREATED`</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Tinjauan-BARU</td>
<td>Disetujui</td>
<td>Setujui</td>
<td>Approver Kandidat</td>
<td>pending pending</td>
<td>data aktif operasional; ketersediaan awal `Tersedia` (via service); audit `CANDIDATE_APPROVED`</td>
<td>✔</td>
</tr>
<tr>
<td>Menunggu Tinjauan-BARU</td>
<td>Ditolak</td>
<td>Tolak (catatan wajib)</td>
<td>Approver Kandidat</td>
<td>catatan ≠ null</td>
<td>notif Staf Input; audit `CANDIDATE_REJECTED`</td>
<td>✔</td>
</tr>
<tr>
<td>Ditolak</td>
<td>Menunggu Tinjauan-REVISI</td>
<td>Submit ulang revisi</td>
<td>Staf Input</td>
<td>**ada perubahan** dari versi ditolak</td>
<td>audit `CANDIDATE_REVISION_SUBMITTED`</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Tinjauan-REVISI</td>
<td>Disetujui</td>
<td>Setujui</td>
<td>Approver Kandidat</td>
<td>pending pending</td>
<td>data aktif; audit `CANDIDATE_APPROVED`</td>
<td>✔</td>
</tr>
<tr>
<td>Menunggu Tinjauan-REVISI</td>
<td>Ditolak</td>
<td>Tolak (catatan wajib)</td>
<td>Approver Kandidat</td>
<td>catatan ≠ null</td>
<td>audit `CANDIDATE_REJECTED`</td>
<td>✔</td>
</tr>
</table>
**Transisi submit kandidat baru:** `Draft → Menunggu Tinjauan-BARU` oleh Staf Input; cek-kemiripan+validasi lengkap; assign NIK; buat pending `CANDIDATE_NEW` dalam transaksi yang sama; notif Approver; audit `CANDIDATE_SUBMITTED`.
### 5.2 Baris revision (update data yang sudah `Disetujui`)
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
</tr>
<tr>
<td>(baru, FK ke utama)</td>
<td>Draft</td>
<td>Buat revision snapshot</td>
<td>Staf Input</td>
<td>main `Disetujui`; tidak ada revision Draft/menunggu aktif lain</td>
<td>clone field mutable + child collections; `nomor_induk=null`; main tetap aktif; audit `CANDIDATE_UPDATED`</td>
<td>✘</td>
</tr>
<tr>
<td>Draft</td>
<td>Menunggu Tinjauan-REVISI</td>
<td>Submit revision</td>
<td>Staf Input</td>
<td>ada perubahan; buat pending `CANDIDATE_REVISION` dalam transaksi yang sama</td>
<td>notif Approver; audit `CANDIDATE_REVISION_SUBMITTED`</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Tinjauan-REVISI</td>
<td>**Diterapkan** (terminal internal, GAP-6)</td>
<td>Setujui</td>
<td>Approver Kandidat</td>
<td>pending pending</td>
<td>satu transaksi mengganti field mutable + seluruh child collections main; NIK, availability, operational history tidak berubah; revision→`Diterapkan`; main tetap `Disetujui`; audit `CANDIDATE_APPROVED`</td>
<td>✔</td>
</tr>
<tr>
<td>Menunggu Tinjauan-REVISI</td>
<td>Ditolak</td>
<td>Tolak (catatan wajib)</td>
<td>Approver Kandidat</td>
<td>catatan ≠ null</td>
<td>data utama **tidak berubah**; baris draft→`Ditolak` (dapat direvisi ulang)</td>
<td>✔</td>
</tr>
</table>
**Terminal:** `Diterapkan` (baris draft). `Disetujui` = stabil (dapat memunculkan baris draft baru — itu baris terpisah, bukan transisi keluar). `Ditolak` = non-terminal (revisable).
**Transisi TERLARANG:** Approver **mengedit data** (hanya setuju/tolak) · submit ulang **tanpa perubahan** · baris draft mulai di `Menunggu Tinjauan-BARU` (draft selalu mulai di REVISI) · `Disetujui→Ditolak` langsung (perubahan hanya via baris draft) · merge baris draft tanpa approval.
```javascript
Baris utama: (baru)→Draft→Menunggu Tinjauan-BARU →[setuju] Disetujui
                               └→[tolak] Ditolak →[revisi+ada perubahan] Menunggu Tinjauan-REVISI ⇄ (setuju/tolak)
Revision data Disetujui: (baru)→Draft→Menunggu Tinjauan-REVISI
     →[setuju] Diterapkan[T] (timpa utama; utama tetap Disetujui)
     →[tolak]  Ditolak (utama tak berubah; revisi ulang mungkin)
```
---
## 6. Mesin: STATUS LINK TAMU (3 status)
**Status:** `Menunggu Approval` · `Aktif` · `Kadaluarsa` (Lamp. B.6). Hanya untuk **kontainer Wawancara** (Penempatan tidak punya link tamu).
**Terminal:** `Kadaluarsa`
<table header-row="true">
<tr>
<td>Dari</td>
<td>Ke</td>
<td>Pemicu/aksi</td>
<td>Aktor</td>
<td>Guard/prasyarat</td>
<td>Efek samping / audit</td>
<td>Apr</td>
<td>Step-up</td>
</tr>
<tr>
<td>(baru)</td>
<td>Menunggu Approval</td>
<td>Buat Link Tamu</td>
<td>Asisten Manajer</td>
<td>kontainer wawancara `Aktif`; **masa berlaku wajib**</td>
<td>`pending_request`; audit `GUEST_LINK_REQUESTED`</td>
<td>✘</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>Aktif</td>
<td>Setujui</td>
<td>Manajer Job</td>
<td>pending pending</td>
<td>**generate token** acak panjang (1 token = 1 kontainer); kode tambahan opsional; audit `GUEST_LINK_APPROVED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Menunggu Approval</td>
<td>*(tidak ada record link)*</td>
<td>Tolak (catatan)</td>
<td>Manajer Job</td>
<td>—</td>
<td>**token tak lahir, ****`guest_link`**** tak dibuat** (GAP-2); penolakan di `pending_request`; audit `GUEST_LINK_REJECTED`</td>
<td>✔</td>
<td>✘</td>
</tr>
<tr>
<td>Aktif</td>
<td>Kadaluarsa</td>
<td>**OTOMATIS**</td>
<td>Sistem</td>
<td>masa berlaku habis **ATAU** kontainer wawancara `Ditutup`/`Dibatalkan`</td>
<td>akses dimatikan; audit (sistem)</td>
<td>—</td>
<td>✘</td>
</tr>
</table>
> Event akses tamu `GUEST_ACCESS` (token, IP, waktu, kontainer) dicatat tiap akses — bukan transisi status link. Satu kontainer boleh punya >1 link `Aktif`.
**Transisi TERLARANG:** `Kadaluarsa→Aktif` (tak ada reaktivasi; buat link baru) · generate token sebelum approval · link tamu pada kontainer Penempatan.
```javascript
(baru)→Menunggu Approval →[setuju] Aktif (token lahir) →[expiry | kontainer Ditutup/Dibatalkan] Kadaluarsa[T]
                          └→[tolak] (tanpa record link, token tak lahir)
```
---
## 7. Sub-flow Force-Majeur (Penempatan, jalur 2b) — transisi khusus
Lihat baris **(baru, Force-Majeur 2b)→****`Bekerja`** di §4. Ringkas sebagai transisi khusus:
- **Pemicu:** "Tambah Langsung" / "Force-Majeur" (aksi terpisah dari batch normal, label & ikon khas).
- **Aktor:** Asisten Manajer (maker) → Manajer Job (approve).
- **Guard:** kandidat `Tersedia` + `Disetujui` (tanpa syarat `Siap Dikirim`); **alasan WAJIB**; `source_participation_id = null`; operasi **ATOMIK** (satu transaksi DB menyentuh `placement_participants` + service publik Kandidat).
- **Efek:** `placement_participant` status `Bekerja`; `markInUse()`→`Sedang Dipakai`; audit `FORCE_MAJEUR_ADDED` (dua lapis).
- **Tanpa step-up** (ditetapkan; lihat catatan §4 + PRD Lampiran D).
---
## 8. Tabel Verifikasi Teknologi (enforcement) — live 2026-06-29
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi seed</td>
<td>Live (terverifikasi)</td>
<td>Status</td>
<td>Catatan</td>
</tr>
<tr>
<td>spatie/laravel-model-states</td>
<td>2.14.1</td>
<td>**2.14.1** (latest, 22 Apr 2026)</td>
<td>✅ aktif/terpelihara</td>
<td>`StateConfig::allowTransition()` untuk transisi sah; custom transition class untuk guard + efek samping atomik. Mendukung Laravel 13 / PHP 8.4.</td>
</tr>
<tr>
<td>PostgreSQL CHECK + kolom status</td>
<td>PG 18</td>
<td>PG 18.x</td>
<td>✅</td>
<td>Jaring pengaman DB (defense-in-depth) selaras §7.10.</td>
</tr>
<tr>
<td>Konkurensi (anti double-approval)</td>
<td>pola</td>
<td>n/a</td>
<td>✅</td>
<td>`version` optimistic + `SELECT FOR UPDATE` (pull bulk) + verifikasi `pending_request` masih `pending` dalam transaksi.</td>
</tr>
</table>
> **\[PERLU VERIFIKASI saat implementasi\]** pin minor terkini `spatie/laravel-model-states` + uji guard transition class di Laravel 13.x.
---
## 9. Daftar guard yang HARUS ditegakkan (handoff lintas-file)
**\[→BUSINESS_RULES\]**
1. Maker-Checker: Checker hanya setuju/tolak + catatan; penolakan wajib catatan.
2. Blok submit ulang bila tidak ada perubahan (kontainer wawancara/penempatan, revisi kandidat).
3. Cek-kemiripan & Nomor Induk (titik integrasi di transisi submit kandidat) — definisi di BUSINESS_RULES.
4. Alasan dua lapis: Dikeluarkan (wawancara & penempatan), Tutup Kontainer; alasan wajib: Force-Majeur, Mengundurkan Diri (penempatan).
5. Cek arsip otomatis penempatan dilakukan **setelah seluruh batch diproses**, bukan per-kandidat.
6. Kandidat `Tersedia` boleh masuk proses baru via baris partisipasi/placement baru.
**\[→DATABASE_SCHEMA\]**
1. Kolom status per mesin + CHECK constraint sesuai daftar status di file ini; Kandidat mencakup `Draft`; kolom `version` optimistic lock pada agregat mutable.
2. Partial unique satu participation Wawancara aktif per kandidat untuk `Menunggu Wawancara`/`Lulus`/`Proses Dokumen`/`Siap Dikirim`; partial unique satu revision Draft/menunggu aktif per main candidate; partial unique pending aktif per `(type,target_type,target_id)`.
3. `source_participation_id` nullable (null = force-majeur).
4. Penulisan ketersediaan kandidat hanya via service publik Kandidat (tanpa FK lintas-modul; §9.7).
5. Perusahaan tujuan kontainer penempatan = FK immutable setelah dibuat.
6. `guest_link` token unik acak, digenerate hanya saat approval; tak ada record untuk link ditolak (GAP-2).
7. Status terminal internal `Diterapkan` pada baris draft-revisi (GAP-6).
8. Escape `Aktif→Dibatalkan` penempatan ber-guard `count(placement_participant)=0` (GAP-4 RESOLVED di PRD).
---
## 10. GAP-PRD (status penutupan)
- **GAP-4 — RESOLVED:** escape `Aktif→Dibatalkan` (guard `count(placement_participant)=0`, ber-approval Manajer Job) sudah di PRD §7.6 & Lampiran B.2 sejak v0.3.3; tetap berlaku di v0.3.14.
- **Force-Majeur step-up — RESOLVED:** **tanpa step-up** (PRD v0.3.2 Lampiran D + §6.4 Sub-flow 2b). Bukan asumsi terbuka.
Tidak ada GAP-PRD terbuka tersisa dari mesin status untuk MVP.
*Status: FINAL v1.3 — hygiene pass 2026-07-14. Selaras PRD v0.3.14 + GLOSSARY + DECISIONS_LOG.*
