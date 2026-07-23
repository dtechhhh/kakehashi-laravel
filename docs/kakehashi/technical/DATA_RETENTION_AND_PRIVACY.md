---
title: "DATA_RETENTION_AND_PRIVACY"
status: "FINAL v1.2"
source_notion_title: "DATA_RETENTION_AND_PRIVACY"
exported_at: "2026-07-15"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# DATA_RETENTION_AND_PRIVACY

> [!NOTE]
> **DATA_RETENTION_AND_**[**PRIVACY.md**](DATA_RETENTION_AND_PRIVACY.md)** — Kakehashi (Kelompok 4 · Operasional).** Status: **FINAL v1.2 — Batch B aligned**. Dokumen ini menetapkan kebijakan praktis retensi & privasi data kandidat untuk MVP Kakehashi, selaras PRD_Kakehashi_v0_3_13 dan prinsip proyek (solo developer, tidak over-engineering).
>
## 0. Ringkasan 5 Aturan (inti kebijakan)
1. **Kumpulkan seperlunya untuk operasional.** Jangan menambah data “sekadar berjaga-jaga”.
2. **Akses mengikuti RBAC yang sudah disepakati.** Tidak ada field-level permission di MVP.
3. **Akses pihak eksternal (Tamu) hanya whitelist G2/G3.** Data sensitif tidak pernah dibuka ke Tamu.
4. **Retensi PII: 5 tahun aktif + anonimisasi ≤ 1 tahun.** Tidak ada hard delete operasional.
5. **Anonimisasi & penghapusan file dilakukan manual-terkendali oleh Super Admin + step-up.** Bukti lewat audit log.
---
## 1. Ruang Lingkup
Dokumen ini mencakup:
- **Kebijakan** data apa yang diproses, siapa boleh mengakses, dan berapa lama disimpan.
- Retensi PII kandidat dan prinsip anonimisasi.
- Eksposur data kandidat ke pihak eksternal via link bertoken (Tamu).
Dokumen ini **tidak** mencakup detail implementasi enkripsi, konfigurasi server, atau prosedur backup (ada di dokumen Kelompok 4 lainnya).
---
## 2. Prinsip yang Dipegang
1. **Nonaktifkan bukan hapus** (catatan operasional tidak di-hard delete).
2. **Manual lebih aman daripada otomasi rapuh** (retensi/anonimisasi MVP dilakukan dengan proses manual yang terdokumentasi).
3. **Solo developer** (kebijakan harus bisa dieksekusi oleh satu orang).
4. **Tidak over-engineering** (tidak menambah sistem consent engine/legal-hold module/field-level permission).
5. **Data sensitif pekerja migran adalah prioritas keamanan tertinggi** (akses eksternal dibatasi ketat).
---
## 3. Data yang Diproses (kategori, bukan perubahan skema)
Dokumen ini **tidak mengubah struktur database**. Semua field yang sudah disepakati tetap boleh digunakan untuk kebutuhan operasional, termasuk:
- Identitas & kontak kandidat
- Foto kandidat
- Pendidikan & riwayat kerja
- Data kesehatan (mis. buta warna, riwayat penyakit/operasi, catatan kesehatan)
- Data keluarga & kontak darurat
- Data imigrasi (paspor, Zairyu, visa)
- Dokumen peserta (mis. KTP/KK/Ijazah/Zairyu Card/sertifikat) melalui `candidate_document`
**Catatan NIK:**
- Sistem **tidak menyimpan NIK sebagai field database**.
- Jika KTP/KK disimpan sebagai dokumen, organisasi menerima bahwa dokumen tersebut dapat memuat NIK.
- NIK di dalam dokumen **tidak boleh disalin** menjadi field database tanpa keputusan baru.
---
## 4. Akses Data (berdasarkan RBAC yang sudah FINAL)
### 4.1 Internal
Akses mengikuti ROLES_AND_PERMISSIONS (FINAL). Secara ringkas:
- **Staf Input**: mengisi & memperbarui data kandidat sesuai workflow.
- **Approver Kandidat**: melihat data kandidat untuk review/approval (tanpa edit).
- **Super Admin**: fungsi admin (akun/lookup/audit) + menjalankan anonimisasi PII (aksi sensitif).
- **Role operasional lain**: tidak mendapat akses penuh ke profil kandidat di luar kebutuhan modulnya.
Tidak ada mekanisme **field-level permission** dan tidak ada “break-glass UI” pada MVP.
### 4.2 Eksternal (Tamu)
Tamu adalah **aktor non-user** (bukan akun) yang hanya membaca melalui link bertoken.
Kebijakan eksposur (sudah dikunci di v0.3.11):
- **G2 (daftar) — pseudonim:** identifier = **Nomor Induk**; tanpa nama & foto.
- **G3 (detail) — diperluas:** boleh menampilkan **Nama (alphabet + katakana)**, **Foto**, **Riwayat Kerja penuh**, **Riwayat Pendidikan penuh**.
- Selain whitelist G2/G3, semua field lain **HIDE** dari Tamu.
---
## 5. Consent / Persetujuan (versi MVP, sederhana)
Untuk MVP, persetujuan dicatat secara sederhana (mis. formulir tertulis/terekam, atau dokumen persetujuan yang disimpan bersama berkas kandidat).
Minimal harus mencakup bahwa kandidat memahami dan menyetujui:
- data diproses untuk keperluan rekrutmen, seleksi, administrasi, imigrasi, dan penempatan;
- **Nama + Foto + riwayat pendidikan/kerja** dapat dibagikan kepada perusahaan Jepang untuk proses seleksi melalui mekanisme Tamu;
- data dapat ditransfer antara Indonesia dan Jepang dalam konteks operasional penempatan.
MVP **tidak** membangun consent management system atau tabel persetujuan khusus.
---
## 6. Retensi PII (keputusan kebijakan FINAL)
### 6.1 Kebijakan inti
- Simpan PII kandidat **5 tahun** aktif sejak keterikatan terakhir kandidat.
- Setelah itu, lakukan **anonimisasi** (soft tombstone) dalam tenggang **≤ 1 tahun**.
- **Tidak ada hard delete** untuk catatan operasional.
### 6.2 Cara eksekusi MVP (manual-terkendali)
Retensi tidak diotomasi kompleks.
- Review kandidat lama dilakukan berkala (mis. 1–2 kali per tahun).
- Super Admin mengeksekusi anonimisasi untuk kandidat yang memenuhi syarat.
- **Eligibility wajib:** availability `Tersedia`; tidak ada participation Wawancara aktif, placement `Bekerja`, pending request terbuka, atau revision Draft/menunggu aktif. Seluruh guard direvalidasi dalam transaksi tepat sebelum tombstone.
- Basis `right_to_erasure` tidak melewati guard operasional; proses aktif diselesaikan secara sah terlebih dahulu.
---
## 7. Anonimisasi PII (aksi sensitif)
### 7.1 Kewenangan
- Anonimisasi PII dilakukan oleh **Super Admin** dan **wajib step-up re-auth**.
- Event dicatat dalam audit sebagai `CANDIDATE_ANONYMIZED`.
### 7.2 Prinsip anonimisasi
- Soft-delete/restore Kandidat **tidak diekspos pada MVP**; `deleted_at` dan event terkait hanya reserved/deferred. Anonimisasi tetap mekanisme penghapusan PII formal.
- `candidate.pii_anonymized_at` diisi (permanen, irreversible).
- Setelah dianonimkan, data kandidat **tidak boleh dipulihkan**.
- Kandidat yang sudah dianonimkan:
	- **dikeluarkan seluruhnya** dari G2/G3; direct detail request ditolak generik;
	- tidak boleh dipakai untuk proses operasional baru.
### 7.3 Penghapusan file & permission Drive (manual)
- Foto kandidat (R2) dihapus saat anonimisasi.
- Dokumen Drive harus private dan hanya dibagikan ke akun/grup staf berwenang; permission direview saat onboarding/offboarding dan berkala.
- URL dokumen di aplikasi dikosongkan sesuai kebutuhan.
- File pada Google Drive dihapus/ditata ulang secara manual sesuai prosedur internal.
Catatan: menghapus URL di aplikasi tidak otomatis menghapus file Drive. `IDENTITY_DOC_VIEWED` hanya membuktikan aplikasi mengungkap/membuka link kepada aktor berwenang, bukan file benar-benar dibaca di Drive.
---
## 8. Retensi Log Akses Tamu
- `guest_access_log.ip` adalah data pribadi.
- Kebijakan MVP: simpan log akses Tamu untuk kebutuhan forensik terbatas, kemudian lakukan pembersihan berkala.
- Rekomendasi angka sederhana: **180 hari**.
---
## 9. Audit Eksposur Data ke Tamu
- Keberhasilan buka link Tamu dicatat `GUEST_ACCESS`.
- Pembukaan detail kandidat (G3) dicatat `GUEST_DETAIL_VIEWED` untuk akuntabilitas eksposur PII.
---
## 10. Definisi Selesai (FINAL v1.2)
- [x] Kebijakan retensi 5 tahun + anonimisasi ≤ 1 tahun ditegaskan.
- [x] RBAC dipakai apa adanya (tanpa field-level permission).
- [x] Kebijakan eksposur Tamu G2/G3 dipertahankan.
- [x] Proses anonimisasi manual-terkendali oleh Super Admin + step-up + audit.
- [x] Tidak ada perubahan skema database dari dokumen ini.
