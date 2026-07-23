---
title: "ARCHITECTURE"
status: "FINAL"
source_notion_title: "ARCHITECTURE"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# ARCHITECTURE

> [!NOTE]
> [**ARCHITECTURE.md**](ARCHITECTURE.md)** — Kakehashi.** Fondasi teknis arsitektur (modular monolith Laravel) untuk seluruh agent modul & DATABASE_SCHEMA. **Sumber kebenaran tertinggi = PRD Kakehashi v0.3.14.** Jika konflik, PRD berlaku. Dependency final: PROJECT_OVERVIEW (final), GLOSSARY (final), DECISIONS_LOG.
> **Status: FINAL (disetujui user 2026-06-29).** Versi tech terverifikasi live 2026-06-29 (Asia/Jakarta).
>
## 0. Cara baca & disiplin scope
- File ini mendefinisikan **gaya arsitektur, batas modul, kontrak antar-lapisan, dan cross-cutting** — bukan detail implementasi.
- **HINDARI di sini (milik file lain):** skema tabel rinci → `DATABASE_SCHEMA.md`; aturan bisnis rinci → `BUSINESS_RULES.md`; transisi status rinci → `STATUS_STATE_MACHINE.md`; RBAC rinci → `ROLES_AND_PERMISSIONS.md`; keputusan frontend → `UI_WIREFRAME_NOTES.md`.
- Rujukan PRD memakai penomoran PRD v0.3 aktual (dikoreksi & disetujui user 2026-06-29): audit = **Lampiran A / §5.1 / §7.11**, kontrak antar-modul = **§9.7**, konkurensi = **§7.10**, notifikasi = **§8.4 / §9.6**, file storage = **§9.8 / §9.1**, i18n = **§9.4**.
---
## 1. Prinsip Arsitektur
1. **Monolith first, modular always.** Satu codebase, satu database PostgreSQL, satu proses deploy; modularitas adalah batas *logis*, bukan jaringan (PRD §9.6, §9.7).
2. **Muat di VPS baseline.** Setiap keputusan harus jalan di VPS **4 vCPU / 8 GB** (single VPS, no HA). **Redis co-located** diizinkan untuk cache/session/queue/rate-limit; **tanpa websocket** di MVP (PRD §9.6 v0.3.12).
3. **Batas modul tegas.** Modul domain tidak saling menerobos tabel/Model; komunikasi hanya lewat *public service/facade* (PRD §9.7).
4. **Store canonical, render localized.** Nilai kanonik (enum/code) di DB; lokalisasi ID/JP di presentation layer (PRD §9.4).
5. **Auditable & aman by design.** Aksi sensitif tercatat di satu jalur audit; PII dilindungi (PRD Lampiran A, §7.9, §9.1).
6. **Konsistensi via transaksi DB**, bukan infrastruktur eksternal (PRD §7.10).
---
## 2. Gaya Arsitektur: Modular Monolith
**Keputusan D1:** mekanisme modularisasi memakai **Composer path repositories** (`app-modules/`) dengan Laravel package discovery — pendekatan ringan & native (`internachi/modular` 2.x). Alternatif `nwidart/laravel-modules` \^13.0 ditolak karena overhead konvensi berlebih untuk 6 modul tetap.
```plain text
┌─────────────────────────────────────────────────────────────┐
│                     KAKEHASHI (1 deploy)                      │
│                                                              │
│   MODUL DOMAIN (punya batas tegas)                           │
│   ┌──────────┐ ┌────────┐ ┌───────────┐ ┌───────────────┐    │
│   │Candidates│ │  Jobs  │ │ Placement │ │ Guest Access  │    │
│   │(Kandidat)│ │(Wwncr) │ │(Penemptn) │ │ (Akses Tamu)  │    │
│   └────┬─────┘ └───┬────┘ └─────┬─────┘ └───────┬───────┘    │
│        │           │            │               │            │
│   MODUL PLATFORM (dipakai semua)                             │
│   ┌──────────┐ ┌──────────────┐                              │
│   │   Auth   │ │ Lookup Data  │                              │
│   └──────────┘ └──────────────┘                              │
│        │           │            │               │            │
│   SHARED INFRASTRUCTURE (cross-cutting, bukan modul domain)  │
│   ┌────────┐ ┌──────────────┐ ┌──────────┐ ┌────────────┐    │
│   │ Audit  │ │ Notification │ │FileStore │ │ i18n / Loc │    │
│   └────────┘ └──────────────┘ └──────────┘ └────────────┘    │
│                                                              │
│   PostgreSQL 18 (1 DB)   ·   Redis (cache/queue)   ·  R2   │
└─────────────────────────────────────────────────────────────┘
```
---
## 3. Daftar Modul & Batas Tanggung Jawab
> Penamaan dwi-label (Inggris ⇄ PRD) mengikuti PROJECT_OVERVIEW §5 & GLOSSARY.
<table header-row="true">
<tr>
<td>Modul (file)</td>
<td>Nama PRD</td>
<td>Tanggung jawab</td>
<td>Bukan tanggung jawabnya</td>
</tr>
<tr>
<td>Candidates</td>
<td>Modul Kandidat</td>
<td>CRUD kandidat, alur Maker–Checker, ketersediaan, cek-kemiripan, Nomor Induk (PRD §5.2, §6.2)</td>
<td>Wawancara/Penempatan</td>
</tr>
<tr>
<td>Jobs</td>
<td>Modul Wawancara</td>
<td>Kontainer wawancara, tarik kandidat, status partisipasi, tutup kontainer (PRD §5.3, §6.3)</td>
<td>Edit data master kandidat</td>
</tr>
<tr>
<td>Placement</td>
<td>Modul Penempatan</td>
<td>Kontainer penempatan, batch normal + force-majeur, status penempatan, arsip otomatis (PRD §5.4, §6.4)</td>
<td>Proses wawancara</td>
</tr>
<tr>
<td>Guest Access</td>
<td>Akses Tamu</td>
<td>Link bertoken read-only, `GuestCandidateView` (PRD §4.3, Lampiran C)</td>
<td>Menulis data apa pun</td>
</tr>
<tr>
<td>Auth</td>
<td>Pecahan keamanan/Super Admin</td>
<td>Login, 2FA TOTP, sesi 30 mnt, step-up re-auth, kelola akun (PRD §4.4–§4.6, §6.1)</td>
<td>Aksi operasional</td>
</tr>
<tr>
<td>Lookup Data</td>
<td>Data Referensi/Lookup</td>
<td>Label bilingual `label_id/label_ja/code`, request data baru, master perusahaan (PRD §5.1, §7.8)</td>
<td>Status state machine (hardcode)</td>
</tr>
</table>
---
## 4. Aturan Dependency Antar-Modul (Keputusan D2)
**Pola komunikasi: Public Service/Facade (sinkron) + Internal Domain Events (sinkron, in-process).** Tidak ada akses tabel/Model lintas modul (PRD §9.7).
```plain text
Presentation ─▶ Application ─▶ Domain
      └────────────▶ Infrastructure (DB, R2, Queue, Audit, Notif, i18n)

MODUL DOMAIN boleh memanggil:
  • Shared Infrastructure (Audit, Notification, FileStorage, Localization)
  • PUBLIC SERVICE modul lain (kontrak), TIDAK pernah Model/tabel modul lain
AUTH & LOOKUP DATA  : dipakai semua modul; TIDAK bergantung ke modul domain
GUEST ACCESS        : read-only, hanya via read-model GuestCandidateView
                      (service publik Candidates) — tidak akses domain lain
```
**Aturan tegas:**
- ❌ Dilarang: `use Modules\Candidates\Models\Candidate` dari dalam modul Jobs/Placement.
- ❌ Dilarang: FK lintas-modul tanpa kontrak service (PRD §9.7).
- ✅ Penulisan ketersediaan kandidat **hanya** lewat service publik Candidates (`markInUse()`/`markAvailable()`, PRD §7.1).
- ✅ Audit dan notifikasi in-app DB dipicu sinkron dalam transaksi domain. Email/queue Redis hanya dijadwalkan after-commit; gagal enqueue dicatat dan tidak me-rollback transaksi bisnis.
### 4.1 Contoh Kontrak Service Publik (illustratif, bukan final API)
```plain text
Candidates\Public\CandidateAvailabilityService
  markInUse(candidateId, version): void      // throws ConflictException (409)
  markAvailable(candidateId, version): void
  isAvailableAndApproved(candidateId): bool

Candidates\Public\GuestCandidateReadModel
  forContainer(interviewContainerId): GuestCandidateView[]   // whitelist Lampiran C

Lookup\Public\LookupService
  resolve(category, code): LookupValue        // label_id/label_ja/code
Auth\Public\StepUpService
  requireStepUp(action, actor): void          // password + TOTP (PRD §4.6)
```
> Definisi method final → `API_CONTRACTS.md`. Di sini hanya menetapkan **bahwa** kontrak ada & arah dependency-nya.
---
## 5. Layering & Tempat Business Rules (Keputusan D3)
Tiap modul memakai 4 lapisan (pragmatis, bukan DDD ketat):
<table header-row="true">
<tr>
<td>Lapisan</td>
<td>Isi</td>
<td>Catatan</td>
</tr>
<tr>
<td>**Domain**</td>
<td>Entities, value objects, **state machine classes**, invariant</td>
<td>Tempat aturan inti & status hidup (detail → BUSINESS_RULES, STATUS_STATE_MACHINE)</td>
</tr>
<tr>
<td>**Application**</td>
<td>Use-cases / services / commands, orkestrasi transaksi, pemicu events</td>
<td>Tempat alur Maker–Checker & pending-as-entity dirakit (PRD §7.4)</td>
</tr>
<tr>
<td>**Infrastructure**</td>
<td>Eloquent models/repositories, R2, queue, Audit/Notif adapter</td>
<td>Eloquent = detail infrastruktur</td>
</tr>
<tr>
<td>**Presentation**</td>
<td>Controllers + Livewire 4 / Blade custom / Tailwind 4</td>
<td>Tipis; hanya I/O + lokalisasi tampilan</td>
</tr>
</table>
> Business rules **hidup di Domain + Application**, tidak di controller/Model. Repository dipakai hanya bila perlu testing seam.
### 5.1 Struktur direktori per modul
```plain text
app-modules/
  candidates/
    src/
      Domain/         (Entities, States, ValueObjects)
      Application/     (Services, Commands, Events, Listeners)
      Infrastructure/  (Eloquent Models, Repositories, R2 adapters)
      Presentation/    (Controllers, Requests, Resources)
      Public/          (Service/Facade yang boleh dipanggil modul lain)
    database/migrations/
    routes/
    lang/ (id, ja)
    tests/
```
---
## 6. Shared Infrastructure (Cross-Cutting)
### 6.1 Audit Log Terpusat (Keputusan D4)
**Service audit custom** (bukan package) menulis ke satu tabel `audit_log` sesuai PRD Lampiran A.
- Skema dasar: `actor_id (nullable)`, `action_type`, `entity_type`, `entity_id`, `detail (JSONB)`, `ip (nullable)`, `created_at` (PRD Lampiran A, §5.1).
- Satu titik tulis: `Shared\Audit\AuditLogger::record(action, entity, detail, actor?, ip?)`.
- Dipicu lewat internal events di transaksi aksi → **atomik** (mis. `EXPEL_APPROVED`, `FORCE_MAJEUR_ADDED`, `GUEST_ACCESS`).
- **Immutable**: hanya INSERT; tidak ada UPDATE/DELETE pada baris audit. Super Admin **read-only** (PRD §4.2).
- Enumerasi `action_type` & skema `detail` per event = sumber di PRD Lampiran A (detail lanjutan → `AUDIT_EVENTS.md`/`BUSINESS_RULES.md`).
### 6.2 Notifikasi In-App + Polling (Keputusan D6)
- **Tanpa websocket** (PRD §3.2, §9.6). In-app via tabel notifikasi + **client polling ≤60 detik** (PRD §8.4).
- `Shared\Notification\NotificationService` dipicu via internal events (mis. kandidat masuk antrian approval, aksi ditolak).
- Email kritis tidak dikirim sinkron → masuk queue (lihat §7).
- Boleh memakai Laravel database notification channel sebagai implementasi. UX polling → `UI_WIREFRAME_NOTES.md`.
### 6.3 File Storage (Keputusan D7 — direvisi 2026-07-01, PRD v0.3.9)
> **Perubahan 2026-07-01:** dokumen peserta kini **link Google Drive privat** (URL input), bukan upload+envelope. R2 hanya untuk **foto wajah**; **video** = embed URL.
- **Foto wajah:** disk `s3` (Flysystem 3.x) → **bucket privat R2 + SSE at-rest**; akses via **signed URL pendek 5–15 menit** (`temporaryUrl`); batas ≤5MB (jpg/png/webp), validasi MIME asli (PRD §9.1, §9.8).
- **Dokumen peserta** (`candidate_document`: KTP/KK/Ijazah/Kartu Zairyu/dll) & sertifikat/kualifikasi: **link Google Drive privat** ("tidak diset public") — URL input, **bukan** upload ke aplikasi; **tanpa** envelope encryption/R2/signed URL. Berlaku untuk semua jenis URL file.
- **Video** (Jikoshokai/Keahlian): **embed URL**.
- ~~Envelope encryption app-level dokumen identitas~~ **DIHAPUS** (PRD v0.3.9) — tidak dipakai lagi.
- ⚠️ **Caveat WAJIB diuji (khusus foto R2):** aws-sdk-php baru mengaktifkan checksum crc32 yang belum didukung R2 → set integrity `WHEN_REQUIRED` & `retain_visibility=false` di config disk.
- File untuk Tamu = whitelist eksplisit *shareable*; foto thumbnail via signed URL terikat sesi token; sertifikat shareable = link Google Drive **"anyone with link"** (opsi paling sederhana, PRD §9.8, Lampiran C).
- `FileStorageService` hanya untuk foto R2 (`storePhoto`/`temporaryUrl`/`deleteObject`). Dokumen peserta adalah URL Google Drive di domain Kandidat, bukan upload/enkripsi FileStorage.
### 6.4 i18n / Lokalisasi (Keputusan D9)
- **Store canonical enum/code, render glyph** (PRD §9.4, GLOSSARY).
- Nilai kanonik (mis. `M`/`F`, `MARRIED`/`SINGLE`) di DB; render `男`/`女`, `既婚`/`未婚`, format `YYYY年MM月DD日` di presentation layer.
- UI string via Laravel localization (`lang/id`, `lang/ja`). Lookup bilingual `label_id/label_ja/code`; perusahaan `nama_ja` wajib.
- Glyph kanonik = sumber di GLOSSARY (Lampiran Glyph). Skema penyimpanan → `DATABASE_SCHEMA.md`.
### 6.5 Konkurensi (Keputusan D8)
- **Optimistic locking** via kolom `version (integer)` pada agregat mutable (kandidat + draft, kontainer wawancara/penempatan, partisipasi, placement_participants). Setiap UPDATE `WHERE version = current`; konflik → **HTTP 409 + reload UI** (PRD §7.10).
- **Pessimistic locking** (`SELECT ... FOR UPDATE`) **khusus** penarikan kandidat bulk ke kontainer wawancara (anti race ganda).
- **Anti double-approval:** verifikasi `pending_request` masih `pending` **di dalam transaksi yang sama** sebelum mengubah status agregat (PRD §7.10).
- ARCHITECTURE menetapkan **pola**; nilai/kolom rinci → `DATABASE_SCHEMA.md`.
---
## 7. Strategi Async / Queue (Keputusan D5 — direvisi 2026-07-13)
- **Redis co-located** di VPS yang sama (bind [localhost](http://localhost); tidak diekspos publik) memakai `maxmemory-policy noeviction` karena satu instance menampung cache/session/queue/rate-limit. Cache wajib TTL dan pemakaian memory dimonitor.
- **Queue driver = ****`redis`** + **2 worker** dijaga **Supervisor** dengan `--max-time` & `--max-jobs` (PRD §9.6 v0.3.12).
- Cache, session, rate-limit production = **Redis**. Tabel `cache`/`sessions`/`jobs` tetap boleh ada sebagai fallback non-prod.
- Yang di-queue: **email kritis saja**. Kebenaran bisnis + audit + notifikasi in-app DB commit dahulu; email Redis didispatch after-commit. Kegagalan enqueue tidak membatalkan transaksi bisnis dan dicatat untuk retry manual.
- ⚠️ Anti-duplikasi bisnis tetap mengandalkan **unique constraint DB + transaksi**. Redis lock/`withoutOverlapping` boleh membantu, **bukan** mengganti jaminan DB.
- Setup Supervisor, Redis, deployment → `DEPLOYMENT.md`.
---
## 8. Cross-Cutting Lain (Keputusan D10)
### 8.1 Error Handling & Semantik HTTP
<table header-row="true">
<tr>
<td>Kondisi</td>
<td>Respons</td>
<td>Sumber</td>
</tr>
<tr>
<td>Konflik versi (optimistic lock)</td>
<td>**409 Conflict**  • minta reload</td>
<td>PRD §7.10</td>
</tr>
<tr>
<td>Akses ditolak peran / step-up belum dipenuhi</td>
<td>**403 Forbidden**</td>
<td>PRD §4.6, Lampiran D</td>
</tr>
<tr>
<td>Validasi form gagal</td>
<td>**422 Unprocessable**</td>
<td>—</td>
</tr>
<tr>
<td>Token tamu invalid/kadaluarsa</td>
<td>**403/410**</td>
<td>PRD §7.7</td>
</tr>
</table>
### 8.2 Config & Feature Isolation
- Tiap modul punya service provider + config sendiri; tidak ada config global yang membocorkan detail modul.
- 6 role **hardcode**; status state machine **hardcode** (PRD §4.1, §7.8) — bukan data lookup.
- Out-of-scope (PRD §3.2) tidak boleh "bocor" sebagai stub: Keuangan, Kelas/Pelatihan, Report tahunan, Generate CV, manajemen tipe role, push/websocket.
### 8.3 Testing Seams
- Uji per modul lewat **public service interface** + factory per modul.
- Transaksi atomik (batch penempatan, force-majeur, pull bulk) wajib punya test konkurensi (PRD §7.10).
- Audit & notifikasi diuji lewat event assertion (in-process).
---
## 9. Tabel Verifikasi Teknologi (live 2026-06-29)
> Keyakinan: T=Tinggi, S=Sedang. Versi mayor mengikuti TECH_VERSION_SEED; minor wajib dicek ulang saat implementasi.
<table header-row="true">
<tr>
<td>Teknologi</td>
<td>Versi</td>
<td>Status</td>
<td>Keyakinan</td>
</tr>
<tr>
<td>PHP</td>
<td>8.4.x</td>
<td>Active s/d Des 2026, security s/d 2028</td>
<td>T</td>
</tr>
<tr>
<td>Laravel</td>
<td>13.x</td>
<td>Stable (PHP 8.3–8.5)</td>
<td>T</td>
</tr>
<tr>
<td>PostgreSQL (+pg_trgm)</td>
<td>18.x</td>
<td>Stable; GIN/JSONB tersedia</td>
<td>T</td>
</tr>
<tr>
<td>internachi/modular</td>
<td>2.x</td>
<td>Aktif; path repositories, native discovery</td>
<td>S–T</td>
</tr>
<tr>
<td>Redis + queue redis + Supervisor (2 worker)</td>
<td>Redis 7.x · Laravel 13</td>
<td>Aktif; co-located VPS 4C/8G (2026-07-13)</td>
<td>T</td>
</tr>
<tr>
<td>Laravel Events/Listeners (sync)</td>
<td>bawaan Laravel 13</td>
<td>Aktif; in-process tanpa Redis</td>
<td>T</td>
</tr>
<tr>
<td>Audit log (service custom, JSONB)</td>
<td>pola</td>
<td>Selaras PRD Lampiran A</td>
<td>T</td>
</tr>
<tr>
<td>league/flysystem-aws-s3-v3</td>
<td>3.x</td>
<td>Aktif; driver s3 → R2</td>
<td>T</td>
</tr>
<tr>
<td>⚠️ R2 + aws-sdk-php checksum</td>
<td>`WHEN_REQUIRED`</td>
<td>WAJIB diuji</td>
<td>S</td>
</tr>
<tr>
<td>Optimistic locking (kolom version)</td>
<td>pola Eloquent</td>
<td>Standar</td>
<td>T</td>
</tr>
</table>
---
## 10. Trade-off & Batas Infra (eksplisit)
- **Single VPS, no HA** (uptime target 99%, PRD §9.5) → SPOF; mitigasi: backup harian R2 + runbook (→ `BACKUP_AND_RECOVERY.md`).
- **Redis co-located** (bukan managed Redis terpisah) → cukup untuk MVP; restart Redis tidak boleh merusak kebenaran data (DB = source of truth).
- **Polling ≤60 dtk** (bukan realtime) → trade-off hemat sumber daya vs latensi notifikasi (sesuai PRD); WebSocket ditunda post-MVP.
- **2 worker queue** → email kritis + headroom ringan; skala worker/vertikal dulu sebelum multi-server (PRD §9.3).
- Semua komponen di atas **MUAT** di **4 vCPU / 8 GB** dengan alokasi kasar: PG \~2–2.5GB, PHP-FPM \~1.5–2GB, Redis ≤1GB, worker+OS sisa.
---
## 11. GAP PRD yang Diselesaikan (disetujui user 2026-06-29)
<table header-row="true">
<tr>
<td>GAP</td>
<td>Isi</td>
<td>Resolusi</td>
</tr>
<tr>
<td>GAP-1</td>
<td>PRD §9.7 diam soal internal events</td>
<td>D2: public service + internal events sinkron (in-process)</td>
</tr>
<tr>
<td>GAP-2</td>
<td>Mekanisme audit log tak ditentukan</td>
<td>D4: service audit custom sesuai Lampiran A</td>
</tr>
<tr>
<td>GAP-3</td>
<td>Package modularisasi tak disebut</td>
<td>D1: internachi/modular (path repositories)</td>
</tr>
<tr>
<td>GAP-4</td>
<td>Pola transaksi lintas-agregat di luar pull</td>
<td>D8: optimistic + pessimistic + anti double-approval di transaksi</td>
</tr>
</table>
---
## 12. Dependency & Handoff
- **Hulu:** PRD v0.3, PROJECT_OVERVIEW (final), GLOSSARY (final), DECISIONS_LOG, TECH_VERSION_SEED.
- **Hilir (handoff):**
	- `DATABASE_SCHEMA.md` — kolom `version`, tabel `audit_log`/`notifications`/`jobs`, lokasi migration per modul, penyimpanan enum kanonik.
	- `BUSINESS_RULES.md` & `STATUS_STATE_MACHINE.md` — aturan & state classes hidup di Domain layer.
	- `API_CONTRACTS.md` — definisi final public service per modul.
	- `MODULE_*` — ikuti struktur direktori §5.1 & aturan dependency §4.
	- `SECURITY_CHECKLIST.md` / `DATA_RETENTION_AND_PRIVACY.md` — audit immutability, penyimpanan file (foto R2 + dokumen Google Drive privat), anonimisasi (hapus foto R2 + putus link Drive).
	- `DEPLOYMENT.md` / `BACKUP_AND_RECOVERY.md` — Supervisor worker, backup R2.
	- `UI_WIREFRAME_NOTES.md` — presentation layer & UX polling (frontend TERKUNCI: Livewire 4 + Blade custom + Tailwind 4).
---
*Status: FINAL v1.2 — Batch B 2026-07-14. Selaras PRD Kakehashi v0.3.14 + PROJECT_OVERVIEW + GLOSSARY + DECISIONS_LOG.*
