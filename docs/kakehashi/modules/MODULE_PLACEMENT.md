---
title: "MODULE_PLACEMENT"
status: "FINAL"
source_notion_title: "MODULE_PLACEMENT"
exported_at: "2026-07-15"
authority_rank: "module"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# MODULE_PLACEMENT

> [!NOTE]
> Status: **FINAL** · Domain: **Kontainer Penempatan** · Selaras PRD_Kakehashi_v0.3.14 + GLOSSARY/ROLES/STATUS_STATE_MACHINE/BUSINESS_RULES/MODULE_LOOKUP_DATA/MODULE_AUTH (semua FINAL). Tech terverifikasi live 2026-06-29. Keputusan terbuka diputuskan user 2026-06-29 (lihat §16).
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
<td>13.x (pin `^13`; latest 13.17.0)</td>
<td>Aktif — bugfix s/d Q3 2027, security s/d 17 Mar 2028; PHP 8.3–8.5</td>
<td>Tanpa breaking dari 12; baseline VPS 4 vCPU / 8 GB (2026-07-13)</td>
<td>[laravel.com/docs/13.x/releases](http://laravel.com/docs/13.x/releases)</td>
</tr>
<tr>
<td>PostgreSQL (`FOR UPDATE`)</td>
<td>18.x</td>
<td>Aktif</td>
<td>`FOR UPDATE` aman di **READ COMMITTED** (default): menunggu lalu mengunci baris. Di REPEATABLE READ/SERIALIZABLE → error bila baris berubah sejak tx mulai. `SKIP LOCKED` **tidak** dipakai pada bulk pull (butuh konsistensi).</td>
<td>[postgresql.org/docs/18/sql-select.html](http://postgresql.org/docs/18/sql-select.html), /explicit-locking.html</td>
</tr>
<tr>
<td>spatie/laravel-model-states</td>
<td>2.14.1 (22 Apr 2026)</td>
<td>Aktif</td>
<td>Transition class kustom utk guard + efek samping atomik (audit, markInUse/markAvailable, set Terkirim). Butuh Laravel 13/PHP 8.4.</td>
<td>[spatie.be/docs/laravel-model-states/v2](http://spatie.be/docs/laravel-model-states/v2); [github.com/spatie/laravel-model-states/releases](http://github.com/spatie/laravel-model-states/releases)</td>
</tr>
<tr>
<td>Laravel queue (redis)</td>
<td>Redis co-located · Laravel 13</td>
<td>Aktif (2026-07-13)</td>
<td>Queue production = **redis + 2 worker**. Idempotensi auto-archive **tetap di unique constraint DB + transaksi**; Redis lock boleh bantu, bukan pengganti.</td>
<td>[laravel.com/docs/13.x/queues](http://laravel.com/docs/13.x/queues); [github.com/laravel/framework/issues/41464](http://github.com/laravel/framework/issues/41464)</td>
</tr>
<tr>
<td>`DB::transaction`  • optimistic lock</td>
<td>bawaan Laravel 13</td>
<td>Aktif</td>
<td>Auto-retry deadlock via argumen `$attempts`. Optimistic `version`: `UPDATE ... WHERE version=:v`; 0 row → 409.</td>
<td>[laravel.com/docs/13.x/database#database-transactions](http://laravel.com/docs/13.x/database#database-transactions)</td>
</tr>
</table>
Tidak ada perubahan versi mayor dari TECH_VERSION_SEED.
---
## 1. Scope
**Termasuk:** Siklus hidup Kontainer Penempatan end-to-end (Draft → Menunggu Approval → Aktif → Arsip otomatis; Dibatalkan), tabel relasi Partisipasi Kandidat (`placement_participants`), penarikan kandidat dari Kontainer Wawancara (batch normal), **sub-flow Force-Majeur**, lifecycle `status_penempatan` per-kandidat, auto-archive, konkurensi (optimistic `version` + pessimistic `FOR UPDATE` untuk bulk pull), step-up untuk Cabut Penempatan, dan audit.
**Tidak termasuk (delegasi ke modul lain):** data & approval kandidat dan ketersediaan (MODULE_CANDIDATES), kontainer & approval wawancara (MODULE_JOBS), master perusahaan/visa/lookup termasuk **`kategori_force_majeur`** (MODULE_LOOKUP_DATA), mekanisme step-up re-auth (MODULE_AUTH), akses tamu (MODULE_GUEST_ACCESS).
**Pemetaan rujukan PRD (koreksi salah-rujuk brief — pola sama dengan MODULE_CANDIDATES/JOBS):**
- Atribut Kontainer Penempatan & `placement_participants` = **PRD §5.4** (brief menulis "§7 Tabel 8").
- Sub-flow Force-Majeur = **PRD §6.4 Sub-flow 2b** (brief menulis "§9.3").
- Konkurensi = **PRD §7.10** (brief menulis "§9").
- Field referensi partisipasi wawancara asal = **`source_participation_id`** (PRD §5.4); brief menulis `ref_riwayat_partisipasi`. Keputusan user: pakai `source_participation_id` (§16-2).
---
## 2. Domain Model
### 2.1 Kontainer Penempatan (`placement_container`)
Atribut dari PRD §5.4:
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
<td>Kode human-readable `P-YYYY-NNNNN`; di-assign saat submit pertama (Draft = NULL), immutable; counter `container_counter` per-tahun JST (BR-KODE)</td>
</tr>
<tr>
<td>`nama`</td>
<td>string</td>
<td>✔</td>
<td>Nama kontainer penempatan</td>
</tr>
<tr>
<td>`perusahaan_id`</td>
<td>FK → `perusahaan`</td>
<td>✔</td>
<td>**Immutable** setelah dibuat; 1 kontainer = 1 perusahaan; tampil `nama_ja` (master via MODULE_LOOKUP_DATA)</td>
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
<td>`created_at` / `approved_at` / `archived_at`</td>
<td>timestamp</td>
<td>auto</td>
<td>Audit waktu; `archived_at` diisi saat auto-archive</td>
</tr>
<tr>
<td>`version`</td>
<td>integer</td>
<td>✔</td>
<td>Optimistic lock (§12), default 0</td>
</tr>
</table>
### 2.2 Partisipasi Kandidat (`placement_participants`) — tabel relasi
Relasi kandidat ⇄ kontainer penempatan. Atribut dari PRD §5.4:
<table header-row="true">
<tr>
<td>Atribut</td>
<td>Tipe</td>
<td>Wajib</td>
<td>Catatan</td>
</tr>
<tr>
<td>`id`</td>
<td>bigint PK</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>`placement_container_id`</td>
<td>FK → `placement_container`</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>`candidate_id`</td>
<td>ref kandidat (tanpa FK lintas-modul)</td>
<td>✔</td>
<td>Akses via service publik MODULE_CANDIDATES (ARCH D2)</td>
</tr>
<tr>
<td>`source_participation_id`</td>
<td>ref partisipasi wawancara</td>
<td>**nullable**</td>
<td>Partisipasi wawancara asal. **NULL ⇒ jalur Force-Majeur** (§5)</td>
</tr>
<tr>
<td>`kategori_force_majeur_id`</td>
<td>FK → lookup `kategori_force_majeur`</td>
<td>**wajib bila ****`source_participation_id`**** NULL**</td>
<td>Lookup baru (§16-4); NULL bila bukan FM</td>
</tr>
<tr>
<td>`alasan_force_majeur`</td>
<td>text</td>
<td>**wajib bila ****`source_participation_id`**** NULL**</td>
<td>Free-text detail; dilarang terisi bila bukan FM</td>
</tr>
<tr>
<td>`jenis_visa_id`</td>
<td>FK → lookup `jenis_visa`</td>
<td>✔</td>
<td>Per-kandidat (boleh beda dari kontainer)</td>
</tr>
<tr>
<td>`tanggal_mulai_kerja`</td>
<td>date</td>
<td>✔</td>
<td>—</td>
</tr>
<tr>
<td>`durasi_kontrak_bulan`</td>
<td>int</td>
<td>✔</td>
<td>Bulan</td>
</tr>
<tr>
<td>`tanggal_berakhir_kontrak`</td>
<td>date</td>
<td>auto + override</td>
<td>Default inklusif = mulai + durasi bulan − 1 hari; override wajib ≥ mulai</td>
</tr>
<tr>
<td>`status_penempatan`</td>
<td>enum 4</td>
<td>✔</td>
<td>Lifecycle per-kandidat §3.2</td>
</tr>
<tr>
<td>`tanggal_status_final`</td>
<td>date</td>
<td>nullable</td>
<td>Diisi saat status terminal</td>
</tr>
<tr>
<td>`catatan_alasan`</td>
<td>text</td>
<td>wajib utk Mengundurkan Diri & Dikeluarkan</td>
<td>2 lapis utk Dikeluarkan (alasan + justifikasi checker)</td>
</tr>
<tr>
<td>`disetujui_oleh`</td>
<td>FK → user</td>
<td>nullable</td>
<td>Manajer Job pada aksi ber-approval</td>
</tr>
<tr>
<td>`version`</td>
<td>integer</td>
<td>✔</td>
<td>Optimistic lock</td>
</tr>
</table>
**Constraint kunci:**
- CHECK: `(source_participation_id IS NULL) = (kategori_force_majeur_id IS NOT NULL AND alasan_force_majeur IS NOT NULL)` — memaksa alasan FM hanya & selalu saat ref null.
- UNIQUE aktif: satu kandidat tidak boleh punya 2 partisipasi `Bekerja` bersamaan (lintas kontainer) — dijaga oleh service `markInUse()` MODULE_CANDIDATES + unique partial index.
---
## 3. Lifecycle (State Machine)
### 3.1 Kontainer — final per STATUS_STATE_MACHINE (placement)
**5 status:** `Draft` · `Menunggu Approval` · `Aktif` · `Arsip` (terminal, **otomatis**, irreversible) · `Dibatalkan` (terminal, hanya pre-Aktif).
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
<td>`PC_CREATED`</td>
<td>—</td>
</tr>
<tr>
<td>Draft → Menunggu Approval</td>
<td>Submit</td>
<td>Asisten Manajer</td>
<td>Blokir resubmit tanpa perubahan</td>
<td>`PC_SUBMITTED`</td>
<td>—</td>
</tr>
<tr>
<td>Draft → Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>—</td>
<td>`PC_CANCELLED`</td>
<td>Approval ✘ · Step-up ✘</td>
</tr>
<tr>
<td>Menunggu Approval → Aktif</td>
<td>Setujui</td>
<td>Manajer Job</td>
<td>—</td>
<td>`PC_APPROVED`; set `disetujui_oleh`,`approved_at`; buka penarikan kandidat</td>
<td>Approval ✔ · Step-up ✘</td>
</tr>
<tr>
<td>Menunggu Approval → Draft</td>
<td>Tolak (+catatan WAJIB)</td>
<td>Manajer Job</td>
<td>Catatan tolak wajib</td>
<td>`PC_REJECTED`</td>
<td>Approval ✔ · Step-up ✘</td>
</tr>
<tr>
<td>Menunggu Approval → Dibatalkan</td>
<td>Batalkan</td>
<td>Asisten Manajer (pembuat)</td>
<td>—</td>
<td>`PC_CANCELLED`</td>
<td>Approval ✘ · Step-up ✘</td>
</tr>
<tr>
<td>Aktif → Arsip</td>
<td>**Otomatis** (bukan manual)</td>
<td>Sistem</td>
<td>Kandidat `Bekerja` terakhir mencapai terminal, dicek **setelah batch** (§13)</td>
<td>`CONTAINER_ARCHIVED`; set `archived_at`</td>
<td>—</td>
</tr>
<tr>
<td>Aktif → Dibatalkan</td>
<td>Batalkan (escape GAP-4)</td>
<td>Asisten Manajer → Manajer Job</td>
<td>**HANYA** bila `count(placement_participants)=0`</td>
<td>`PC_CANCELLED`</td>
<td>Approval ✔ · Step-up ✘</td>
</tr>
</table>
**Terlarang (eksplisit):** `Draft → Aktif` (wajib lewat Menunggu Approval); `Aktif → Draft`/`Menunggu Approval`; `Arsip → *`; `Dibatalkan → *`; pembatalan `Aktif` saat masih ada partisipasi (≥1) ditolak.
> **Tidak ada "manual close".** Kontainer Penempatan **tidak** ditutup manual seperti Kontainer Wawancara; ia **hanya** berpindah ke `Arsip` secara otomatis (§13). Escape `Aktif → Dibatalkan` hanya untuk kontainer kosong (GAP-4, STATUS_STATE_MACHINE).
### 3.2 Partisipasi (`status_penempatan`) — 4 status
<table header-row="true">
<tr>
<td>Status</td>
<td>Aktif?</td>
<td>Transisi keluar</td>
<td>Approval / Step-up</td>
<td>Efek</td>
</tr>
<tr>
<td>`Bekerja`</td>
<td>✔ (satu-satunya aktif)</td>
<td>→ Selesai Kontrak / Mengundurkan Diri / Dikeluarkan</td>
<td>—</td>
<td>State awal saat partisipasi aktif</td>
</tr>
<tr>
<td>`Selesai Kontrak`</td>
<td>terminal</td>
<td>—</td>
<td>Tanpa approval</td>
<td>`markAvailable()` → Tersedia; cek arsip setelah batch</td>
</tr>
<tr>
<td>`Mengundurkan Diri`</td>
<td>terminal</td>
<td>—</td>
<td>**Approval** Manajer Job · Step-up ✘ · `catatan_alasan` wajib</td>
<td>`markAvailable()` → Tersedia; cek arsip</td>
</tr>
<tr>
<td>`Dikeluarkan` (Cabut Penempatan)</td>
<td>terminal</td>
<td>—</td>
<td>**Approval + Step-up ✔** · alasan 2 lapis (maker + checker)</td>
<td>`markAvailable()` → Tersedia; cek arsip</td>
</tr>
</table>
---
## 4. Sumber Kandidat & Transfer Normal (batch)
- **Sumber normal final:** source participation ber-`status_wawancara=Siap Dikirim`, availability kandidat **`Sedang Dipakai`**, source participation aktif tersebut milik kandidat yang sama, dan kandidat tidak memiliki placement `Bekerja`. Filter `Siap Dikirim + Tersedia` dilarang.
- **Setiap row partisipasi** menyimpan `source_participation_id` → partisipasi wawancara asal (untuk traceability).
- **Submit batch:** buat pending `PLACEMENT_BATCH` dengan payload snapshot seluruh candidate/source/field penempatan; belum mengubah source atau availability.
- **Approval batch atomik (PRD §6.4 Sub-flow 2):** dalam satu transaksi:
	1. lock dan revalidasi pending, candidate, serta source participation;
	2. assert source=`Siap Dikirim`, availability=`Sedang Dipakai`, ownership cocok, tanpa placement `Bekerja`;
	3. insert `placement_participants` (`Bekerja`, `source_participation_id` terisi);
	4. source participation → `Terkirim`;
	5. availability tetap `Sedang Dipakai`; **jangan** memanggil `markInUse()` untuk flip, cukup assert ownership/state;
	6. audit `BATCH_SENT`.
	Gagal salah satu → rollback total.
- **Batas batch:** maksimum **50 kandidat per operasi** (§16-5) untuk membatasi durasi lock `FOR UPDATE` (tetap di VPS 4C/8G).
---
## 5. Sub-flow Force-Majeur (PRD §6.4 Sub-flow 2b) — detail
Jalur memasukkan kandidat **tanpa** Kontainer Wawancara terkait.
**Prasyarat:**
- Kandidat ber-ketersediaan **`Tersedia`** dan sudah **`Disetujui`** (MODULE_CANDIDATES).
- `source_participation_id = NULL` → memicu kewajiban `kategori_force_majeur_id` + `alasan_force_majeur` (CHECK §2.2).
**Alur:**
1. Asisten Manajer mengajukan partisipasi FM (isi kategori + alasan, jenis visa, tanggal mulai, durasi).
2. Dibuat `pending_request` bertipe **FORCE_MAJEUR** (status `pending`).
3. **Manajer Job approve** (approval rutin — **TANPA step-up**, BR-FM-06 / DECISIONS_LOG D1).
4. Pada approval, **operasi ATOMIK** dalam satu `DB::transaction`:
	- Insert `placement_participants` (`status_penempatan='Bekerja'`, ref null + alasan FM).
	- `markInUse()` kandidat → `Sedang Dipakai`.
	- Tulis audit `FORCE_MAJEUR_ADDED` (payload memuat kategori + alasan 2-lapis = `fm_alasan_recorded` tergabung).
	- Tulis notifikasi in-app DB bila termasuk transaksi bisnis.
	- Email/queue Redis dijalankan after-commit; gagal enqueue dicatat tetapi tidak me-rollback transaksi bisnis.
5. Tolak → `pending_request` ditandai `rejected`, audit pada lifecycle pending_request (lihat §10).
**Diagram alur Force-Majeur:**
```javascript
[Kandidat: Tersedia + Disetujui]
          |
          v
  Asisten Manajer ajukan partisipasi FM
  (kategori_force_majeur + alasan WAJIB; source_participation_id = NULL)
          |
          v
  pending_request(type=FORCE_MAJEUR, status=pending)
          |
     +----+-----------------------------+
     | Manajer Job approve               | Manajer Job tolak
     | (approval rutin, TANPA step-up)   |
     v                                   v
  === DB::transaction (ATOMIK) ===    pending_request=rejected
   1. INSERT placement_participants    (audit FM_REJECTED)
      status_penempatan='Bekerja'
      source_participation_id=NULL
      kategori_force_majeur_id, alasan_force_majeur
   2. markInUse() -> Sedang Dipakai
   3. AUDIT: FORCE_MAJEUR_ADDED (alasan 2-lapis)
   4. NOTIFIKASI (queue=redis)
  ================================
     | sukses semua          | gagal salah satu
     v                       v
  COMMIT                  ROLLBACK TOTAL
  (partisipasi aktif)     (tidak ada perubahan)
```
---
## 6. API / Routes (high-level)
Semua endpoint internal di belakang auth + Policy (spatie/laravel-permission + Policy scope).
<table header-row="true">
<tr>
<td>Method · Path</td>
<td>Aksi</td>
<td>Aktor</td>
<td>Guard utama</td>
</tr>
<tr>
<td>`POST /placements`</td>
<td>Buat Draft</td>
<td>Asisten Manajer</td>
<td>permission `placement.create`</td>
</tr>
<tr>
<td>`PUT /placements/{id}`</td>
<td>Edit Draft</td>
<td>Asisten Manajer (pembuat)</td>
<td>status=Draft; cek `version` → 409</td>
</tr>
<tr>
<td>`POST /placements/{id}/submit`</td>
<td>Submit approval</td>
<td>Asisten Manajer</td>
<td>status=Draft; ada perubahan</td>
</tr>
<tr>
<td>`POST /placements/{id}/approve`</td>
<td>Setujui → Aktif</td>
<td>Manajer Job</td>
<td>status=Menunggu Approval · step-up ✘</td>
</tr>
<tr>
<td>`POST /placements/{id}/reject`</td>
<td>Tolak → Draft</td>
<td>Manajer Job</td>
<td>catatan wajib · step-up ✘</td>
</tr>
<tr>
<td>`POST /placements/{id}/cancel`</td>
<td>Batalkan</td>
<td>Asisten Manajer / (Aktif kosong: + Manajer Job)</td>
<td>pre-Aktif, atau Aktif & `count(participants)=0` · step-up ✘</td>
</tr>
<tr>
<td>`POST /placements/{id}/batches`</td>
<td>Submit batch normal → pending `PLACEMENT_BATCH`</td>
<td>Asisten Manajer</td>
<td>status=Aktif; payload snapshot; ≤50/operasi</td>
</tr>
<tr>
<td>`POST /placements/{id}/batches/{rid}/approve`</td>
<td>Approve batch normal atomik</td>
<td>Manajer Job</td>
<td>pending masih pending; lock+revalidasi source; step-up ✘</td>
</tr>
<tr>
<td>`POST /placements/{id}/participants/force-majeur`</td>
<td>Ajukan partisipasi FM</td>
<td>Asisten Manajer</td>
<td>status=Aktif; kategori+alasan wajib</td>
</tr>
<tr>
<td>`POST /placements/{id}/participants/fm/{rid}/approve`</td>
<td>Approve FM</td>
<td>Manajer Job</td>
<td>ada `pending_request` FM · step-up ✘ · ATOMIK</td>
</tr>
<tr>
<td>`POST /placements/{id}/participants/fm/{rid}/reject`</td>
<td>Tolak FM</td>
<td>Manajer Job</td>
<td>ada `pending_request` FM</td>
</tr>
<tr>
<td>`POST /participants/{pid}/status`</td>
<td>Ubah `status_penempatan` (Selesai Kontrak)</td>
<td>Asisten Manajer</td>
<td>jalur alami; Selesai Kontrak tanpa approval</td>
</tr>
<tr>
<td>`POST /participants/{pid}/resign`</td>
<td>Mengundurkan Diri (request→approve)</td>
<td>Maker: Asisten Manajer · Checker: Manajer Job</td>
<td>`catatan_alasan` wajib · step-up ✘</td>
</tr>
<tr>
<td>`POST /participants/{pid}/expel`</td>
<td>**Cabut Penempatan / Dikeluarkan** (request→approve)</td>
<td>Maker: Asisten Manajer · Checker: Manajer Job</td>
<td>**step-up ✔** · alasan 2-lapis</td>
</tr>
</table>
Konflik versi pada setiap mutasi → **HTTP 409**. `pending_request` ganda → verifikasi dalam transaksi → 409.
---
## 7. Persistence (high-level)
- `placement_container` (atribut §2.1) + CHECK constraint status + index `(status)`, `(perusahaan_id)`.
- `placement_participants` (atribut §2.2) + CHECK FM (§2.2) + partial unique index untuk satu `Bekerja` aktif per kandidat.
- `pending_request` (cross-cutting): `type` ∈ \{PC_CREATE, PLACEMENT_BATCH, FORCE_MAJEUR, PLACEMENT_RESIGN, PLACEMENT_EXPEL, PC_CANCEL_ACTIVE\}; payload snapshot wajib untuk batch/FM/resign/expel/cancel; `target_id`, `requested_by`, `reason_maker`, `note_checker`, `status`, unik aktif per (type,target).
- `version integer NOT NULL DEFAULT 0` di kedua tabel inti.
- **Tanpa FK lintas-modul** ke Kandidat/Wawancara — akses via service publik (`markInUse()`/`markAvailable()`, ARCH D2). `source_participation_id` & `candidate_id` disimpan sebagai referensi logis.
- `audit_log` JSONB immutable (ARCH D4); notifikasi/email via queue **redis + 2 worker** (ARCH D5, 2026-07-13).
---
## 8. Invariants
1. Status kontainer hanya berpindah lewat transisi sah §3.1; lainnya ditolak guard + CHECK.
2. `Draft → Aktif` mustahil tanpa `Menunggu Approval`.
3. `Arsip` & `Dibatalkan` terminal — tak ada transisi keluar.
4. `Dibatalkan` hanya pre-Aktif, KECUALI escape Aktif-kosong (`count=0` + approval).
5. **Tidak ada penutupan manual** — `Aktif → Arsip` hanya otomatis (§13).
6. `source_participation_id IS NULL` ⟺ baris Force-Majeur ⟺ `kategori_force_majeur_id` & `alasan_force_majeur` terisi (CHECK).
7. Hanya `Bekerja` yang status aktif; tiga status terminal mengembalikan kandidat ke `Tersedia` via `markAvailable()`.
8. Operasi FM & approval batch normal bersifat atomik. Jalur normal adalah transfer ownership tanpa availability `Tersedia`; Force-Majeur tetap `Tersedia+Disetujui`→`markInUse()`.
9. `catatan_alasan` wajib untuk `Mengundurkan Diri` & `Dikeluarkan`; `Dikeluarkan` butuh alasan 2-lapis.
10. Setiap mutasi memvalidasi `version`; mismatch → 409.
11. `perusahaan_id` immutable setelah kontainer dibuat.
12. Step-up **hanya** pada Cabut Penempatan (`Dikeluarkan`); aksi approval lain rutin.
---
## 9. Integrasi Modul Lain
<table header-row="true">
<tr>
<td>Modul</td>
<td>Kaitan</td>
</tr>
<tr>
<td>MODULE_JOBS (Wawancara)</td>
<td>Sumber kandidat normal (`Diterima`/`Siap Dikirim`); set `status_wawancara` → `Terkirim` saat batch; `source_participation_id` mereferensikan partisipasi wawancara</td>
</tr>
<tr>
<td>MODULE_CANDIDATES</td>
<td>Ketersediaan via service publik `markInUse()` (→ Sedang Dipakai) & `markAvailable()` (→ Tersedia); sumber FM = kandidat `Tersedia + Disetujui`; tanpa FK langsung</td>
</tr>
<tr>
<td>MODULE_LOOKUP_DATA</td>
<td>`perusahaan`, `jenis_visa`, **`kategori_force_majeur`** (lookup baru — lihat §16-4)</td>
</tr>
<tr>
<td>MODULE_AUTH</td>
<td>Step-up re-auth (password+TOTP, TTL 5 mnt) untuk Cabut Penempatan</td>
</tr>
<tr>
<td>MODULE_GUEST_ACCESS</td>
<td>Tidak ada akses tamu pada Kontainer Penempatan (di luar scope tamu)</td>
</tr>
</table>
---
## 10. Audit Events (enum kanonik PRD Lampiran A)
Nama brief (lowercase) dipetakan ke enum kanonik PRD domain Penempatan:
<table header-row="true">
<tr>
<td>Nama brief</td>
<td>Enum kanonik PRD</td>
<td>Keterangan</td>
</tr>
<tr>
<td>placement_create</td>
<td>`PC_CREATED`</td>
<td>Draft dibuat</td>
</tr>
<tr>
<td>submit</td>
<td>`PC_SUBMITTED`</td>
<td>Submit approval</td>
</tr>
<tr>
<td>approve</td>
<td>`PC_APPROVED`</td>
<td>Disetujui → Aktif</td>
</tr>
<tr>
<td>reject</td>
<td>`PC_REJECTED`</td>
<td>Ditolak → Draft</td>
</tr>
<tr>
<td>activate</td>
<td>(tergabung `PC_APPROVED`)</td>
<td>Aktivasi = efek approve, bukan event terpisah</td>
</tr>
<tr>
<td>archive</td>
<td>`CONTAINER_ARCHIVED`</td>
<td>Auto-archive (§13)</td>
</tr>
<tr>
<td>cancel</td>
<td>`PC_CANCELLED`</td>
<td>Pembatalan (pre-Aktif / Aktif kosong)</td>
</tr>
<tr>
<td>participation_add (normal)</td>
<td>`BATCH_SENT`</td>
<td>Tarik kandidat batch dari Wawancara</td>
</tr>
<tr>
<td>participation_add_fm</td>
<td>`FORCE_MAJEUR_ADDED`</td>
<td>Partisipasi FM aktif (atomik)</td>
</tr>
<tr>
<td>fm_alasan_recorded</td>
<td>(payload `FORCE_MAJEUR_ADDED`)</td>
<td>Kategori + alasan 2-lapis tercatat di payload, bukan event terpisah</td>
</tr>
<tr>
<td>fm_approve / fm_reject</td>
<td>lifecycle `pending_request` FM + `FORCE_MAJEUR_ADDED`/`FM_REJECTED`</td>
<td>Approve memicu `FORCE_MAJEUR_ADDED`; tolak → `FM_REJECTED`</td>
</tr>
<tr>
<td>participation_remove</td>
<td>`PLACEMENT_EXPEL_REQUESTED`/`_APPROVED`/`_REJECTED`</td>
<td>Cabut Penempatan = `Dikeluarkan` (step-up)</td>
</tr>
<tr>
<td>(ubah status partisipasi)</td>
<td>`PLACEMENT_STATUS_CHANGED`</td>
<td>Selesai Kontrak / jalur status umum</td>
</tr>
<tr>
<td>(mengundurkan diri)</td>
<td>`RESIGN_REQUESTED`/`_APPROVED`/`_REJECTED`</td>
<td>Approval tanpa step-up</td>
</tr>
</table>
---
## 11. Step-up Re-auth
Mengikuti PRD §4.6/Lampiran D + ROLES §8.2 + MODULE_AUTH (semua FINAL), **bukan** daftar brief:
- **Memicu step-up:** **Cabut Penempatan** (approve `Dikeluarkan`/`PLACEMENT_EXPEL` setelah Aktif) — aksi berisiko & ireversibel.
- **TIDAK memicu step-up (approval rutin):** approve/tolak kontainer, batalkan kontainer, approve Force-Majeur, approve Mengundurkan Diri.
- Mekanisme: re-auth password+TOTP, TTL 5 menit, per-aksi (MODULE_AUTH).
> ⚠️ Brief misi meminta step-up untuk Approve/Reject/Cancel + Cabut Penempatan. Ini **bertentangan** dengan dependency final; modul mengikuti dependency (hanya Cabut Penempatan). Lihat §16-1 / GAP-P1.
---
## 12. Konkurensi (PRD §7.10)
- **Optimistic locking** kolom `version` pada `placement_container` & `placement_participants`: `UPDATE ... SET version=version+1 WHERE id=:id AND version=:v`; 0 baris → konflik → **HTTP 409** (BR-CON-01/02, ARCH D8).
- **Pessimistic ****`SELECT ... FOR UPDATE`** untuk approval batch kandidat dari Kontainer Wawancara (BR-CON-03). READ COMMITTED (default PG): lock baris terpilih hingga commit, mencegah dua kontainer menarik kandidat sama. **Tanpa ****`SKIP LOCKED`** (butuh konsistensi, bukan lewati).
- **Anti double-approval / double-pull:** verifikasi `pending_request` `pending` & status partisipasi wawancara di dalam transaksi sebelum commit.
- **Deadlock:** `DB::transaction(fn () => ..., $attempts = 3)` untuk auto-retry; urutkan akunisisi lock secara konsisten (kontainer → partisipasi).
- Batas batch 50 (§4) membatasi jumlah baris terkunci sekaligus.
---
## 13. Auto-archive
**Pemicu (terverifikasi PRD §6.4 Sub-flow 6 + Lampiran B.2 + STATUS_STATE_MACHINE — BUKAN GAP):**
> Kontainer `Aktif` → `Arsip` **otomatis** ketika kandidat ber-`status_penempatan='Bekerja'` **terakhir** mencapai status terminal (`Selesai Kontrak`/`Mengundurkan Diri`/`Dikeluarkan`). Pengecekan dilakukan **setelah seluruh batch transisi diproses** (anti-arsip prematur), **bukan** per-kandidat.
**Bukan berbasis target tercapai dan bukan berbasis tanggal.** Kontainer `Aktif` yang belum pernah punya kandidat tidak diarsip — keluar lewat escape `Aktif → Dibatalkan` (count=0 + approval, GAP-4).
**Mekanisme (keputusan §16-3): sinkron utama + sweeper harian.**
1. **Sinkron (utama):** di akhir transaksi perubahan status partisipasi, hitung `count(status_penempatan='Bekerja')`. Bila 0 dan kontainer `Aktif` → transisi ke `Arsip` + `CONTAINER_ARCHIVED` dalam transaksi yang sama. Idempotensi dijamin guard state machine (`Arsip` hanya dari `Aktif`).
2. **Sweeper (jaring pengaman):** scheduled command harian (queue redis) menyapu kontainer `Aktif` tanpa partisipasi `Bekerja` yang lolos dari jalur sinkron. **Idempotensi** mengandalkan **guard transisi + unique state** (Redis lock opsional sebagai bantu; bukan pengganti DB).
---
## 14. Edge Cases
1. Dua kontainer menarik kandidat sama bersamaan → `FOR UPDATE` membuat satu menunggu; kandidat sudah `Sedang Dipakai` → operasi kedua gagal/skip baris itu.
2. Approve FM saat kandidat sudah tidak `Tersedia` (terpakai di tempat lain) → ditolak dalam transaksi (rollback).
3. Kandidat terakhir `Bekerja` di-`Dikeluarkan` → step-up → setelah commit, cek arsip → kontainer auto-`Arsip`.
4. Batch berisi sebagian kandidat tidak valid → seluruh batch rollback (atomik).
5. Edit kontainer Draft sementara user lain submit → 409 versi.
6. Cancel kontainer Aktif yang masih punya partisipasi → ditolak (hanya escape count=0).
7. `source_participation_id` diisi tetapi alasan FM juga diisi → ditolak CHECK.
8. Step-up kedaluwarsa (>5 mnt) saat approve Cabut Penempatan → minta re-auth ulang, transaksi batal.
9. `tanggal_berakhir_kontrak` di-override lebih awal dari `tanggal_mulai_kerja` → validasi tolak.
10. Sweeper & jalur sinkron mencoba arsip kontainer sama → transisi kedua no-op karena status sudah `Arsip` (guard).
---
## 15. Test Plan (ringkas)
- **State machine kontainer:** tiap transisi sah lulus; terlarang ditolak; `Arsip`/`Dibatalkan` terminal; escape Aktif-kosong hanya saat count=0.
- **Partisipasi:** transisi `Bekerja` → tiga terminal; `markAvailable` terpicu; `Selesai Kontrak` tanpa approval; `Mengundurkan Diri` butuh approval+catatan; `Dikeluarkan` butuh approval+step-up+alasan 2-lapis.
- **Force-Majeur:** CHECK alasan; operasi atomik (rollback bila notif/audit gagal); approval tanpa step-up; audit `FORCE_MAJEUR_ADDED` payload lengkap.
- **Batch normal:** submit membuat pending+payload; approve atomik; source→`Terkirim`; availability tetap `Sedang Dipakai` tanpa flip `markInUse`; ownership dan no-`Bekerja` direvalidasi; cap 50; audit `BATCH_SENT`.
- **Konkurensi:** 409 optimistic; bulk pull `FOR UPDATE`; anti double-approval; deadlock retry.
- **Auto-archive:** arsip hanya setelah `Bekerja` terakhir terminal & setelah batch; sweeper idempoten; kontainer kosong tidak terarsip.
- **Step-up:** Cabut Penempatan tanpa step-up → ditolak; approval lain tanpa step-up → sukses.
- **Audit:** setiap aksi mencatat enum kanonik benar.
- **Persistence:** immutability `perusahaan_id`; partial unique satu `Bekerja` per kandidat.
---
## 16. Keputusan Terbuka (diputuskan user 2026-06-29) & GAP PRD
- **§16-1 / GAP-P1 (step-up):** Brief minta step-up pada Approve/Reject/Cancel + Cabut Penempatan; dependency final hanya **Cabut Penempatan**. **Keputusan user: ikuti dependency.** Versi brief perlu SUPERSEDES + update PRD.
- **§16-2 (penamaan kolom ref):** Brief & MODULE_CANDIDATES pakai `ref_riwayat_partisipasi`; PRD §5.4 pakai `source_participation_id`. **Keputusan user: pakai ****`source_participation_id`** sebagai kanonik pada `placement_participants`. (Tabel `candidate_participation` di MODULE_CANDIDATES tetap dengan namanya sendiri — entitas berbeda; perlu dicatat agar DATABASE_SCHEMA konsisten.)
- **§16-3 (mekanisme auto-archive):** **Keputusan user: sinkron in-transaction (utama) + scheduled command harian (jaring pengaman).** Idempotensi via guard state + unique, bukan lock Redis.
- **§16-4 (format alasan Force-Majeur):** **Keputusan user: kategori (lookup ****`kategori_force_majeur`****) + free text wajib.** **Affects MODULE_LOOKUP_DATA** — perlu menambah master lookup `kategori_force_majeur` (CRUD Super Admin). Ditandai untuk koordinasi.
- **§16-5 (batas bulk pull):** **Keputusan user: maksimum 50 kandidat per operasi.**
- **Auto-archive trigger — BUKAN GAP:** PRD §6.4 Sub-flow 6 + Lampiran B.2 mengunci pemicu (kandidat `Bekerja` terakhir → terminal, dicek setelah batch). Bukan target/tanggal. Didokumentasikan eksplisit §13.
- **Koreksi rujukan PRD (bukan perubahan isi):** atribut = §5.4 (bukan "§7 Tabel 8"); Force-Majeur = §6.4 Sub-flow 2b (bukan "§9.3"); konkurensi = §7.10 (bukan "§9").
