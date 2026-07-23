---
title: "ROLES_AND_PERMISSIONS"
status: "FINAL"
source_notion_title: "ROLES_AND_PERMISSIONS"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# ROLES_AND_PERMISSIONS

> [!NOTE]
> **ROLES_AND_**[**PERMISSIONS.md**](ROLES_AND_PERMISSIONS.md)** — Kakehashi.** Buku aturan kanonik "siapa boleh melakukan apa". Acuan untuk MODULE_AUTH, MODULE_GUEST_ACCESS, dan kontrol akses semua modul. **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.** Jika konflik, PRD berlaku. Skema tabel → DATABASE_SCHEMA; transisi status → STATUS_STATE_MACHINE.
>
**Status: FINAL v1.0 — disetujui user 2026-06-29.**
## 0. Cakupan & Dependency
- **In-scope:** 6 peran + deskripsi, matriks izin per modul, aturan scope/kepemilikan, pemisahan tugas (SoD), pemetaan 2FA & step-up, pemodelan aktor Tamu.
- **Out-of-scope (file lain):** skema tabel (DATABASE_SCHEMA), transisi status (STATUS_STATE_MACHINE), detail token tamu (MODULE_GUEST_ACCESS), implementasi login (MODULE_AUTH).
- **Dependency hulu (final):** PRD v0.3, PROJECT_OVERVIEW, GLOSSARY, DECISIONS_LOG, ARCHITECTURE.
## 1. Prinsip Desain Akses
1. **Least privilege** — tiap peran hanya diberi izin seperlunya untuk tugasnya.
2. **Separation of Duties (maker–checker)** — pembuat aksi ≠ penyetuju aksi (PRD §7.4). Kombinasi peran yang melanggar ini diblokir sistem (lihat §7).
3. **Defense-in-depth** — 2FA untuk peran berisiko + step-up re-auth untuk aksi sensitif/irreversible.
4. **Role hardcode** — 6 tipe peran tetap; Super Admin hanya *assign/unassign*, tidak membuat tipe peran baru (PRD §4.2).
5. **Aktor non-user** — Tamu bukan akun internal; diisolasi dari RBAC internal (GLOSSARY §1).
## 2. Keputusan Enforcement (disetujui user 2026-06-29)
<table header-row="true">
<tr>
<td>Kode</td>
<td>Keputusan</td>
<td>Pilihan</td>
</tr>
<tr>
<td>D-R1</td>
<td>Mekanisme enforcement</td>
<td>**Hybrid**: paket role-assignment + Policy untuk aturan scope/kepemilikan</td>
</tr>
<tr>
<td>D-R2</td>
<td>Granularitas izin</td>
<td>**Permission bernama** (di-seed, dipetakan ke 6 role hardcode)</td>
</tr>
<tr>
<td>D-R3</td>
<td>2FA & step-up</td>
<td>**2FA login first-party**  • **step-up re-auth custom** (password + TOTP ulang per aksi)</td>
</tr>
<tr>
<td>D-R4</td>
<td>Scope kepemilikan</td>
<td>**Global untuk MVP**  • jejak `dibuat_oleh` / `disetujui_oleh`; scoping ketat ditunda post-MVP</td>
</tr>
<tr>
<td>D-R5</td>
<td>Multi-role & SoD</td>
<td>**Hard-block** kombinasi yang melanggar SoD; default 1 user = 1 role</td>
</tr>
<tr>
<td>D-R6</td>
<td>2FA 2 role Maker</td>
<td>**Ikuti PRD** (opsional untuk Staf Input & Asisten Manajer); wajib-untuk-semua hanya catatan keamanan opsional</td>
</tr>
<tr>
<td>D-R7</td>
<td>Aktor Tamu</td>
<td>**Aktor/guard token terpisah**, hanya baca read-model GuestCandidateView</td>
</tr>
<tr>
<td>GAP-R4</td>
<td>Akses log audit</td>
<td>**Hanya Super Admin** lihat log audit pusat; role lain cukup riwayat di dalam kontainer</td>
</tr>
<tr>
<td>GAP-R5</td>
<td>Penyetuju berhalangan</td>
<td>**Tanpa fitur pendelegasian di MVP**; Super Admin assign/unassign role secara manual bila perlu</td>
</tr>
</table>
## 3. Tabel Verifikasi Teknologi (diverifikasi live 2026-06-29)
<table header-row="true">
<tr>
<td>Komponen</td>
<td>Versi</td>
<td>Status</td>
<td>Catatan</td>
</tr>
<tr>
<td>spatie/laravel-permission</td>
<td>8.1.0 (27 Jun 2026)</td>
<td>✅ Aktif, kompatibel Laravel 13</td>
<td>Role di-seed hardcode; permission bernama. Pin `^8.1`.</td>
</tr>
<tr>
<td>Laravel Fortify (2FA TOTP)</td>
<td>bawaan Laravel 13.x</td>
<td>✅ Aktif (first-party)</td>
<td>TOTP RFC 6238 bawaan + backup codes. **Tidak** native step-up per-aksi.</td>
</tr>
<tr>
<td>Step-up re-auth</td>
<td>pola custom (Fortify + verifikasi ulang)</td>
<td>⚠️ \[PERLU VERIFIKASI saat implementasi\]</td>
<td>Detail teknis → MODULE_AUTH.</td>
</tr>
<tr>
<td>pragmarx/google2fa-laravel</td>
<td>3.0.1 (core 9.0.0)</td>
<td>✅ Aktif (alternatif)</td>
<td>Cadangan bila butuh kontrol granular di luar Fortify.</td>
</tr>
</table>
> Tidak ada perubahan versi mayor vs TECH_VERSION_SEED. Hanya minor spatie 8.0.0 → 8.1.0.
## 4. Daftar 6 Peran (hardcode MVP — PRD §4.1)
<table header-row="true">
<tr>
<td>Peran</td>
<td>Untuk siapa</td>
<td>Peran inti</td>
<td>Modul</td>
<td>Wajib 2FA?</td>
</tr>
<tr>
<td>**Staf Input**</td>
<td>Operator data internal</td>
<td>Maker modul Kandidat: input data kandidat</td>
<td>Kandidat</td>
<td>Tidak (opsional)</td>
</tr>
<tr>
<td>**Approver Kandidat**</td>
<td>Validator data internal</td>
<td>Checker modul Kandidat: setuju/tolak data (tanpa edit)</td>
<td>Kandidat</td>
<td>**Ya**</td>
</tr>
<tr>
<td>**Asisten Manajer**</td>
<td>Eksekutor operasional</td>
<td>Maker Wawancara & Penempatan: semua aksi eksekusi</td>
<td>Wawancara, Penempatan</td>
<td>Tidak (opsional)</td>
</tr>
<tr>
<td>**Manajer Job**</td>
<td>Penyetuju operasional</td>
<td>Pure Checker Wawancara & Penempatan</td>
<td>Wawancara, Penempatan</td>
<td>**Ya**</td>
</tr>
<tr>
<td>**Super Admin**</td>
<td>Administrator sistem</td>
<td>Kelola akun, lookup/master, lihat audit; read-only operasional</td>
<td>Auth, Lookup, Audit</td>
<td>**Ya**</td>
</tr>
<tr>
<td>**Tamu**</td>
<td>Perusahaan Jepang (eksternal)</td>
<td>Lihat daftar peserta wawancara, read-only, via link bertoken — **bukan akun**</td>
<td>Guest Access</td>
<td>— (bukan akun)</td>
</tr>
</table>
## 5. Matriks Izin per Modul
> Legenda: ✅ = diizinkan · — = tidak · 👁️ = read-only · 🔒 = memicu step-up re-auth · ⏳ = aksi menghasilkan pending request (status agregat belum berubah sampai disetujui).
### 5.1 Modul Kandidat (PRD §5.2, §6.2)
<table header-row="true">
<tr>
<td>Aksi / sumber daya</td>
<td>Staf Input</td>
<td>Approver Kandidat</td>
<td>Asisten Manajer</td>
<td>Manajer Job</td>
<td>Super Admin</td>
</tr>
<tr>
<td>Input kandidat baru + submit</td>
<td>✅</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Revisi data ditolak + submit ulang</td>
<td>✅</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Update data disetujui (buat draft revisi)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui data kandidat</td>
<td>—</td>
<td>✅</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Tolak data kandidat (catatan wajib)</td>
<td>—</td>
<td>✅</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Lihat data kandidat</td>
<td>✅</td>
<td>👁️</td>
<td>—</td>
<td>—</td>
<td>👁️</td>
</tr>
<tr>
<td>Anonimisasi PII kandidat</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>✅ 🔒</td>
</tr>
</table>
> **Catatan PRD:** §7.9 menyatakan anonimisasi PII butuh Super Admin **+ step-up re-auth**, di luar 4 kategori Lampiran D. Lihat catatan inkonsistensi minor di §9. Prosedur detail → DATA_RETENTION_AND_PRIVACY.
### 5.2 Modul Wawancara / "Jobs" (PRD §5.3, §6.3)
<table header-row="true">
<tr>
<td>Aksi / sumber daya</td>
<td>Asisten Manajer</td>
<td>Manajer Job</td>
<td>Super Admin</td>
</tr>
<tr>
<td>Buat kontainer (simpan Draft / submit)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Batalkan kontainer (Draft / Menunggu Approval)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui / tolak kontainer</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Tarik kandidat ke kontainer (bulk)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Update status partisipasi (jalur alami)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Keluarkan kandidat (jalur paksa)</td>
<td>✅ ⏳</td>
<td>✅ 🔒 (approve)</td>
<td>—</td>
</tr>
<tr>
<td>Minta tutup kontainer</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui penutupan kontainer (irreversible)</td>
<td>—</td>
<td>✅ 🔒</td>
<td>—</td>
</tr>
<tr>
<td>Buat link tamu (request)</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui link tamu (token digenerate)</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Lihat kontainer & daftar kandidat</td>
<td>✅</td>
<td>👁️</td>
<td>👁️</td>
</tr>
</table>
### 5.3 Modul Penempatan (PRD §5.4, §6.4)
<table header-row="true">
<tr>
<td>Aksi / sumber daya</td>
<td>Asisten Manajer</td>
<td>Manajer Job</td>
<td>Super Admin</td>
</tr>
<tr>
<td>Buat kontainer (Draft / submit / batalkan)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui / tolak kontainer</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Kirim kandidat (batch normal, request)</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui batch normal (atomik)</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Tambah kandidat langsung — Force-Majeur (request)</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui Force-Majeur</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Status → Selesai Kontrak (langsung efektif)</td>
<td>✅</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Mengundurkan Diri (request)</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui Mengundurkan Diri</td>
<td>—</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Keluarkan kandidat (jalur paksa, request)</td>
<td>✅ ⏳</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Setujui Pengeluaran penempatan</td>
<td>—</td>
<td>✅ 🔒</td>
<td>—</td>
</tr>
<tr>
<td>Lihat kontainer penempatan</td>
<td>✅</td>
<td>👁️</td>
<td>👁️</td>
</tr>
</table>
### 5.4 Domain Auth / Kelola Akun (PRD §4.2, §6.1) — Super Admin
<table header-row="true">
<tr>
<td>Aksi</td>
<td>Super Admin</td>
<td>Lainnya</td>
</tr>
<tr>
<td>Buat akun user baru</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Assign / unassign role (yang sudah ada)</td>
<td>✅ 🔒</td>
<td>—</td>
</tr>
<tr>
<td>Nonaktifkan akun user</td>
<td>✅ 🔒</td>
<td>—</td>
</tr>
<tr>
<td>Lihat daftar user & status 2FA</td>
<td>✅</td>
<td>—</td>
</tr>
<tr>
<td>Membuat / menghapus **tipe** role</td>
<td>❌ (hardcode — tidak ada role yang boleh)</td>
<td>❌</td>
</tr>
</table>
### 5.5 Domain Lookup & Master Perusahaan (PRD §5.1, §7.8)
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
<td>Setujui / kelola master perusahaan</td>
<td>—</td>
<td>—</td>
<td>✅ 🔒</td>
</tr>
</table>
### 5.6 Audit Log (PRD §5.1, Lampiran A) & Akses Tamu
<table header-row="true">
<tr>
<td>Aksi</td>
<td>Super Admin</td>
<td>Manajer Job</td>
<td>Asisten Manajer</td>
<td>Approver Kandidat</td>
<td>Staf Input</td>
<td>Tamu</td>
</tr>
<tr>
<td>Lihat log audit pusat</td>
<td>👁️</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
</tr>
<tr>
<td>Lihat read-model GuestCandidateView</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>—</td>
<td>👁️ (via link bertoken)</td>
</tr>
</table>
> **GAP-R4 (diputuskan):** hanya Super Admin yang melihat log audit pusat. Konteks operasional untuk role lain dipenuhi lewat riwayat perubahan di dalam kontainer (bukan log audit pusat).
## 6. Aturan Scope / Kepemilikan (D-R4)
- **MVP = akses global per peran.** Asisten Manajer dapat beraksi di semua kontainer; Manajer Job dapat menyetujui semua kontainer. Alasan: tim kecil + risiko bottleneck approval (PRD §10).
- **Jejak akuntabilitas tetap wajib:** setiap entitas menyimpan `dibuat_oleh` dan `disetujui_oleh` (audit Lampiran A). Akuntabilitas dijaga lewat pencatatan, bukan pembatasan scope.
- **Post-MVP (catatan):** scoping kepemilikan per-job/per-penempatan dapat ditambahkan tanpa mengubah model peran — cukup tambah aturan Policy.
- **Super Admin** read-only di semua modul operasional — tidak pernah menjadi Maker/Checker aksi operasional (PRD §4.2).
## 7. Separation of Duties — Kombinasi Peran yang Diblokir (D-R5)
Default MVP: **1 user = 1 role**. Bila rangkap diizinkan administratif, sistem **menolak keras** kombinasi berikut karena melanggar maker–checker (PRD §7.4):
<table header-row="true">
<tr>
<td>Kombinasi dilarang</td>
<td>Alasan</td>
</tr>
<tr>
<td>Staf Input **+** Approver Kandidat</td>
<td>Maker = Checker data kandidat (input lalu menyetujui sendiri)</td>
</tr>
<tr>
<td>Asisten Manajer **+** Manajer Job</td>
<td>Maker = Checker aksi Wawancara/Penempatan</td>
</tr>
<tr>
<td>Super Admin **+** peran operasional manapun</td>
<td>Super Admin harus tetap read-only operasional + pengawas audit/akun</td>
</tr>
<tr>
<td>Peran internal manapun **+** Tamu</td>
<td>Tamu bukan akun internal (GLOSSARY §1)</td>
</tr>
</table>
> Kombinasi yang tidak melanggar SoD (mis. Staf Input + Asisten Manajer) secara teknis boleh, tetapi **tetap tidak disarankan** di MVP.
## 8. Pemetaan 2FA & Step-up Re-Auth
### 8.1 Kewajiban 2FA login (TOTP) — PRD §4.5
<table header-row="true">
<tr>
<td>Peran</td>
<td>2FA TOTP</td>
</tr>
<tr>
<td>Approver Kandidat</td>
<td>**Wajib**</td>
</tr>
<tr>
<td>Manajer Job</td>
<td>**Wajib**</td>
</tr>
<tr>
<td>Super Admin</td>
<td>**Wajib**</td>
</tr>
<tr>
<td>Staf Input</td>
<td>Opsional (D-R6)</td>
</tr>
<tr>
<td>Asisten Manajer</td>
<td>Opsional (D-R6)</td>
</tr>
<tr>
<td>Tamu</td>
<td>Tidak berlaku (bukan akun)</td>
</tr>
</table>
> Catatan keamanan opsional (D-R6): mewajibkan 2FA untuk Staf Input & Asisten Manajer meningkatkan keamanan, namun di luar mandat PRD — keputusan user. MVP mengikuti PRD.
### 8.2 Aksi pemicu step-up re-auth (password + TOTP ulang) — PRD §4.6, Lampiran D
<table header-row="true">
<tr>
<td>#</td>
<td>Aksi</td>
<td>Pelaku (role)</td>
<td>Catatan</td>
</tr>
<tr>
<td>1</td>
<td>Ubah role / nonaktifkan akun user</td>
<td>Super Admin</td>
<td>—</td>
</tr>
<tr>
<td>2</td>
<td>Setujui penutupan kontainer wawancara (irreversible)</td>
<td>Manajer Job</td>
<td>Saat approve, bukan saat Maker submit</td>
</tr>
<tr>
<td>3</td>
<td>Setujui pengeluaran kandidat (Wawancara & Penempatan)</td>
<td>Manajer Job</td>
<td>Alasan dua lapis (Maker + Checker)</td>
</tr>
<tr>
<td>4</td>
<td>Kelola lookup/config + master perusahaan</td>
<td>Super Admin</td>
<td>Termasuk setujui request lookup/perusahaan</td>
</tr>
<tr>
<td>(5)</td>
<td>Anonimisasi PII kandidat</td>
<td>Super Admin</td>
<td>Per PRD §7.9 — lihat catatan §9</td>
</tr>
</table>
> **Penting:** semua pemicu step-up dieksekusi oleh role yang **wajib 2FA**, sehingga model konsisten. Approval rutin (setuju/tolak kandidat, kontainer baru, link tamu, batch kirim, force-majeur, mengundurkan diri penempatan) **TIDAK** memicu step-up — cukup 2FA login + sesi aktif.
## 9. Catatan Inkonsistensi PRD (untuk revisi PRD berikutnya, bukan keputusan file ini)
- **Step-up #5 (anonimisasi PII):** Lampiran D mendaftar 4 kategori step-up, tetapi §7.9 menambahkan anonimisasi PII sebagai aksi ber-step-up. Bukan kontradiksi (aksi berbeda), namun sebaiknya Lampiran D diperjelas agar mencantumkan 5 pemicu. **Rekomendasi:** perlakukan anonimisasi sebagai pemicu step-up (sudah tercermin di §5.1 & §8.2).
## 10. Pemodelan Aktor Tamu (D-R7)
- Tamu = **aktor token terpisah**, di luar tabel role internal (GLOSSARY §1; PRD §4.3).
- Satu-satunya izin: **membaca** read-model `GuestCandidateView` untuk **satu** kontainer yang dirujuk token (Lampiran C).
- Tidak punya: login akun, akses lintas-kontainer, kemampuan menulis apa pun.
- Detail siklus token (generate, kadaluarsa, kode tambahan) → MODULE_GUEST_ACCESS.
## 11. Definisi Selesai (checklist FINAL)
- [x] 6 peran hardcode, TOTP wajib 3 role, step-up 4 (+1 §7.9) — selaras PRD v0.3 & GLOSSARY.
- [x] Matriks izin lengkap per modul, tak ambigu.
- [x] Scope, SoD, 2FA/step-up, aktor Tamu terdefinisi.
- [x] Tech terverifikasi (2026-06-29).
- [x] **Approval user eksplisit** (2026-06-29) → STATUS: FINAL; entri DECISIONS_LOG ditambahkan.
---
*Status: FINAL v1.0 — disetujui user 2026-06-29. Selaras PRD Kakehashi v0.3 + PROJECT_OVERVIEW + GLOSSARY + DECISIONS_LOG.*
