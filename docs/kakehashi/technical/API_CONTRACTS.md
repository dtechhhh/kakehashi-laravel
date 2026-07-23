---
title: "API_CONTRACTS"
status: "FINAL v1.2"
source_notion_title: "API_CONTRACTS"
exported_at: "2026-07-15"
authority_rank: "technical"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Historical labels may remain in source text; follow PRD v0.3.14, Batch A/B, and the repository authority order. Stop if a conflict is suspected.

# API_CONTRACTS

> [!NOTE]
> **API_**[**CONTRACTS.md**](API_CONTRACTS.md)** — Kakehashi (Kelompok 4 · Operasional).** Status: **FINAL v1.0**. Konsolidasi kontrak **public service / facade** antar-modul (bukan OpenAPI, bukan REST gateway). Sumber: ARCHITECTURE D2/D4/D8/D10 + modul FINAL + PRD v0.3.13. Persona: API/Interface Designer. Tgl: 2026-07-13.
>
## 0. Tujuan
1. Satu tempat daftar **public service** yang boleh dipanggil lintas-modul.
2. Input/output ringkas + error HTTP standar.
3. Batas tegas: **tidak** akses Model/tabel lintas-modul.
4. Acuan coding agent agar tidak “tembak langsung” ke DB modul lain.
**Bukan:** daftar route UI Livewire, OpenAPI/Swagger, skema kolom DB, tabel transisi status.
---
## 1. Prinsip & larangan
<table header-row="true">
<tr>
<td>Aturan</td>
<td>Isi</td>
</tr>
<tr>
<td>Arah dependency</td>
<td>Jobs / Placement / Guest → **public service** Candidates / Lookup / Auth</td>
</tr>
<tr>
<td>Larangan</td>
<td>`use Modules\X\Models\...` lintas modul; query/FK lintas-domain tanpa kontrak service (PRD §9.7)</td>
</tr>
<tr>
<td>Transaksi</td>
<td>Write lintas-agregat (bulk pull, batch kirim, force-majeur) dalam **satu DB transaction**</td>
</tr>
<tr>
<td>AuthZ</td>
<td>Pemanggil tetap cek Policy/permission; public service bukan bypass RBAC</td>
</tr>
<tr>
<td>Tamu</td>
<td>Hanya lewat read-model `GuestCandidateView` (G2/G3)</td>
</tr>
<tr>
<td>Ketersediaan</td>
<td>Hanya lewat `markInUse` / `markAvailable` — tidak UPDATE kolom ketersediaan lintas-modul</td>
</tr>
</table>
---
## 2. Error HTTP standar
<table header-row="true">
<tr>
<td>Kode</td>
<td>Kapan</td>
</tr>
<tr>
<td>**403**</td>
<td>Role/permission gagal; step-up belum; token tamu ditolak</td>
</tr>
<tr>
<td>**409**</td>
<td>Optimistic lock (`version`); double-approval; race ketersediaan</td>
</tr>
<tr>
<td>**422**</td>
<td>Validasi form/bisnis; guard state gagal</td>
</tr>
<tr>
<td>**410**</td>
<td>Opsional untuk resource kadaluarsa; surface Tamu boleh **403 generik** anti-enumerasi</td>
</tr>
</table>
Surface Tamu: prefer **pesan generik** (jangan bocorkan apakah token/kode yang salah).
---
## 3. Candidates — public services
### 3.1 `CandidateAvailabilityService`
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output / efek</td>
<td>Error</td>
</tr>
<tr>
<td>`isAvailableAndApproved(candidateId)`</td>
<td>id</td>
<td>`bool`</td>
<td>—</td>
</tr>
<tr>
<td>`markInUse(candidateId, version)`</td>
<td>id + version</td>
<td>void; **hanya** untuk pull Wawancara/Force-Majeur: Tersedia → Sedang Dipakai</td>
<td>**409** version; **422** jika bukan Tersedia+Disetujui</td>
</tr>
<tr>
<td>`assertInUse(candidateId, version)`</td>
<td>id + version</td>
<td>void; assertion availability=`Sedang Dipakai`, tanpa mutasi</td>
<td>**409** version; **422** bila state tidak cocok</td>
</tr>
<tr>
<td>`markAvailable(candidateId, version)`</td>
<td>id + version</td>
<td>void; ketersediaan → **Tersedia**</td>
<td>**409**</td>
</tr>
</table>
Pemanggil utama: **Jobs** (pull), **Placement** (Force-Majeur/terminal). Pada batch normal Placement, gunakan `assertInUse`—bukan `markInUse`—karena availability tetap `Sedang Dipakai` selama transfer ownership.
### 3.2 `CandidateReadService` (internal modul operasional)
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output</td>
</tr>
<tr>
<td>`getMasterSnapshot(candidateId)`</td>
<td>id</td>
<td>Data master **read-only** untuk Lapisan 2 Jobs/Placement</td>
</tr>
<tr>
<td>`assertNotAnonymized(candidateId)`</td>
<td>id</td>
<td>void; throw/422 jika `pii_anonymized_at` terisi</td>
</tr>
</table>
### 3.3 `GuestCandidateReadModel`
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output</td>
</tr>
<tr>
<td>`listForContainer(containerId)`</td>
<td>`interview_container_id`  • sesi token valid</td>
<td>List **G2** (pseudonim: Nomor Induk, tanpa nama/foto)</td>
</tr>
<tr>
<td>`detailForGuest(containerId, candidateId, tokenContext)`</td>
<td>  • sesi token valid</td>
<td>**G3** whitelist saja (nama, foto signed URL, riwayat kerja/pendidikan penuh, dll. sesuai PRD Lampiran C)</td>
</tr>
</table>
Aturan:
- Sort/filter Tamu hanya kolom aman.
- Nama / foto / nama lembaga / nama perusahaan **bukan** parameter filter/sort.
- Baris ter-anonimisasi **tidak dikembalikan** oleh G2/G3; direct detail request ditolak generik.
---
### 3.4 Jobs — `InterviewPlacementTransferService`
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output / guard</td>
</tr>
<tr>
<td>`assertReadyForPlacement(participationId, candidateId)`</td>
<td>source participation + candidate</td>
<td>void; wajib `Siap Dikirim`, source aktif milik kandidat</td>
</tr>
<tr>
<td>`markSentForPlacement(participationId, candidateId, version)`</td>
<td>source + candidate + version</td>
<td>source→`Terkirim`; hanya dipanggil dalam approval batch atomik</td>
</tr>
</table>
Placement menggabungkan service ini dengan `CandidateAvailabilityService::assertInUse()` dan pemeriksaan no-placement-`Bekerja`. Transfer normal tidak memiliki window `Tersedia`.
## 4. Lookup — `LookupService`
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output</td>
</tr>
<tr>
<td>`resolve(table, code, lang?)`</td>
<td>nama tabel + code + locale opsional</td>
<td>Label bilingual (fallback berjenjang → code)</td>
</tr>
<tr>
<td>`options(table, lang?)`</td>
<td>table</td>
<td>Map `code → label` hanya `is_active=true`</td>
</tr>
<tr>
<td>`assertActive(table, code)`</td>
<td>table + code</td>
<td>void; **422** jika nonaktif/tidak ada</td>
</tr>
</table>
Cache production: **Redis** (key per-tabel per-bahasa); invalidasi on write. Cache bukan sumber kebenaran.
Enum Kelas 1 (gender, marital, dll.) **bukan** lewat LookupService — pakai PHP backed enum.
---
## 5. Auth — `StepUpService`
<table header-row="true">
<tr>
<td>Method</td>
<td>Input</td>
<td>Output</td>
</tr>
<tr>
<td>`require(action, entityType, entityId)`</td>
<td>aksi sensitif + target</td>
<td>void jika elevasi valid (TTL 5 mnt, per-aksi); **403** jika belum</td>
</tr>
</table>
Pemicu step-up final (PRD Lampiran D):
1. ubah role / nonaktifkan akun
2. setujui tutup kontainer wawancara
3. setujui keluarkan/cabut kandidat
4. kelola lookup / master perusahaan
5. anonimisasi PII
Approval rutin **tidak** memanggil StepUp.
---
## 6. Shared infrastructure contracts
### 6.0 `PendingRequestService`
```plain text
submit(type, targetType, targetId, payload?)
approve(requestId, checkerId, note?)
reject(requestId, checkerId, note)
```
- Sumber keputusan Checker untuk seluruh approval domain.
- Status submission+pending dibuat satu transaksi.
- Satu pending aktif per `(type,targetType,targetId)`.
- Payload wajib untuk `PLACEMENT_BATCH`, `FORCE_MAJEUR`, expel, resign, cancel.
- `lookup_request` dan `company_request` tetap terpisah.
### 6.1 `AuditLogger`
```plain text
record(actionType, entityType, entityId?, detail?, actorId?, ip?, userAgent?)
```
- Immutable insert ke `audit_log`
- Isi `actor_role_snapshot` saat kejadian
- Enum `action_type` = PRD Lampiran A / DATABASE_SCHEMA §7
### 6.2 `NotificationService`
```plain text
notifyInApp(userIds, type, payload)
queueEmailAfterCommit(userIds, type, payload)
```
- Kebenaran bisnis + audit + notifikasi in-app DB commit terlebih dahulu.
- Email Redis hanya after-commit; gagal enqueue dicatat dan tidak me-rollback transaksi bisnis.
- In-app dibaca via polling ≤60 dtk.
### 6.3 `DocumentLinkAuditService`
```plain text
revealLink(candidateId, candidateDocumentId, actorId)
```
- Cek Policy lalu catat `IDENTITY_DOC_VIEWED` sebelum mengembalikan URL Drive.
- Event berarti link diungkap/dibuka melalui aplikasi, bukan bukti file dibaca di Drive.
### 6.4 `FileStorageService`
<table header-row="true">
<tr>
<td>Method</td>
<td>Kegunaan</td>
</tr>
<tr>
<td>`storeCandidatePhoto(...)`</td>
<td>Upload R2 privat + metadata</td>
</tr>
<tr>
<td>`temporaryUrl(objectKey, ttlSeconds)`</td>
<td>Signed URL 5–15 mnt</td>
</tr>
<tr>
<td>`deleteObject(objectKey)`</td>
<td>Hapus saat anonimisasi / ganti foto</td>
</tr>
</table>
Dokumen peserta = URL Google Drive di domain Kandidat, **bukan** lewat FileStorage upload.
---
## 7. Internal domain events (sinkron, in-process)
Event **bukan** message bus. Listener jalan dalam proses (sering dalam transaksi yang sama).
<table header-row="true">
<tr>
<td>Event (contoh)</td>
<td>Side effect tipikal</td>
</tr>
<tr>
<td>CandidateSubmitted / Approved / Rejected</td>
<td>Audit + notifikasi antrian</td>
</tr>
<tr>
<td>CandidatePulled</td>
<td>`markInUse` (sudah di use-case) + audit</td>
</tr>
<tr>
<td>ParticipationStatusChanged</td>
<td>Audit (+ notif bila perlu)</td>
</tr>
<tr>
<td>BatchSent / ForceMajeurAdded</td>
<td>Batch normal: transfer source + `assertInUse`; Force-Majeur: `markInUse`; audit DB + notif in-app, email after-commit</td>
</tr>
<tr>
<td>PlacementTerminalReached</td>
<td>`markAvailable`  • cek arsip kontainer</td>
</tr>
<tr>
<td>GuestLinkAccessed / GuestDetailViewed</td>
<td>`guest_access_log`  • audit</td>
</tr>
<tr>
<td>InterviewContainerClosed</td>
<td>Freeze partisipasi + `markAvailable` kandidat aktif</td>
</tr>
</table>
---
## 8. Matriks pemanggil
<table header-row="true">
<tr>
<td>Pemanggil</td>
<td>Boleh panggil</td>
</tr>
<tr>
<td>**Jobs**</td>
<td>CandidateAvailability, CandidateRead, Lookup, StepUp, Audit, Notification</td>
</tr>
<tr>
<td>**Placement**</td>
<td>CandidateAvailability (`assertInUse` normal; `markInUse` FM), InterviewPlacementTransfer, Lookup, StepUp, Pending, Audit, Notification</td>
</tr>
<tr>
<td>**Guest Access**</td>
<td>GuestCandidateReadModel, FileStorage (signed URL foto), Audit</td>
</tr>
<tr>
<td>**Candidates**</td>
<td>Lookup, Audit, Notification, FileStorage — **tidak** akses tabel Jobs/Placement</td>
</tr>
<tr>
<td>**Auth / Lookup**</td>
<td>Audit; Lookup **tidak** panggil domain kandidat</td>
</tr>
</table>
---
## 9. Yang sengaja tidak dimasukkan
- Method private internal tiap modul
- DTO field-by-field penuh (rujuk DATABASE_SCHEMA + GuestCandidateView)
- Versioning API publik `/v1`
- GraphQL / webhook eksternal
- REST resource map 1:1 ke halaman UI
---
## 10. Handoff coding
1. Letakkan public service di folder `Public/` tiap modul (ARCHITECTURE §5.1).
2. Implementasi dulu **Availability + Guest read-model + Lookup + StepUp** — paling sering dipanggil lintas-modul.
3. Uji kontrak: bulk pull race (409), double-approval (409), guest field HIDE, step-up 403.
4. Security enforcement tetap di `SECURITY_CHECKLIST` (kontrak ini tidak menggantikan RBAC).
---
## 11. Definisi Selesai (FINAL)
- [x] Prinsip & larangan lintas-modul
- [x] Katalog public service Candidates / Lookup / Auth / Guest / Shared
- [x] Error HTTP standar
- [x] Event internal ringkas + matriks pemanggil
- [x] Tanpa OpenAPI / over-engineering
---
*Status: FINAL v1.2 — Batch B 2026-07-14. Selaras PRD_Kakehashi_v0_3_14 + ARCHITECTURE + modul FINAL.*
