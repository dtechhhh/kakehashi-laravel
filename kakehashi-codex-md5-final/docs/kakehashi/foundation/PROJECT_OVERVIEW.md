---
title: "PROJECT_OVERVIEW"
status: "FINAL"
source_notion_title: "PROJECT_OVERVIEW"
exported_at: "2026-07-15"
authority_rank: "foundation"
canonical_source: "Notion"
codex_edit_policy: "read-only"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Do not change product or domain decisions in a coding task. If this file appears stale or contradictory, stop and ask the operator to verify Notion.

# PROJECT_OVERVIEW

> [!NOTE]
> **Pintu masuk proyek Kakehashi.** File ini dibaca lebih dulu oleh semua agent file lain. **Sumber kebenaran tunggal & tertinggi = PRD Kakehashi v0.3.14.** Jika file ini berkonflik dengan PRD, PRD yang berlaku. Status: **FINAL v1.1 — Batch B aligned (2026-07-14).**
>
## 1. Visi & Problem Statement
**Visi.** Kakehashi adalah sistem terpusat manajemen kandidat & job untuk Kumiai/TSK — menjembatani kandidat Indonesia dengan perusahaan Jepang melalui satu alur tervalidasi, terlacak, dan bilingual (ID/JP), sejak organisasi baru berdiri.
**Problem statement (PRD §1, §2.1).** Kumiai ini baru berdiri dan belum punya sistem pengelolaan data kandidat. Praktik manual di industri menimbulkan pola masalah berulang: data tersebar tanpa pusat, validasi tidak terlacak (siapa/kapan/alasan), penjadwalan wawancara rawan duplikasi penarikan kandidat, dan riwayat kandidat tidak tersimpan terstruktur. Kakehashi dibangun agar kumiai beroperasi dengan sistem terpusat sejak awal — **melompati fase manual yang rawan masalah.**
## 2. Pengguna & Stakeholder
**Enam role hardcode untuk MVP** (tidak bisa ditambah/dihapus via dashboard — PRD §4.1):
<table header-row="true">
<tr>
<td>Role</td>
<td>Peran inti</td>
<td>Modul utama</td>
</tr>
<tr>
<td>Staf Input</td>
<td>Input data kandidat (Maker)</td>
<td>Kandidat</td>
</tr>
<tr>
<td>Approver Kandidat</td>
<td>Setuju/tolak data kandidat (Checker), tanpa edit</td>
<td>Kandidat</td>
</tr>
<tr>
<td>Asisten Manajer</td>
<td>Eksekusi aksi Wawancara & Penempatan (Maker)</td>
<td>Wawancara, Penempatan</td>
</tr>
<tr>
<td>Manajer Job</td>
<td>Setuju/tolak aksi Asisten Manajer (pure Checker)</td>
<td>Wawancara, Penempatan</td>
</tr>
<tr>
<td>Super Admin</td>
<td>Kelola akun, data referensi/lookup, lihat audit; read-only di modul operasional</td>
<td>Auth, Lookup, Audit</td>
</tr>
<tr>
<td>Tamu</td>
<td>Lihat daftar peserta wawancara, read-only, via link bertoken (bukan akun)</td>
<td>Guest Access</td>
</tr>
</table>
**Stakeholder eksternal.** Perusahaan Jepang tujuan (mengakses sebagai Tamu read-only per kontainer wawancara — PRD §4.3). **\[ASUMSI\]** Manajemen kumiai sebagai sponsor/owner produk.
## 3. Tujuan Bisnis & Metrik Sukses
**Tujuan produk (PRD §2.2):**
- Satu sistem terpusat untuk data kandidat dari input awal sampai resmi tersedia, dengan validasi dua tahap yang terlacak.
- Mencegah duplikasi penarikan kandidat ke lebih dari satu proses aktif bersamaan, lewat penanda status konsisten.
- Menyimpan riwayat lengkap & permanen tiap kandidat (wawancara, hasil, penempatan).
**Metrik sukses (PRD §2.3 — target operasional, karena belum ada data historis):**
- Nol kasus kandidat ditarik ke >1 job aktif bersamaan.
- Setiap kandidat disetujui punya jejak validasi lengkap & dapat ditelusuri (input, approver, waktu).
- Setelah 3 bulan operasional: catat baseline waktu rata-rata input → disetujui sebagai dasar perbaikan iterasi berikutnya.
## 4. Ruang Lingkup MVP
**IN scope (PRD §3.1):**
- Manajemen data kandidat (CRUD + validasi dua tahap)
- Kontainer wawancara (state machine 5 status)
- Kontainer penempatan (state machine 5 status)
- Sub-flow force-majeur penempatan langsung (gated, ber-approval, alasan wajib)
- Akses tamu read-only per kontainer
- Cross-cutting: notifikasi in-app + polling, audit log terpusat, i18n ID/JP, file storage (foto R2 + dokumen Drive privat)
**OUT of scope (PRD §3.2) — jangan dibangun:**
- Modul Keuangan · Modul Kelas/Pelatihan · Modul Report tahunan
- Data kandidat multi-bahasa penuh (UI bilingual tetap MVP)
- Modul Generate CV
- Manajemen tipe role (post-MVP; MVP = 6 role hardcode)
- Notifikasi push/websocket real-time (post-MVP; MVP near-real-time via polling)
## 5. Ringkasan Modul (High-Level)
> Penamaan dwi-label: **nama file (Inggris) ⇄ nama PRD (Indonesia)**. Detail diserahkan ke file modul masing-masing.
<table header-row="true">
<tr>
<td>Modul (file)</td>
<td>Nama PRD</td>
<td>Ringkasan</td>
</tr>
<tr>
<td>Candidates (MODULE_[CANDIDATES.md](../modules/MODULE_CANDIDATES.md))</td>
<td>Modul Kandidat</td>
<td>CRUD data kandidat dengan alur validasi dua tahap (Maker–Checker) dan riwayat permanen (PRD §5.2, §6.2).</td>
</tr>
<tr>
<td>Jobs (MODULE_[JOBS.md](../modules/MODULE_JOBS.md))</td>
<td>Modul Wawancara</td>
<td>**"Jobs" = Modul Wawancara PRD.** Kelola kontainer wawancara: tarik kandidat, update status partisipasi, tutup kontainer (PRD §5.3, §6.3).</td>
</tr>
<tr>
<td>Placement (MODULE_[PLACEMENT.md](../modules/MODULE_PLACEMENT.md))</td>
<td>Modul Penempatan</td>
<td>Kelola kontainer penempatan: kirim batch normal + force-majeur, status penempatan, arsip otomatis (PRD §5.4, §6.4).</td>
</tr>
<tr>
<td>Guest Access (MODULE_GUEST_[ACCESS.md](../modules/MODULE_GUEST_ACCESS.md))</td>
<td>Akses Tamu (bagian Wawancara)</td>
<td>Link bertoken read-only per kontainer untuk perusahaan Jepang, whitelist field GuestCandidateView (PRD §4.3, §6.3 Sub-flow 3, Lampiran C).</td>
</tr>
<tr>
<td>Auth (MODULE_[AUTH.md](../modules/MODULE_AUTH.md))</td>
<td>Pecahan domain keamanan/Super Admin</td>
<td>Login + 2FA TOTP, sesi 30 menit, step-up re-auth per aksi sensitif, kelola akun pengguna (PRD §4.4–§4.6, §6.1).</td>
</tr>
<tr>
<td>Lookup Data (MODULE_LOOKUP_[DATA.md](../modules/MODULE_LOOKUP_DATA.md))</td>
<td>Data Referensi/Lookup (domain Super Admin)</td>
<td>Kelola label deskriptif bilingual (label_id/label_ja/code) + mekanisme request data baru; status state machine tetap hardcode (PRD §5.1, §7.8).</td>
</tr>
</table>
> **Catatan peran Super Admin:** di PRD, Super Admin adalah satu role yang kewenangannya tersebar di domain **Auth** (kelola akun), **Lookup Data**, dan **Audit** (read-only), serta read-only di semua modul operasional. Tidak ada satu "modul Super Admin" tunggal di struktur file.
## 6. Ringkasan Stack & Batas Infra
Mengacu PRD §9.6 dan TECH_VERSION_SEED (terverifikasi live 2026-06-28). Detail versi minor + caveat ada di seed.
<table header-row="true">
<tr>
<td>Lapisan</td>
<td>Keputusan</td>
<td>Status</td>
</tr>
<tr>
<td>Backend</td>
<td>Laravel 13.x · PHP 8.4</td>
<td>Terkunci (PRD §9.6)</td>
</tr>
<tr>
<td>Database</td>
<td>PostgreSQL 18.x (stable) + pg_trgm</td>
<td>Terkunci</td>
</tr>
<tr>
<td>File storage</td>
<td>Foto wajah: Cloudflare R2 privat + signed URL 5–15 mnt; Dokumen Peserta: link Google Drive privat</td>
<td>Terkunci</td>
</tr>
<tr>
<td>Infra</td>
<td>VPS **4 vCPU / 8 GB RAM** (single VPS, no HA). Provider/region final → DEPLOYMENT</td>
<td>Terkunci (2026-07-13)</td>
</tr>
<tr>
<td>Queue / Cache / Notifikasi</td>
<td>Redis co-located (cache/session/queue/rate-limit) dengan `maxmemory-policy noeviction`  • cache TTL + monitoring memory; queue 2 worker; notifikasi in-app + polling ≤60 dtk</td>
<td>Terkunci (2026-07-14)</td>
</tr>
<tr>
<td>Arsitektur</td>
<td>Modular monolith; komunikasi antar-modul via public service/facade (PRD §9.7)</td>
<td>Terkunci (detail → [ARCHITECTURE.md](ARCHITECTURE.md))</td>
</tr>
<tr>
<td>Frontend</td>
<td>**Livewire 4 + Blade custom + Tailwind 4**</td>
<td>Terkunci (UI_WIREFRAME_NOTES FINAL)</td>
</tr>
</table>
## 7. Glosarium Minimum
> Definisi lengkap = sumber tunggal di [**GLOSSARY.md**](GLOSSARY.md). Berikut istilah kunci agar overview bisa dibaca mandiri:
- **Kumiai / TSK** — organisasi penyalur/pengelola program kerja ke Jepang.
- **Kontainer** — wadah proses (wawancara atau penempatan) tempat kandidat ditarik & dikelola.
- **Maker–Checker** — pola dua peran: pelaku aksi (Maker) vs penyetuju (Checker).
- **Pending sebagai entitas** — aksi sensitif tidak mengubah status agregat sampai disetujui; ditampilkan sebagai overlay label (PRD §7.4).
- **Step-up re-auth** — re-autentikasi (password + TOTP) per aksi sensitif, berbeda dari 2FA login (PRD §4.6).
- **Force-majeur** — jalur pengecualian menempatkan kandidat langsung tanpa pipeline wawancara (PRD §6.4 Sub-flow 2b).
- **GuestCandidateView** — whitelist field yang boleh dilihat Tamu (PRD Lampiran C).
## 8. Asumsi, Batasan & Open Questions
**Batasan utama:** single VPS tanpa HA (target uptime 99%); tim kecil → risiko bottleneck approval; PII tunduk APPI (Jepang) & UU PDP (Indonesia).
**Open questions (PRD §11) — sudah dikonfirmasi user 2026-06-29 (retensi PII terkunci di tingkat kebijakan, rincian jadwal pending DPO):**
<table header-row="true">
<tr>
<td>Item</td>
<td>Default sementara</td>
<td>Status</td>
</tr>
<tr>
<td>Estimasi volume kandidat 1–3 tahun</td>
<td>500–3.000</td>
<td>Terkunci (user 2026-06-29) — angka perencanaan kapasitas</td>
</tr>
<tr>
<td>Jumlah user internal total</td>
<td>±15 user</td>
<td>Terkunci (user 2026-06-29)</td>
</tr>
<tr>
<td>Kebijakan retensi PII</td>
<td>5 tahun aktif sejak keterikatan terakhir + anonimisasi (soft tombstone) ≤1 tahun</td>
<td>Terkunci di tingkat kebijakan (user 2026-06-29); rincian jadwal pending DPO → DATA_RETENTION_AND_PRIVACY.md</td>
</tr>
</table>
## 9. Dependency & Handoff
- **Dependency hulu:** tidak ada (file fondasi paling hulu). Mengacu langsung ke PRD v0.3.
- **Handoff ke file lain:** [ARCHITECTURE.md](ARCHITECTURE.md) (arsitektur & kontrak modul), [GLOSSARY.md](GLOSSARY.md) (istilah), ROLES_AND_[PERMISSIONS.md](ROLES_AND_PERMISSIONS.md) (RBAC rinci), STATUS_STATE_[MACHINE.md](STATUS_STATE_MACHINE.md) & BUSINESS_[RULES.md](BUSINESS_RULES.md) (status & aturan), file modul (Candidates/Jobs/Placement/Guest Access/Auth/Lookup), UI_WIREFRAME_[NOTES.md](../ui/UI_WIREFRAME_NOTES.md) (keputusan frontend).
- **Keputusan tercatat di DECISIONS_LOG** (Batch A/B closure).
---
*Status: FINAL v1.2 — Batch B 2026-07-14. Selaras PRD_Kakehashi_v0_3_14.*
