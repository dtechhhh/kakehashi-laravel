---
title: "MODULE_JOBS"
status: "FINAL"
source_notion_title: "MODULE_JOBS"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_JOBS

> [!NOTE]
> Status: **FINAL** · Domain: **Kontainer Wawancara (Modul Wawancara PRD = "Jobs")** · Selaras PRD_Kakehashi_v0.3.13 + GLOSSARY/ROLES/STATUS_STATE_MACHINE/BUSINESS_RULES/MODULE_LOOKUP_DATA (semua FINAL). Tech terverifikasi live 2026-06-29.
>
## 0. Tabel Verifikasi Teknologi
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
<td>Aktif (rilis 17 Mar 2026; PHP 8.3–8.5)</td>
<td>Tanpa breaking change dari 12; muat di VPS 2vCPU/2GB</td>
<td>[laravel.com/docs/13.x/releases](http://laravel.com/docs/13.x/releases)</td>
</tr>
<tr>
<td>spatie/laravel-model-states</td>
<td>2.14.1 (22 Apr 2026)</td>
<td>Aktif</td>
<td>Transition class kustom utk guard + efek samping atomik (audit, freeze partisipasi)</td>
<td>[spatie.be/docs/laravel-model-states/v2](http://spatie.be/docs/laravel-model-states/v2)</td>
</tr>
<tr>
<td>Filament / Livewire</td>
<td>Filament 5.x (≥5.3.5) · Livewire 4.x</td>
<td>Aktif</td>
<td>`Wizard`/`Step` di `Filament\Schemas\Components`; CVE-2026-33080 dipatch 5.3.5</td>
<td>[filamentphp.com/docs/5.x/schemas/wizards](http://filamentphp.com/docs/5.x/schemas/wizards)</td>
</tr>
<tr>
<td>Optimistic locking</td>
<td>Pola manual kolom `version` (tanpa paket)</td>
<td>Pola standar</td>
<td>`UPDATE ... WHERE version=:v`; 0 row → 409 (BR-CON-01/02)</td>
<td>[laravel.com/docs/13.x/eloquent](http://laravel.com/docs/13.x/eloquent)</td>
</tr>
</table>
Tidak ada perubahan versi mayor dari TECH_VERSION_SEED.
---
## 1. Scope
**Termasuk:** Siklus hidup Kontainer Wawancara end-to-end (Draft → Menunggu Approval → Aktif → Ditutup; Dibatalkan), entitas pendukung Perusahaan (master) & Posisi Pekerjaan (lookup), target peserta diterima (soft warning), workflow Maker–Checker (Asisten Manajer ↔ Manajer Job), penutupan ber-step-up, pembatalan, visibilitas Tamu (hanya kontainer Aktif), konkurensi optimistic-lock, dan audit.
**Tidak termasuk (delegasi ke modul lain):** data & approval kandidat (MODULE_CANDIDATES), pembuatan/approval link tamu & GuestCandidateView (MODULE_GUEST_ACCESS), kontainer & alur penempatan (MODULE_PLACEMENT), master lookup CRUD (MODULE_LOOKUP_DATA), autentikasi & mekanisme step-up (MODULE_AUTH).
**Pemetaan istilah:** "Job" = **Modul Wawancara PRD** (DECISIONS_LOG 2026-06-28, PROJECT_OVERVIEW). Atribut kontainer bersumber **PRD §5.3** (rujukan brief "§7 Tabel 7" adalah salah-rujuk — pola yang sama dikoreksi pada MODULE_CANDIDATES).
---
## 2. Domain Model
### 2.1 Kontainer Wawancara (`interview_container`)
Entitas inti. Atribut dari PRD §5.3:
<table header-row="true">
<tr>
<td>Atribut</td>
<td>Tipe</td>
<td>Wajib</td>
<td>Sumber/Catatan</td>
</tr>
<tr>
<td>`id`</td>
<td>bigint PK</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>`kode_kontainer`</td>
<td>string `VARCHAR(13)` UNIQUE</td>
<td>auto</td>
<td>Kode human-readable `W-YYYY-NNNNN`; di-assign saat submit pertama (Draft = NULL), immutable; counter `container_counter` per-tahun JST (BR-KODE)</td>
</tr>
<tr>
<td>`judul` / identifikasi</td>
<td>string</td>
<td>✔</td>
<td>Nama kontainer</td>
</tr>
<tr>
<td>`perusahaan_id`</td>
<td>FK → `perusahaan`</td>
<td>✔</td>
<td>Master perusahaan; tampil pakai `nama_ja`</td>
</tr>
<tr>
<td>`posisi_pekerjaan_id`</td>
<td>FK → `posisi_pekerjaan` (lookup)</td>
<td>✔</td>
<td>Lookup berkategori `bidang_pekerjaan_id` (MODULE_LOOKUP_DATA)</td>
</tr>
<tr>
<td>`jenis_wawancara`</td>
<td>enum `OFFLINE`/`ONLINE`</td>
<td>✔</td>
<td>Backed enum PHP 8.4 (D-L3)</td>
</tr>
<tr>
<td>`jenis_visa_id`</td>
<td>FK → lookup `jenis_visa`</td>
<td>✔</td>
<td>Lookup bilingual</td>
</tr>
<tr>
<td>`tanggal_wawancara`</td>
<td>date/datetime</td>
<td>✔</td>
<td>Periode wawancara</td>
</tr>
<tr>
<td>`jumlah_peserta`</td>
<td>int</td>
<td>auto</td>
<td>Dihitung dari `participation` aktif</td>
</tr>
<tr>
<td>`target_peserta_diterima`</td>
<td>int</td>
<td>opsional</td>
<td>Manual; **informatif** (soft warning, tidak memblok — BR-TGT-01/02)</td>
</tr>
<tr>
<td>`deskripsi` / catatan job</td>
<td>text (JP)</td>
<td>opsional</td>
<td>Store canonical, render glyph (D9)</td>
</tr>
<tr>
<td>`syarat`</td>
<td>text (JP)</td>
<td>opsional</td>
<td>—</td>
</tr>
<tr>
<td>`status`</td>
<td>enum 5 status</td>
<td>✔</td>
<td>State machine §3</td>
</tr>
<tr>
<td>`dibuat_oleh`</td>
<td>FK → user</td>
<td>✔</td>
<td>Asisten Manajer (Maker)</td>
</tr>
<tr>
<td>`disetujui_oleh`</td>
<td>FK → user</td>
<td>nullable</td>
<td>Manajer Job (Checker)</td>
</tr>
<tr>
<td>`created_at` / `approved_at` / `closed_at`</td>
<td>timestamp</td>
<td>auto</td>
<td>Audit waktu</td>
</tr>
<tr>
<td>`version`</td>
<td>integer</td>
<td>✔</td>
<td>Optimistic lock (§12)</td>
</tr>
</table>
### 2.2 Perusahaan (`perusahaan`)
Master data tujuan wawancara; CRUD & approval-nya milik Super Admin (MODULE_LOOKUP_DATA D-L6, dengan step-up). Modul Jobs **mereferensikan** saja.
<table header-row="true">
<tr>
<td>Atribut</td>
<td>Wajib</td>
<td>Catatan</td>
</tr>
<tr>
<td>`nama_ja`</td>
<td>**WAJIB**</td>
<td>GLOSSARY §5 + BR-I18N-01 (final)</td>
</tr>
<tr>
<td>`nama_romaji`</td>
<td>opsional</td>
<td>Final (DECISIONS_LOG GLOSSARY)</td>
</tr>
<tr>
<td>`nama_id`</td>
<td>**opsional**</td>
<td>Final — bukan wajib (PRD §9.4, bukan §9.6)</td>
</tr>
<tr>
<td>`is_active`</td>
<td>—</td>
<td>Soft-disable, bukan hard-delete bila dirujuk kontainer</td>
</tr>
</table>
### 2.3 Posisi Pekerjaan & Target
- **Posisi Pekerjaan** = lookup `posisi_pekerjaan` + FK `bidang_pekerjaan_id` (sudah ada di MODULE_LOOKUP_DATA). Tidak ada tabel baru di modul ini.
- **Target Peserta Diterima** = kolom angka informatif. Saat `jumlah_peserta_diterima > target`, sistem menampilkan **soft warning** (`SIMILARITY`-style banner) tanpa memblokir aksi apa pun (PRD §10, BR-TGT-01/02).
---
## 3. Lifecycle (State Machine) — final per STATUS_STATE_MACHINE §1
**5 status:** `Draft` · `Menunggu Approval` · `Aktif` · `Ditutup` (terminal, irreversible) · `Dibatalkan` (terminal, hanya pre-Aktif).
**"Menunggu Persetujuan Penutupan" BUKAN status** — ia overlay yang diturunkan dari adanya `pending_request` bertipe CLOSE yang masih terbuka. Status tetap `Aktif`.
<table header-row="true">
<tr>
<td>Dari → Ke</td>
<td>Pemicu</td>
<td>Aktor</td>
<td>Guard</td>
<td>Efek samping / Audit</td>
<td>Approval / Step-up</td>
</tr>
<tr>
<td>(baru) → Draft</td>
<td>Buat kontainer</td>
<td>Asisten Manajer</td>
<td>—</td>
<td>`IC_CREATED`</td>
<td>—</td>
</tr>
<tr>
<td>Draft → Menunggu Approval</td>
<td>Submit</td>
<td>Asisten Manajer</td>
<td>Blokir resubmit tanpa perubahan (BR-APV)</td>
<td>`IC_SUBMITTED`</td>
<td>—</td>
</tr>
<tr>
<td>Draft → Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>—</td>
<td>`IC_CANCELLED`</td>
<td>—</td>
</tr>
<tr>
<td>Menunggu Approval → Aktif</td>
<td>Setujui</td>
<td>Manajer Job</td>
<td>—</td>
<td>`IC_APPROVED`; set `disetujui_oleh`,`approved_at`; buka pembuatan link tamu</td>
<td>Approval ✔ · Step-up ✘</td>
</tr>
<tr>
<td>Menunggu Approval → Draft</td>
<td>Tolak (+catatan WAJIB)</td>
<td>Manajer Job</td>
<td>Catatan tolak wajib</td>
<td>`IC_REJECTED`</td>
<td>Approval ✔ · Step-up ✘</td>
</tr>
<tr>
<td>Menunggu Approval → Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>—</td>
<td>`IC_CANCELLED`</td>
<td>—</td>
</tr>
<tr>
<td>Aktif → Ditutup</td>
<td>Minta tutup → setujui</td>
<td>Maker: Asisten Manajer · Checker: Manajer Job</td>
<td>`pending_request` CLOSE terverifikasi dalam transaksi</td>
<td>`IC_CLOSE_REQUESTED` → `IC_CLOSED`; freeze partisipasi non-terminal (GAP-3); `markAvailable()` semua kandidat → Tersedia</td>
<td>Approval ✔ · **Step-up ✔**</td>
</tr>
</table>
**Status terminal:** `Ditutup` (irreversible), `Dibatalkan`.
**Transisi TERLARANG (eksplisit):** `Aktif → Dibatalkan`/`Draft`/`Menunggu Approval`; `Draft → Aktif` (wajib lewat Menunggu Approval); `Menunggu Approval → Ditutup`; `Ditutup → *`; `Dibatalkan → *`.
**Enforcement:** spatie/laravel-model-states (transition class kustom + guard) + CHECK constraint DB + optimistic `version`.
---
## 4. API / Routes (high-level)
Semua endpoint internal di belakang auth + Policy (spatie/laravel-permission + Policy scope, D-R1/D-R2).
<table header-row="true">
<tr>
<td>Method · Path</td>
<td>Aksi</td>
<td>Aktor</td>
<td>Guard utama</td>
</tr>
<tr>
<td>`POST /jobs`</td>
<td>Buat Draft</td>
<td>Asisten Manajer</td>
<td>permission `job.create`</td>
</tr>
<tr>
<td>`PUT /jobs/{id}`</td>
<td>Edit Draft</td>
<td>Asisten Manajer (pembuat)</td>
<td>status=Draft; cek `version` → 409</td>
</tr>
<tr>
<td>`POST /jobs/{id}/submit`</td>
<td>Submit approval</td>
<td>Asisten Manajer</td>
<td>status=Draft; ada perubahan</td>
</tr>
<tr>
<td>`POST /jobs/{id}/approve`</td>
<td>Setujui → Aktif</td>
<td>Manajer Job</td>
<td>status=Menunggu Approval</td>
</tr>
<tr>
<td>`POST /jobs/{id}/reject`</td>
<td>Tolak → Draft</td>
<td>Manajer Job</td>
<td>catatan wajib</td>
</tr>
<tr>
<td>`POST /jobs/{id}/cancel`</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>status ∈ \{Draft, Menunggu Approval\}</td>
</tr>
<tr>
<td>`POST /jobs/{id}/request-close`</td>
<td>Minta tutup</td>
<td>Asisten Manajer</td>
<td>status=Aktif; alasan maker wajib</td>
</tr>
<tr>
<td>`POST /jobs/{id}/approve-close`</td>
<td>Setujui penutupan</td>
<td>Manajer Job</td>
<td>ada `pending_request` CLOSE; **step-up ✔**; catatan checker</td>
</tr>
<tr>
<td>`GET /jobs`, `GET /jobs/{id}`</td>
<td>Lihat</td>
<td>Internal sesuai role</td>
<td>Super Admin read-only operasional</td>
</tr>
</table>
Konflik versi pada setiap mutasi → **HTTP 409**. Aksi pada `pending_request` ganda → verifikasi di dalam transaksi → 409 (anti double-approval).
---
## 5. Approver Workflow (Maker–Checker)
Mengikuti ROLES §5.2 (final):
- **Asisten Manajer = Maker:** buat/edit/batalkan kontainer, submit approval, tarik kandidat (bulk), update status partisipasi, ajukan keluarkan kandidat ⏳, ajukan tutup ⏳, ajukan link tamu ⏳.
- **Manajer Job = pure Checker:** setujui/tolak kontainer (rutin), approve keluarkan kandidat 🔒, approve tutup 🔒, approve link tamu. **Tidak punya akses Modul Kandidat.**
- **Super Admin:** read-only operasional.
**Catatan kandidat (selaras dependency, bukan brief):** Di modul Jobs **tidak ada** aksi "approve/reject kandidat". Approver Kandidat hanya beroperasi di MODULE_CANDIDATES (ROLES §5.1). Alur kandidat dalam kontainer = **tarik (Asisten Manajer) → update ****`status_wawancara`**** (jalur alami state machine, tanpa approval) → keluarkan/expel (approval Manajer Job + step-up)**. Lihat §15 (GAP).
---
## 6. Visibilitas Tamu
- Hanya kontainer ber-status **`Aktif`** yang dapat diakses Tamu (PRD §4.3).
- Tamu = aktor token read-only di luar RBAC internal (ROLES D-R7), hanya membaca **`GuestCandidateView`** (whitelist kolom, JP eksplisit) — definisi & token milik **MODULE_GUEST_ACCESS**.
- Saat kontainer keluar dari `Aktif` (→ Ditutup), link tamu terkait kedaluwarsa sesuai state machine Link Tamu (koordinasi MODULE_GUEST_ACCESS).
---
## 7. Persistence (high-level)
- `interview_container` (atribut §2.1) + CHECK constraint status + index `(status)`, `(perusahaan_id)`.
- `pending_request` (cross-cutting): `type` (`IC_CREATE`, `IC_CLOSE`, `IC_EXPEL`, `GUEST_LINK`), `target_id`, `requested_by`, `reason_maker`, `note_checker`, `status` (pending/approved/rejected), unik aktif per (type,target) untuk anti-duplikasi.
- Relasi: FK ke `perusahaan`, lookup `posisi_pekerjaan`/`jenis_visa`. **Tanpa FK lintas-modul ke Kandidat** — akses ketersediaan via service publik (ARCHITECTURE D2).
- `version integer NOT NULL DEFAULT 0` di `interview_container` & `participation`.
---
## 8. Invariants
1. Status hanya berpindah lewat transisi sah §3; lainnya ditolak guard + CHECK.
2. `Draft → Aktif` mustahil tanpa melewati `Menunggu Approval`.
3. `Ditutup` & `Dibatalkan` bersifat terminal — tak ada transisi keluar.
4. `Dibatalkan` hanya dari pre-Aktif (Draft / Menunggu Approval).
5. Penutupan butuh `pending_request` CLOSE yang disetujui + step-up; bersifat irreversible.
6. Saat Ditutup: semua `status_wawancara` non-terminal dibekukan (GAP-3) — `status_wawancara` **tidak diubah**, stamp `participation.frozen_at`, baris beku **tidak** mengisi slot partial unique; kandidat → Tersedia dan boleh ditarik ke kontainer `Aktif` lain lewat baris partisipasi baru.
7. `target_peserta_diterima` tidak pernah memblok aksi (soft warning saja).
8. Resubmit tanpa perubahan dari Draft diblokir (BR-APV).
9. Tolak kontainer wajib menyertakan catatan (Manajer Job).
10. Setiap mutasi memvalidasi `version`; mismatch → 409.
---
## 9. Integrasi Modul Lain
<table header-row="true">
<tr>
<td>Modul</td>
<td>Kaitan</td>
</tr>
<tr>
<td>MODULE_CANDIDATES</td>
<td>Tarik kandidat (service publik), `status_wawancara`, expel; tanpa FK langsung</td>
</tr>
<tr>
<td>MODULE_GUEST_ACCESS</td>
<td>Link tamu + GuestCandidateView untuk kontainer Aktif</td>
</tr>
<tr>
<td>MODULE_PLACEMENT</td>
<td>Transfer normal hanya dari source participation `Siap Dikirim` milik kandidat dengan availability `Sedang Dipakai`; approval batch mengubah source→`Terkirim` tanpa window `Tersedia`</td>
</tr>
<tr>
<td>MODULE_LOOKUP_DATA</td>
<td>`perusahaan`, `posisi_pekerjaan`, `bidang_pekerjaan`, `jenis_visa`</td>
</tr>
<tr>
<td>MODULE_AUTH</td>
<td>Mekanisme step-up re-auth (password+TOTP, TTL 5 mnt) untuk penutupan</td>
</tr>
</table>
---
## 10. Audit Events (enum kanonik PRD Lampiran A)
Memakai enum kanonik PRD (brief `job_*` dipetakan ke nama kanonik):
<table header-row="true">
<tr>
<td>Nama brief</td>
<td>Enum kanonik PRD</td>
<td>Keterangan</td>
</tr>
<tr>
<td>job_create</td>
<td>`IC_CREATED`</td>
<td>Draft dibuat</td>
</tr>
<tr>
<td>job_submit_for_approval</td>
<td>`IC_SUBMITTED`</td>
<td>Submit approval; status `Menunggu Approval`  • pending `IC_CREATE` dibuat satu transaksi</td>
</tr>
<tr>
<td>job_approve</td>
<td>`IC_APPROVED`</td>
<td>Disetujui → Aktif</td>
</tr>
<tr>
<td>job_reject</td>
<td>`IC_REJECTED`</td>
<td>Ditolak → Draft</td>
</tr>
<tr>
<td>job_activate</td>
<td>(tergabung `IC_APPROVED`)</td>
<td>Aktivasi = efek approve, bukan event terpisah</td>
</tr>
<tr>
<td>job_request_close</td>
<td>`IC_CLOSE_REQUESTED`</td>
<td>Minta tutup</td>
</tr>
<tr>
<td>job_close</td>
<td>`IC_CLOSED`</td>
<td>Penutupan disetujui</td>
</tr>
<tr>
<td>job_cancel</td>
<td>`IC_CANCELLED`</td>
<td>Pembatalan</td>
</tr>
<tr>
<td>(tarik kandidat)</td>
<td>`CANDIDATE_PULLED`</td>
<td>Bulk pull</td>
</tr>
<tr>
<td>(ubah status partisipasi)</td>
<td>`PARTICIPATION_STATUS_CHANGED`</td>
<td>Jalur alami</td>
</tr>
<tr>
<td>candidate_approve/reject_in_job</td>
<td>— (tidak ada di Jobs)</td>
<td>Lihat §15 GAP-J2</td>
</tr>
<tr>
<td>target_warning_shown</td>
<td>— (tidak dipakai)</td>
<td>Opsi A (final): warning tanpa audit</td>
</tr>
</table>
Expel kandidat: `EXPEL_REQUESTED` / `EXPEL_APPROVED` / `EXPEL_REJECTED`.
---
## 11. Step-up Re-auth
Mengikuti PRD §4.6/Lampiran D + ROLES §8.2 + MODULE_AUTH (final), **bukan** daftar brief:
- **Memicu step-up:** approve **Penutupan kontainer wawancara** (irreversible), approve **Keluarkan kandidat (expel)**.
- **TIDAK memicu step-up (approval rutin):** approve/tolak kontainer baru, batalkan kontainer, approve link tamu.
- Mekanisme: re-auth password+TOTP, TTL 5 menit, per-aksi (MODULE_AUTH A-6).
> ⚠️ Brief misi meminta step-up untuk Approve/Reject/Close/Cancel. Ini **bertentangan** dengan dependency final; modul mengikuti dependency (hanya Close + Expel). Lihat §15 GAP-J1.
---
## 12. Konkurensi
- **Optimistic locking** kolom `version` pada `interview_container` & `participation`: `UPDATE ... SET version=version+1 WHERE id=:id AND version=:v`; 0 baris terpengaruh → konflik → **HTTP 409** (BR-CON-01/02, ARCHITECTURE D8).
- **Pessimistic ****`SELECT ... FOR UPDATE`** hanya untuk bulk pull kandidat (BR-CON-03).
- Partial unique pada `participation(candidate_id)` untuk status aktif (`Menunggu Wawancara`, `Lulus`, `Proses Dokumen`, `Siap Dikirim`) menjadi jaring pengaman satu proses Wawancara aktif per kandidat.
- Anti double-approval: verifikasi keberadaan `pending_request` `pending` di dalam transaksi sebelum approve → bila sudah diproses, 409.
---
## 13. Edge Cases
1. Submit ulang Draft tanpa perubahan → ditolak (BR-APV).
2. Dua Manajer Job approve penutupan bersamaan → satu sukses, lain 409.
3. Edit Draft sementara user lain sudah submit → 409 versi.
4. Cancel pada kontainer Aktif → ditolak (transisi terlarang).
5. Target dilampaui → banner soft warning; aksi tetap berjalan.
6. Penutupan saat masih ada partisipasi non-terminal → tetap boleh; semua dibekukan & kandidat → Tersedia (GAP-3).
7. Step-up kedaluwarsa (>5 mnt) saat approve tutup → minta re-auth ulang, transaksi batal.
8. Akses Tamu ke kontainer non-Aktif → 404/410 (ditangani MODULE_GUEST_ACCESS).
9. Referensi perusahaan non-aktif → cegah pemilihan baru; kontainer lama tetap valid (soft-disable).
---
## 14. Test Plan (ringkas)
- **State machine:** tiap transisi sah lulus; tiap transisi terlarang ditolak; terminal tak bisa keluar.
- **Maker–Checker:** Asisten Manajer tak bisa approve sendiri; Manajer Job tak bisa buat/submit; Super Admin read-only.
- **Step-up:** approve tutup tanpa step-up → ditolak; approve rutin tanpa step-up → sukses.
- **Konkurensi:** uji 409 optimistic; bulk pull FOR UPDATE; partial unique satu participation aktif; anti double-approval `pending_request` termasuk `IC_CREATE`.
- **Target:** lampaui target → warning muncul, aksi tidak terblok.
- **Penutupan:** stamp `frozen_at` pada partisipasi non-terminal (status tidak berubah), markAvailable terpicu, re-pull kandidat ke kontainer baru lolos; audit `IC_CLOSE_REQUESTED`→`IC_CLOSED`.
- **Tamu:** hanya kontainer Aktif terlihat; non-Aktif tertutup.
- **Audit:** setiap aksi mencatat enum kanonik yang benar.
- **i18n:** `nama_ja` wajib; render glyph; Tamu JP.
---
## 15. Pertanyaan Terbuka & GAP PRD
- **GAP-J1 (step-up):** Brief minta step-up pada Approve/Reject/Cancel; dependency final hanya Close + Expel. **Resolusi (keputusan user 2026-06-29): ikuti dependency.** Memilih versi brief perlu SUPERSEDES + update PRD.
- **GAP-J2 (approve/reject kandidat di Jobs):** Brief menyebut alur ini di Jobs; ROLES/STATE_MACHINE menempatkannya di MODULE_CANDIDATES. **Resolusi: ikuti dependency** — di Jobs hanya tarik + update status + expel. Event `candidate_approve_in_job`/`candidate_reject_in_job` tidak dibuat.
- **GAP-J3 (audit ****`target_warning_shown`****): DITUTUP** — keputusan user 2026-06-29: **Opsi A**. Peringatan target ditampilkan sebagai soft warning **tanpa** dicatat ke audit log; tidak perlu enum baru & tidak ada adendum PRD (sifatnya informatif, bukan aksi berisiko).
- **Lampiran job:** PRD §5.3 tidak menyebut lampiran file di level kontainer → MVP tanpa lampiran (signed URL TTL tidak relevan). Bila nanti diperlukan, ikuti pola Candidates (5–15 mnt).
- **Rujukan PRD:** atribut kontainer = §5.3 (bukan "§7 Tabel 7"); canonical enum+glyph = §9.4 (bukan §9.6) — koreksi rujukan, bukan perubahan isi PRD.
