---
title: "BUSINESS_RULES"
status: "FINAL"
source_notion_title: "BUSINESS_RULES"
exported_at: "2026-07-28"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# BUSINESS_RULES

> [!NOTE]
> **BUSINESS_**[**RULES.md**](BUSINESS_RULES.md) — Aturan bisnis Kakehashi (Kelompok 1 · Fondasi)
> **Status:** FINAL (disetujui 2026-06-29)
> **Sumber kebenaran tunggal & tertinggi:** PRD_Kakehashi_v0_3. Dependency final: GLOSSARY, STATUS_STATE_MACHINE. Keputusan lintas-file: DECISIONS_LOG.
> **Tanggal:** 2026-06-29 (Asia/Jakarta) · **Persona:** Business Analyst
>
## 0. Konvensi & Prinsip
- **Format ID aturan:** `BR-<KATEGORI>-NN` (atomik & testable: satu kondisi → satu aksi terukur).
- **Prioritas konflik:** PRD v0.3 > STATUS_STATE_MACHINE / GLOSSARY (final) > DECISIONS_LOG. Bila ambigu → ditandai `[GAP PRD]` / `[ASUMSI]`.
- **Kode HTTP standar (selaras ARCHITECTURE):** validasi gagal → `422`; akses ditolak → `403`; konflik konkurensi → `409`; resource kadaluarsa (link tamu) → `410`.
- **Cakupan file ini:** ATURAN bisnis. Transisi status detail dirujuk ke STATUS_STATE_MACHINE; skema tabel dirujuk ke DATABASE_SCHEMA — keduanya **tidak** diulang di sini.
- Tanda `[→FILE]` = dampak/handoff ke file lain. `[ASUMSI]` = perlu konfirmasi. `[PERLU VERIFIKASI]` = belum bisa dicek live (tidak ada di file ini).
---
## 1. APPROVAL & ANTI DOUBLE-APPROVAL (Maker–Checker)
Rujukan: PRD §6.1, §6.3, §7.10 (konkurensi/§P2); GLOSSARY (Maker–Checker = istilah kanonik).
**BR-APV-01 — Pemisahan Maker–Checker**
- **Kondisi:** Aktor yang membuat/menyunting entitas (Maker) sama dengan aktor yang menyetujui (Checker).
- **Aksi:** Tolak approval.
- **Pesan:** `APV_SELF: Pembuat/penyunting tidak boleh menyetujui pekerjaannya sendiri.`
**BR-APV-02 — Pemetaan kewenangan approval**
- **Kondisi:** Permintaan approval atas suatu entitas.
- **Aksi:** Entitas **Kandidat** hanya boleh disetujui/ditolak oleh **Approver Kandidat**; entitas **Wawancara** & **Penempatan** hanya oleh **Manajer Job**.
- **Pesan:** `APV_ROLE: Peran Anda tidak berwenang menyetujui entitas ini.`
- **Rujukan:** PRD §4 (peran), §6.
**BR-APV-03 — Checker tidak menyunting**
- **Kondisi:** Checker membuka entitas untuk ditinjau.
- **Aksi:** Checker hanya boleh `approve` atau `reject` + catatan; tidak boleh mengubah konten entitas.
- **Pesan:** `APV_NOEDIT: Checker tidak dapat menyunting konten; gunakan tolak + catatan.`
**BR-APV-04 — Catatan penolakan wajib**
- **Kondisi:** Aksi `reject` dijalankan.
- **Aksi:** Wajib ada catatan minimal 1 karakter non-whitespace setelah trim; jika kosong → tolak aksi.
- **Pesan:** `APV_NOTE: Catatan penolakan wajib diisi.`
**BR-APV-05 — Penolakan kontainer wawancara kembali ke Draft**
- **Kondisi:** Kontainer wawancara berstatus menunggu tinjauan ditolak Manajer Job.
- **Aksi:** Kontainer kembali ke status **Draft** (transisi detail dirujuk ke STATUS_STATE_MACHINE).
- **Rujukan:** PRD §6.3; \[→STATUS_STATE_MACHINE\].
**BR-APV-06 — Blokir resubmit tanpa perubahan**
- **Kondisi:** Entitas yang pernah ditolak dikirim ulang tanpa perubahan apa pun sejak penolakan terakhir.
- **Aksi:** Tolak submit.
- **Pesan:** `APV_NOCHANGE: Tidak ada perubahan sejak penolakan; lakukan revisi sebelum kirim ulang.`
**BR-APV-07 — Anti double-decision (verifikasi dalam transaksi)**
- **Kondisi:** Dua aksi keputusan atas `pending_request`, `lookup_request`, atau `company_request` yang sama berjalan nyaris bersamaan.
- **Aksi:** Status pada sumber keputusan yang berlaku harus diverifikasi masih `pending` **di dalam transaksi yang sama** sebelum komit; bila sudah tidak `pending`, batalkan aksi kedua.
- **Pesan:** `APV_DONE: Permintaan ini sudah diproses oleh aktor lain.` (HTTP `409`)
- **Rujukan:** PRD §7.10 (§P2).
**BR-APV-08 — Pending sebagai sumber keputusan Checker**
- **Kondisi:** Aksi approval domain selain `lookup_request` dan `company_request`.
- **Aksi:** Buat tepat satu `pending_request`; untuk submit Kandidat/kontainer, status `Menunggu*` + pending dibuat satu transaksi; untuk command sensitif status target tidak berubah hingga approve. Partial unique aktif per `(type,target_type,target_id)`. Payload snapshot wajib untuk Placement batch, Force-Majeur, expel, resign, cancel.
- **Pengecualian:** `lookup_request.status` dan `company_request.status` adalah sumber keputusan flow masing-masing. Keduanya tidak membuat `pending_request` dan tidak menambah tipe ke `PendingType`.
- **Fondasi bersama:** Kedua flow pengecualian tetap memakai BR-APV-01/04/07, RBAC, `StepUpService`, `AuditLogger`, `NotificationService`, transaksi/rollback, dan after-commit.
**BR-APV-09 — Step-up untuk aksi Checker sensitif**
- **Kondisi:** Approver Kandidat / Manajer Job menjalankan aksi approval.
- **Aksi:** Wajib lolos 2FA/step-up sesuai kebijakan peran.
- **Rujukan:** PRD §4, Lampiran D; \[→ROLES_AND_PERMISSIONS\].
---
## 1A. AVAILABILITY & TRANSFER OWNERSHIP
**BR-AVL-01 — Makna Tersedia**
- Kandidat `Tersedia` tidak memiliki participation Wawancara aktif dan tidak memiliki placement `Bekerja`.
**BR-AVL-02 — Makna Sedang Dipakai**
- Kandidat `Sedang Dipakai` memiliki tepat satu ikatan aktif. `Siap Dikirim` tetap `Sedang Dipakai`.
**BR-AVL-03 — Eligible batch Placement normal**
- Eligible bila source participation=`Siap Dikirim`, availability=`Sedang Dipakai`, source aktif milik kandidat yang sama, dan tidak ada placement `Bekerja`. Filter `Siap Dikirim + Tersedia` dilarang.
**BR-AVL-04 — Transfer atomik normal**
- Approval `PLACEMENT_BATCH` mengunci dan merevalidasi candidate+source; source→`Terkirim`; placement→`Bekerja`; availability tetap `Sedang Dipakai`. `markInUse()` tidak dipakai untuk flip availability pada jalur normal.
**BR-AVL-05 — Force-Majeur**
- Force-Majeur tetap dimulai dari `Tersedia + Disetujui`, lalu `markInUse()` mengubah availability→`Sedang Dipakai` saat approval.
## 2. SUB-FLOW FORCE-MAJEUR (Penempatan 2b)
Keputusan terkunci: **D1 — tanpa step-up re-auth.** Rujukan: PRD §6.4.
**BR-FM-01 — Prasyarat status kandidat sumber**
- **Kondisi:** Inisiasi penempatan jalur force-majeur.
- **Aksi:** Lanjut hanya bila kandidat sumber berstatus **Tersedia** dan **Disetujui**; jika tidak → tolak.
- **Pesan:** `FM_STATE: Kandidat harus berstatus Tersedia + Disetujui.`
**BR-FM-02 — Tidak terikat partisipasi sumber**
- **Kondisi:** Inisiasi force-majeur.
- **Aksi:** `ref_riwayat_partisipasi` wajib `null` (penempatan tidak berasal dari partisipasi sumber).
- **Pesan:** `FM_REF: Force-majeur tidak boleh terikat riwayat partisipasi.`
**BR-FM-03 — Alasan wajib**
- **Kondisi:** Eksekusi force-majeur.
- **Aksi:** Wajib ada alasan (≥1 karakter non-whitespace setelah trim).
- **Pesan:** `FM_REASON: Alasan force-majeur wajib diisi.`
**BR-FM-04 — Approval Manajer Job**
- **Kondisi:** Eksekusi force-majeur.
- **Aksi:** Wajib disetujui **Manajer Job**; peran lain ditolak.
- **Pesan:** `FM_APPROVER: Hanya Manajer Job yang dapat menyetujui force-majeur.`
**BR-FM-05 — Operasi atomik**
- **Kondisi:** Eksekusi force-majeur menyentuh >1 perubahan keadaan.
- **Aksi:** Seluruh operasi harus **all-or-nothing** dalam satu transaksi; gagal sebagian → rollback penuh.
- **Pesan:** `FM_ATOMIC: Operasi gagal sebagian dan dibatalkan seluruhnya.`
**BR-FM-06 — Audit dua lapis, tanpa step-up**
- **Kondisi:** Force-majeur berhasil.
- **Aksi:** Catat audit dua lapis `FORCE_MAJEUR_ADDED` (alasan + aktor + persetujuan). Step-up re-auth **tidak** diwajibkan (keputusan D1).
- **Rujukan:** PRD §6.4, Lampiran D. `[GAP PRD]` PRD §6.4 menyebut "gaya EXPEL" namun Lampiran D tak mendaftarkan force-majeur sebagai pemicu step-up → diselesaikan sebagai D1; usul koreksi PRD agar tegas. \[→DECISIONS_LOG\].
---
## 3. CEK-KEMIRIPAN (deteksi duplikat kandidat)
Keputusan terkunci: **A2** (nama latin + katakana) · **B1** (soft warning + konfirmasi). Rujukan: PRD §6.2, §8.1.
**BR-DUP-01 — Pemicu saat submit**
- **Kondisi:** Kandidat dikirim (submit), bukan saat pengetikan.
- **Aksi:** Jalankan cek-kemiripan sebelum menetapkan Nomor Induk.
- **Rujukan:** PRD §6.2. `[ASUMSI]` dijalankan saat submit (selaras titik generate Nomor Induk).
**BR-DUP-02 — Kriteria match**
- **Kondisi:** Membandingkan kandidat baru terhadap kandidat lain.
- **Aksi:** Tandai sebagai "mirip" bila **(kemiripan nama ≥ 0,4 pada nama latin ATAU nama katakana)** **DAN** tanggal lahir sama persis **DAN** kewarganegaraan sama persis.
- **Rujukan:** PRD §6.2.
**BR-DUP-03 — Normalisasi nama**
- **Kondisi:** Sebelum menghitung kemiripan.
- **Aksi:** Nama latin dinormalisasi: huruf kecil + trim + rapikan spasi ganda + buang tanda baca. Nama katakana: trim + rapikan spasi (tanpa pengecilan huruf).
- **Rujukan:** PRD §6.2 (rincian normalisasi = penajaman aturan, `[ASUMSI]`).
**BR-DUP-04 — Cakupan pembanding**
- **Kondisi:** Menentukan himpunan pembanding.
- **Aksi:** Sertakan data berstatus **Draft**; **kecualikan** kandidat yang sudah dianonimisasi (lihat §7).
- **Rujukan:** PRD §6.2.
**BR-DUP-05 — Perilaku saat match: peringatan, bukan blokir**
- **Kondisi:** Ditemukan ≥1 kandidat mirip.
- **Aksi:** Tampilkan peringatan + minta konfirmasi eksplisit; pengguna boleh melanjutkan. **Tidak** memblokir.
- **Pesan:** `DUP_WARN: Ditemukan {N} kandidat mirip (≥0,4 nama + tanggal lahir + kewarganegaraan sama). Konfirmasi untuk melanjutkan.`
- **Rujukan:** PRD §6.2, §8.1.
**BR-DUP-06 — Jejak override**
- **Kondisi:** Pengguna memilih lanjut meski ada peringatan duplikat.
- **Aksi:** Catat di audit: aktor, waktu, dan daftar kandidat mirip yang diabaikan.
- **Rujukan:** PRD §8.1.
**BR-DUP-07 — Ambang deterministik**
- **Kondisi:** Menghitung ambang kemiripan 0,4.
- **Aksi:** Gunakan perhitungan kemiripan **eksplisit** (`similarity(...) >= 0.4`), bukan ambang global yang dapat berubah antar-sesi — agar hasil konsisten & dapat diuji. \[→DATABASE_SCHEMA\] perlu indeks trigram (GIN).
- **Rujukan:** Tabel Verifikasi Teknologi (§9).
---
## 4. NOMOR INDUK KANDIDAT
Keputusan terkunci: **C1** (sequence per tahun, boleh ada gap) · **tahun = JST (Asia/Tokyo)**. Rujukan: PRD §6.2.
**BR-NIK-01 — Format**
- **Aksi:** Nomor Induk berformat `K-YYYY-NNNNN` (NNNNN = 5 digit dengan nol di depan).
**BR-NIK-02 — Titik generate**
- **Kondisi:** Kandidat di-**submit** pertama kali (transisi Draft → Menunggu Tinjauan-BARU).
- **Aksi:** Tetapkan Nomor Induk pada saat itu; sebelum submit, kandidat tidak punya Nomor Induk.
- **Rujukan:** PRD §6.2; \[→STATUS_STATE_MACHINE\].
**BR-NIK-03 — Penentuan tahun (YYYY)**
- **Aksi:** `YYYY` = tahun pada saat submit menurut zona waktu **Asia/Tokyo (JST)**.
- **Rujukan:** Keputusan user 2026-06-29.
**BR-NIK-04 — Penomoran per tahun**
- **Aksi:** `NNNNN` bersumber dari penghitung **per tahun** yang dimulai dari 1 setiap tahun JST baru.
**BR-NIK-05 — Keunikan & permanen**
- **Kondisi:** Penetapan Nomor Induk.
- **Aksi:** Nomor wajib unik (dijamin unique constraint), permanen, dan tidak pernah dipakai ulang—even bila kandidat dibatalkan/dihapus.
- **Pesan:** `NIK_DUP: Nomor Induk bentrok; pembuatan dibatalkan.` (HTTP `409`)
**BR-NIK-06 — Gap diterima**
- **Aksi:** Nomor boleh tidak berurutan rapat (gap) akibat submit yang dibatalkan; gap **bukan** kesalahan dan tidak perlu diisi ulang.
**BR-NIK-07 — Batas atas tahunan**
- **Kondisi:** `NNNNN` melewati 99999 dalam satu tahun.
- **Aksi:** Tolak dengan error eksplisit (di luar skenario volume realistis 500–3.000/tahun).
- **Pesan:** `NIK_OVERFLOW: Kuota nomor tahun {YYYY} habis; hubungi admin.`
---
## 4A. DRAFT & REVISION KANDIDAT
**BR-CAN-01 — Draft belum disubmit**
- Save awal kandidat/revision memakai status `Draft`, `nomor_induk=null`, tidak masuk antrian, dan belum memiliki pending approval.
**BR-CAN-02 — Submit kandidat baru**
- Validasi lengkap + similarity + assign NIK + status `Menunggu Tinjauan-BARU` + pending `CANDIDATE_NEW` dilakukan satu transaksi.
**BR-CAN-03 — Satu revision aktif**
- Maksimum satu revision berstatus `Draft`, `Menunggu Tinjauan-REVISI`, atau `Ditolak` yang sedang dikerjakan per main candidate; partial unique digunakan untuk status Draft/menunggu aktif.
**BR-CAN-04 — Revision snapshot aggregate**
- Revision menyimpan snapshot field mutable + seluruh child collections; NIK revision null dan tampilan memakai NIK main.
**BR-CAN-05 — Merge revision atomik**
- Approve mengganti field mutable + seluruh child collections main dalam satu transaksi. NIK, availability, dan operational history main tidak berubah; revision→`Diterapkan`.
**BR-CAN-06 — Soft-delete deferred**
- Soft-delete/restore Kandidat tidak memiliki route, button, atau permission aktif pada MVP. `deleted_at` dan event terkait reserved/deferred.
## 5. TARGET PESERTA DITERIMA (informatif)
Keputusan terkunci: informatif + soft warning, **tidak** memblokir. Rujukan: PRD §5.3, §7.10 (§P2).
**BR-TGT-01 — Peringatan informatif**
- **Kondisi:** Jumlah peserta diterima mendekati atau melebihi target Job.
- **Aksi:** Tampilkan peringatan informatif; **tidak** memblokir penerimaan/penempatan.
- **Pesan:** `TARGET_WARN: Peserta diterima ({n}) mencapai/melebihi target ({target}). Lanjut tetap diizinkan.`
**BR-TGT-02 — Tidak ada penegakan keras**
- **Aksi:** Tidak boleh ada aturan yang mengubah target peserta menjadi blokir keras.
- **Rujukan:** PRD §5.3.
---
## 6. KONKURENSI
Rujukan: PRD §7.10 (§P2).
**BR-CON-01 — Optimistic locking**
- **Kondisi:** Pembaruan agregat mutable (mis. kontainer wawancara, penempatan).
- **Aksi:** Gunakan kolom `version`; `UPDATE ... WHERE version = <nilai-yang-dibaca>`. Bila 0 baris terpengaruh → konflik.
**BR-CON-02 — Penanganan konflik**
- **Kondisi:** Konflik versi terdeteksi (BR-CON-01).
- **Aksi:** Tolak pembaruan; minta muat ulang.
- **Pesan:** `CONFLICT: Data telah diubah pihak lain; muat ulang lalu coba lagi.` (HTTP `409`)
**BR-CON-03 — Pessimistic lock terbatas**
- **Kondisi:** Operasi **bulk pull** wawancara.
- **Aksi:** Boleh memakai penguncian baris (`FOR UPDATE`) hanya untuk skenario bulk pull ini; tidak untuk operasi umum.
**BR-CON-04 — Anti double-decision**
- **Aksi:** Lihat **BR-APV-07** (revalidasi status sumber keputusan dalam transaksi).
**BR-CON-05 — Unique participation aktif**
- Database menolak lebih dari satu participation aktif per kandidat melalui partial unique untuk `Menunggu Wawancara`, `Lulus`, `Proses Dokumen`, `Siap Dikirim`.
**BR-CON-06 — Unique revision aktif**
- Database menolak lebih dari satu revision Draft/menunggu aktif per main candidate.
**BR-CON-07 — Unique pending aktif**
- Untuk approval domain yang memakai `pending_request`, database menolak lebih dari satu pending aktif per `(type,target_type,target_id)`.
---
## 7. PRIVASI PII
Keputusan terkunci: **E1** — definisikan mekanisme; periode retensi **DITETAPKAN** = 5 thn aktif + anonimisasi ≤ 1 thn (PRD §11 v0.3.3). Rujukan: PRD §7.9, §7.10.
**BR-PII-01 — Pemisahan catatan operasional vs PII**
- **Aksi:** Catatan operasional (jejak proses/audit) bersifat **permanen**; data pribadi (PII) tunduk pada retensi + anonimisasi.
**BR-PII-02 — Tanpa hard delete operasional**
- **Kondisi:** Permintaan hapus.
- **Aksi:** Catatan operasional tidak boleh dihapus permanen (no hard delete).
- **Pesan:** `PII_NOHARD: Catatan operasional tidak dapat dihapus permanen.`
**BR-PII-03 — Anonimisasi via soft tombstone**
- **Kondisi:** PII perlu "dihapus".
- **Aksi:** Ganti nilai PII dengan placeholder anonim (soft tombstone); integritas referensial & catatan operasional tetap utuh.
**BR-PII-04 — Kewenangan anonimisasi (step-up ke-5)**
- **Kondisi:** Eksekusi anonimisasi PII.
- **Aksi:** Hanya **Super Admin** + **step-up re-auth** (pemicu step-up ke-5 di luar 4 kategori Lampiran D).
- **Pesan:** `PII_AUTH: Hanya Super Admin dengan verifikasi ulang yang dapat menganonimkan PII.`
- **Rujukan:** PRD §7.9; \[→ROLES_AND_PERMISSIONS\].
**BR-PII-05 — Audit anonimisasi**
- **Aksi:** Catat audit `CANDIDATE_ANONYMIZED` (aktor, waktu, dasar).
**BR-PII-06 — Periode retensi (DITETAPKAN)**
- **Aksi:** Periode retensi PII = **5 tahun** aktif sejak keterikatan terakhir kandidat, lalu **anonimisasi** (soft tombstone) dalam tenggang **≤ 1 tahun**. Disetujui user 2026-06-29 & dikunci di PRD §11 (v0.3.3). Rincian jadwal & prosedur → DATA_RETENTION_AND_PRIVACY (pending konfirmasi DPO).
- **Rujukan:** PRD §11 (v0.3.3).
**BR-PII-07 — Akses & edit PII**
- **Kondisi:** Akses/penyuntingan PII.
- **Aksi:** Tunduk pada kewenangan peran; PII kandidat yang sudah dianonimisasi tidak boleh diedit atau dipulihkan.
- **Pesan:** `PII_FROZEN: PII yang telah dianonimkan tidak dapat diubah/dipulihkan.`
**BR-PII-08 — Eligibility anonimisasi**
- **Kondisi:** Eksekusi anonimisasi.
- **Aksi:** Tolak jika ada participation Wawancara aktif, placement `Bekerja`, pending request terbuka, atau revision Draft/menunggu aktif; availability wajib `Tersedia`. Revalidasi seluruh guard dalam transaksi tepat sebelum tombstone. Basis `right_to_erasure` tidak melewati guard; proses aktif harus ditutup sah dahulu.
- **Pesan:** `PII_ACTIVE: Kandidat masih memiliki proses atau permintaan aktif; selesaikan dahulu sebelum anonimisasi.`
---
## 8. ATURAN i18n
Rujukan: PRD §7.x i18n; GLOSSARY.
**BR-I18N-01 — Nama Jepang perusahaan wajib**
- **Kondisi:** Membuat/menyunting data perusahaan (Kumiai/TSK).
- **Aksi:** `nama_ja` **wajib**; `nama_romaji` dan `nama_id` opsional.
- **Pesan:** `I18N_NAMEJA: Nama Jepang (nama_ja) perusahaan wajib diisi.`
**BR-I18N-02 — Simpan enum kanonik, render glyph**
- **Aksi:** Nilai pilihan (status/enum) disimpan sebagai kode kanonik; tampilan memakai `label_id`/`label_ja` sesuai bahasa. Jangan simpan teks tampilan sebagai sumber kebenaran.
- **Rujukan:** GLOSSARY.
---
## 8A. MANAJEMEN AKUN USER — GUARD (Super Admin)
Rujukan: PRD §4.2, §6.1, Lampiran D. Semua guard **ditegakkan server-side di dalam transaksi** (bukan sekadar menonaktifkan tombol UI). Aksi sensitif (ubah role / nonaktifkan) tetap memicu step-up re-auth (Lampiran D butir 1).
**BR-USR-01 — Larangan menghapus Super Admin aktif terakhir**
- **Kondisi:** Menonaktifkan akun ATAU mengubah/menurunkan peran user yang berperan Super Admin.
- **Aksi:** Tolak bila operasi menyebabkan jumlah user dengan peran Super Admin berstatus `Aktif` menjadi 0. Hitungan diverifikasi di dalam transaksi (row lock) untuk mencegah race dua admin bersamaan.
- **Pesan:** `USR_LAST_SUPERADMIN: Minimal harus ada satu Super Admin aktif; aksi ini akan menghapus Super Admin terakhir.` (HTTP `422`)
**BR-USR-02 — Larangan menonaktifkan diri sendiri**
- **Kondisi:** Aktor menonaktifkan akunnya sendiri (`actor_id = target_user_id`).
- **Aksi:** Tolak aksi.
- **Pesan:** `USR_SELF_DEACTIVATE: Anda tidak dapat menonaktifkan akun Anda sendiri.` (HTTP `422`)
**BR-USR-03 — Larangan mengubah peran diri sendiri**
- **Kondisi:** Aktor mengubah peran akunnya sendiri (`actor_id = target_user_id`).
- **Aksi:** Tolak aksi; perubahan peran diri sendiri harus dilakukan Super Admin lain (mencegah eskalasi/penurunan hak tak sengaja).
- **Pesan:** `USR_SELF_ROLE: Anda tidak dapat mengubah peran Anda sendiri; minta Super Admin lain.` (HTTP `422`)
**BR-USR-04 — Reset password oleh admin**
- **Kondisi:** Super Admin menerbitkan password sementara untuk user lain (aksi Reset Password S4).
- **Aksi:** Set password sementara + `must_change_password = TRUE` (user wajib ganti saat login berikutnya). Audit `PASSWORD_RESET_BY_ADMIN` — dibedakan dari `PASSWORD_CHANGED` (ganti oleh user sendiri). Tidak boleh mereset password diri sendiri via jalur ini.
- **Pesan (target = diri sendiri):** `USR_SELF_RESET: Gunakan ganti password biasa untuk akun Anda sendiri.` (HTTP `422`)
**BR-USR-05 — Reaktivasi akun**
- **Kondisi:** Super Admin mengaktifkan kembali akun `Nonaktif`.
- **Aksi:** Status akun → `Aktif`; audit `USER_REACTIVATED`. Tidak dibatasi guard last-Super-Admin.
---
## 9. TABEL VERIFIKASI TEKNOLOGI
Diverifikasi live ke dokumentasi resmi PostgreSQL 18 pada 2026-06-29. Versi mayor dipertahankan dari TECH_VERSION_SEED (PostgreSQL 18.x).
<table fit-page-width="true" header-row="true">
<tr>
<td>Komponen</td>
<td>Klaim seed/PRD</td>
<td>Hasil verifikasi</td>
<td>Status</td>
<td>Implikasi aturan</td>
</tr>
<tr>
<td>pg_trgm `similarity()`</td>
<td>skor 0–1, ambang 0,4</td>
<td>Benar; `similarity(a,b)` real 0–1. Operator `%` memakai ambang global (default 0,3).</td>
<td>✅</td>
<td>BR-DUP-07: pakai `similarity() >= 0.4` eksplisit, bukan operator `%`/ambang global.</td>
</tr>
<tr>
<td>Indeks GIN trigram</td>
<td>perlu GIN trigram index</td>
<td>Benar; `USING GIN (col gin_trgm_ops)`, cocok read-heavy.</td>
<td>✅</td>
<td>\[→DATABASE_SCHEMA\] indeks pada nama ternormalisasi.</td>
</tr>
<tr>
<td>Sequence `nextval()`</td>
<td>generate Nomor Induk atomik</td>
<td>Atomik; sesi konkuren dijamin nilai distinct.</td>
<td>✅</td>
<td>BR-NIK: aman tanpa Redis.</td>
</tr>
<tr>
<td>Sifat gap sequence</td>
<td>—</td>
<td>Tidak transaksional; nilai bisa berlubang saat rollback/crash.</td>
<td>⚠️</td>
<td>BR-NIK-06: gap diterima; unique constraint sebagai jaring pengaman.</td>
</tr>
<tr>
<td>Optimistic lock (`version`)</td>
<td>kolom version, konflik→409</td>
<td>Pola standar, tanpa extension.</td>
<td>✅</td>
<td>BR-CON-01/02.</td>
</tr>
<tr>
<td>`SELECT ... FOR UPDATE`</td>
<td>pessimistic utk bulk pull</td>
<td>Didukung penuh.</td>
<td>✅</td>
<td>BR-CON-03 (khusus bulk pull).</td>
</tr>
</table>
Tidak ada item `[PERLU VERIFIKASI]` tersisa untuk cakupan file ini.
---
## 10. Daftar GAP / ASUMSI Terbuka
- **`[SELESAI]`**** Force-majeur & step-up** — diselesaikan sebagai D1 (tanpa step-up); **sudah diterapkan ke PRD v0.3.2** (§6.4 Sub-flow 2b + Lampiran D).
- **`[SELESAI]`**** Periode retensi PII** — DITETAPKAN: 5 thn aktif + anonimisasi ≤ 1 thn; **dikunci di PRD §11 v0.3.3** (BR-PII-06). Angka final tetap perlu konfirmasi DPO.
- **`[ASUMSI]`**** Cek-kemiripan dijalankan saat submit** (BR-DUP-01).
- **`[ASUMSI]`**** Rincian normalisasi nama** (BR-DUP-03) sebagai penajaman PRD §6.2.
---
## 11. Daftar Aturan yang Harus Diuji Modul (handoff QA)
- **Approval/Anti double-approval:** BR-APV-01, 04, 06, 07 (uji race condition dua approver).
- **Force-majeur:** BR-FM-01, 03, 04, 05 (uji rollback atomik saat gagal sebagian).
- **Cek-kemiripan:** BR-DUP-02, 03, 05 (uji ambang 0,4 latin & katakana; soft warning bukan blokir).
- **Nomor Induk:** BR-NIK-02, 03 (boundary tengah malam JST), 05 (keunikan), 06 (gap).
- **Konkurensi:** BR-CON-01/02 (konflik versi → 409), BR-CON-03 (bulk pull).
- **PII:** BR-PII-02, 03, 04, 07 (otorisasi Super Admin + step-up; soft tombstone tak bisa dipulihkan).
- **i18n:** BR-I18N-01 (nama_ja wajib).
- **Manajemen user (§8A):** BR-USR-01 (last-Super-Admin — uji race dua admin bersamaan), BR-USR-02/03 (self-action nonaktif/ubah peran), BR-USR-04 (reset admin vs self), BR-USR-05 (reaktivasi).
