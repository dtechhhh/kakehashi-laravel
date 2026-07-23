---
title: "03 — Wave 0: Environment & Skeleton Aman"
status: "FINAL v1"
source_notion_title: "03 — Wave 0: Environment & Skeleton Aman"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 03 — Wave 0: Environment & Skeleton Aman

> [!NOTE]
> **Wave 0 — Environment & Skeleton Aman.** Tujuan: membangun bengkel kerja yang dapat diulang tanpa memasuki fitur bisnis atau Wave 1.
>
## Apa Artinya untuk Operator
Setelah wave ini, repository dapat dipasang ulang dari clone baru, memakai versi teknologi yang benar, dan memiliki cara sederhana untuk menjalankan test serta build. Belum ada login, Kandidat, atau layar bisnis.
## Prasyarat
- [ ] Repository GitHub private sudah lulus checklist Bab 01.
- [ ] Builder dan Reviewer memakai percakapan Codex terpisah.
- [ ] Password manager dan mesin development tersedia.
- [ ] Status awal dicatat di Notion page reference.
## Dokumen Wajib untuk Builder
- PRD v0.3.14
- DECISIONS_LOG Batch A/B
- ARCHITECTURE
- DATABASE_SCHEMA
- PROJECT_OVERVIEW
- TECH_VERSION_SEED
- DEPLOYMENT
- `AGENTS.md` setelah dibuat
## Lingkup Boleh
- PHP 8.4, Laravel 13, PostgreSQL 18, dan `pg_trgm`.
- Redis lokal sebagai cache/session/queue/rate limit.
- Struktur modular monolith minimum.
- Livewire 4 + Blade custom + Tailwind 4 minimum.
- Environment development dan testing terpisah.
- Command install, migration fresh, test, lint/format, dan asset build.
- README setup non-IT dan template `.env` tanpa nilai secret.
## Catatan Ephemeral Test VPS
VPS harian **bukan** prasyarat Wave 0. Local tetap wajib untuk bootstrap, test PostgreSQL, dan iterasi harian. Setelah `wave-0-baseline` lulus, VPS test boleh dipakai secara opsional untuk membuktikan README/bootstrap dapat dijalankan di Ubuntu bersih. Rehearsal tersebut harus memakai commit/tag PASS, data sintetis, dan instance yang bisa dihancurkan.
## Larangan Terkunci
- Tidak Docker-first wajib.
- Tidak Filament sebagai panel utama.
- Tidak repository/DTO/abstraction massal.
- Tidak interface/framework kosong “untuk nanti”.
- Tidak scaffold domain massal.
- Tidak Auth, `pending_request`, audit domain, Candidate, Jobs, Placement, atau Guest.
- Tidak SQLite untuk perilaku database.
- Tidak secret di repository/prompt.
- Tidak membuka Wave 1.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W0-T1 Inspect baseline</td>
<td>Kondisi repo, runtime, dan gap tercatat</td>
<td>Tidak ada edit sebelum rencana disetujui</td>
</tr>
<tr>
<td>W0-T2 Bootstrap runtime</td>
<td>Laravel 13 + PHP 8.4 terpasang</td>
<td>Versi aktual dilaporkan</td>
</tr>
<tr>
<td>W0-T3 PostgreSQL testing</td>
<td>DB dev/test terpisah + `pg_trgm`</td>
<td>Bukan SQLite</td>
</tr>
<tr>
<td>W0-T4 Redis & environment</td>
<td>Redis lokal dan `.env` template aman</td>
<td>Secret tidak terlacak</td>
</tr>
<tr>
<td>W0-T5 Struktur modular</td>
<td>Folder modul dan `Public/` minimum</td>
<td>Tanpa domain scaffold berlebihan</td>
</tr>
<tr>
<td>W0-T6 Tooling</td>
<td>Test, lint, build, migration fresh, README</td>
<td>Fresh clone dapat diulang</td>
</tr>
<tr>
<td>W0-T7 Review akhir</td>
<td>Review anti-overengineering</td>
<td>PASS sebelum tag</td>
</tr>
</table>
## Prompt Builder — W0-T1 Inspect Only
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan W0-T1 sebagai INSPECT-ONLY. Jangan mengubah file, membuat commit, atau menjalankan command destruktif.

Authority: AGENTS.md bila ada; PRD v0.3.14; DECISIONS_LOG Batch A/B; ARCHITECTURE; DATABASE_SCHEMA; PROJECT_OVERVIEW; TECH_VERSION_SEED; DEPLOYMENT.

Periksa repository, branch aktif, PHP/Laravel/PostgreSQL/Redis/Node yang tersedia, file setup, .gitignore, docs authority, dan test configuration.

Laporkan:
1. kondisi baseline;
2. gap terhadap Wave 0;
3. rencana minimum W0-T2 sampai W0-T6;
4. file yang diperkirakan berubah;
5. command yang akan dijalankan;
6. test/verification;
7. risiko dan stop condition.

Jangan edit sebelum operator menyetujui rencana.
```
## Prompt Builder — Implementasi W0
```plain text
Anda adalah Builder Agent Kakehashi. Lanjutkan task [W0-T2/W0-T3/W0-T4/W0-T5/W0-T6] yang sudah disetujui.

Tujuan: [ISI SATU TUJUAN].
Invariant: PHP 8.4, Laravel 13, PostgreSQL 18 + pg_trgm, Redis lokal, modular monolith, Livewire 4 + Blade + Tailwind 4, dan PostgreSQL—not SQLite—untuk test database.

Dilarang: Auth/domain bisnis, Docker-first wajib, Filament sebagai panel utama, repository/DTO massal, interface kosong, dependency tidak perlu, secret, atau pekerjaan Wave 1.

Buat patch terkecil. Jalankan test/tooling yang relevan. Bila schema berubah, jalankan migration fresh pada database test.

Laporan akhir wajib: file berubah, command+hasil, bukti PostgreSQL/pg_trgm/Redis, hal yang tidak dikerjakan, risiko, dan commit message.
```
## Prompt Reviewer — Wave 0
```plain text
Anda adalah Reviewer Agent Kakehashi terpisah. Jangan mengubah kode.

Tinjau task Wave 0 berikut: [TEMPEL TASK, DIFF/COMMIT, LAPORAN BUILDER].

Pastikan:
- stack sesuai PHP 8.4/Laravel 13/PostgreSQL 18/Redis/Livewire 4/Blade/Tailwind 4;
- test database memakai PostgreSQL, bukan SQLite;
- pg_trgm aktif;
- dev dan test terpisah;
- tidak ada secret atau .env terlacak;
- tidak ada fitur Wave 1;
- tidak ada Docker-first wajib, Filament utama, mass abstraction, atau scaffold kosong;
- setup fresh clone dapat diulang.

Berikan severity, bukti, gate lulus/gagal, dan verdict PASS/PASS WITH NON-BLOCKING NOTES/FAIL/BLOCKED.
```
## Definition of Done
- [ ] Versi runtime aktual sesuai baseline.
- [ ] `pg_trgm` aktif.
- [ ] Redis terhubung dan hanya lokal untuk konfigurasi development.
- [ ] Dev/test memakai database berbeda.
- [ ] Migration fresh berhasil pada database test.
- [ ] Test dasar, lint/format, dan asset build berhasil.
- [ ] Struktur modular minimum tersedia tanpa domain kosong berlebihan.
- [ ] README setup dapat diikuti dari clone baru.
- [ ] `.env` dan secret tidak terlacak Git.
- [ ] Reviewer memberi PASS.
- [ ] Tag `wave-0-baseline` dibuat dan dicatat di Build Log.
## Stop Condition
- Setup hanya bekerja di mesin Builder.
- PostgreSQL diganti SQLite.
- Secret diperlukan dalam repository.
- Codex mulai membuat fitur Wave 1.
- Dependency besar tidak memiliki alasan langsung.
## Kesalahan Umum
- Menganggap Docker wajib sejak awal.
- Menambah seluruh package “untuk nanti”.
- Membuat model domain sebagai placeholder.
- Menilai setup selesai tanpa mencoba fresh clone.
- Menganggap test SQLite cukup untuk partial index, JSONB, atau locking PostgreSQL.
## Bukti Sukses Minimum
1. Clone baru mengikuti README sampai test/build lulus.
2. `pg_trgm` dapat diverifikasi di database test.
3. Redis dan PostgreSQL terhubung tanpa credential dicetak ke log.
## Commit dan Snapshot
Commit kecil per task; setelah review akhir lulus buat tag `wave-0-baseline` dan catat pada Build Log.
---
**Status:** FINAL v1 — panduan operasional Wave 0 siap digunakan.
