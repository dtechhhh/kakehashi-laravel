---
title: "KAKEHASHI BUILD & DEPLOYMENT PLAYBOOK v1"
status: "FINAL v1 + VPS Harian Ephemeral Addendum"
source_notion_title: "KAKEHASHI BUILD & DEPLOYMENT PLAYBOOK v1"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# KAKEHASHI BUILD & DEPLOYMENT PLAYBOOK v1

> [!NOTE]
> **Panduan operasional non-IT untuk membangun Kakehashi dengan ChatGPT Codex—dari repository GitHub kosong sampai deployment, backup, restore, dan go-live.**
> **Status:** FINAL v1 — VPS Harian Ephemeral Addendum approved · **Mulai penggunaan dari:** [00 — Mulai di Sini](00_MULAI_DI_SINI.md)
>
## Status Eksekusi
- **Pass 1:** COMPLETE — kerangka buku, daftar isi, format standar, simbol, dan acceptance criteria
- **Pass 2:** COMPLETE — GitHub, Codex, operating model, prompt generik wajib
- **Pass 3a:** COMPLETE — panduan Wave 0–2, prompt wajib, DoD, stop condition, dan snapshot
- **Pass 3b:** COMPLETE — panduan Wave 3–5, prompt wajib, DoD, stop condition, dan snapshot
- **Pass 3c:** COMPLETE — panduan Wave 6–7, prompt wajib, DoD, stop condition, dan snapshot
- **Pass 4:** COMPLETE — deployment, staging ringan, backup, restore, recovery
- **Pass 5:** COMPLETE — quality pass, status final, prompt/authority check, dan finalisasi
> [!NOTE]
> **Buku ini bukan eksekusi coding aplikasi.** Jangan memasukkan secret ke Notion, GitHub, atau prompt. Jangan menjalankan wave berikutnya tanpa gate dan persetujuan operator.
>
## Prinsip yang Dikunci
1. **Builder ≠ Reviewer.** Gunakan percakapan Codex terpisah.
2. Tetap mengikuti **8 wave BUILD STRATEGY v1**: Env → Auth/Audit → Lookup → Candidates → Jobs → Placement → Guest → Hardening/Go-live.
3. `pending_request` dan pola after-commit dibangun sekali pada Wave 1.
4. Skeleton guard anonimisasi dibangun pada Wave 3; UI dan pengujian penuh diselesaikan pada Wave 7.
5. Restore database yang berhasil adalah **hard gate go-live**.
6. Approved HTML hanya referensi visual non-authoritative.
7. Hygiene dokumentasi menjadi backlog terpisah setelah coding stabil/pasca-MVP awal.
8. Prompt Library v1 fokus pada **prompt generik lengkap + prompt wajib untuk task berisiko**. Prompt turunan dapat bertambah bertahap.
9. Staging bersifat ringan: environment mirip production, dapat berupa local production-like atau aplikasi/folder terpisah; tidak mewajibkan multi-server.
10. Hanya ada satu **master Build Log** sebagai sumber status progres.
## Addendum Disetujui — Model VPS Harian Ephemeral
1. **Local Dev tetap bengkel utama.** Coding, unit/feature test, commit, dan source of truth tetap local + GitHub.
2. **Ephemeral Test VPS** dapat disewa harian untuk rehearsal/integration/smoke test. Ia boleh diganti atau dihancurkan kapan saja.
3. **Production VPS tetap stabil dan terpisah.** VPS harian tidak menggantikan target single VPS production 4C/8G.
4. VPS test hanya memakai commit/tag yang telah PASS, data sintetis, test secret, bucket R2 test, dan folder Drive test.
5. Disk VPS ephemeral bukan backup; backup dan restore gate production tidak berubah.
6. VPS bukan prasyarat Wave 0. Ia mulai berguna untuk rehearsal dan menjadi wajib minimal satu kali pada Wave 7 production-like rehearsal.
7. Addendum ini tidak mengubah 8 wave, stack, domain locks, atau otoritas dokumen.
## Daftar Isi Final
1. **00 — Mulai di Sini** — jalur cepat harian, cara membaca buku, mode terminal non-IT, persiapan bertahap.
2. **01 — GitHub & Repository** — repository privat, branch, commit, pull request, snapshot.
3. **02 — Menyiapkan Codex** — dokumen otoritas, `AGENTS.md`, Builder/Reviewer, satu task per percakapan.
4. **03 — Wave 0: Environment & Skeleton Aman**.
5. **04 — Wave 1: Auth, Audit & Approval Foundation**.
6. **05 — Wave 2: Lookup & Master Perusahaan**.
7. **06 — Wave 3: Candidates**.
8. **07 — Wave 4: Jobs/Wawancara**.
9. **08 — Wave 5: Placement**.
10. **09 — Wave 6: Guest Access**.
11. **10 — Wave 7: Hardening & Go-Live**.
12. **11 — Prompt Library** — prompt generik wajib dan prompt task berisiko.
13. **12 — Deployment** — staging ringan, VPS, Nginx, SSL, Redis, Supervisor, deploy.
14. **13 — Backup, Restore & Recovery**.
15. **14 — Build Log & Checklist** — satu-satunya sumber status progres.
16. **15 — Backlog Pasca-MVP Awal** — hygiene dokumentasi dan pekerjaan yang sengaja ditunda.
## Jalur Persiapan Bertahap
<table fit-page-width="true" header-row="true">
<tr>
<td>Titik waktu</td>
<td>Yang wajib sudah tersedia</td>
</tr>
<tr>
<td>Sebelum Wave 0</td>
<td>GitHub, Codex, password manager, mesin development</td>
</tr>
<tr>
<td>Sebelum fitur foto</td>
<td>Cloudflare R2 privat dan kredensial tersimpan aman</td>
</tr>
<tr>
<td>Sebelum Guest/go-live</td>
<td>VPS, domain/DNS, email, kebijakan permission Google Drive, aplikasi TOTP</td>
</tr>
</table>
## Mode Terminal Non-IT
- **Mode A — Codex menjalankan command:** operator membaca rencana dan menyetujui command sebelum dijalankan.
- **Mode B — Operator copy-paste:** Codex memberi satu command per langkah, output yang diharapkan, dan stop condition; operator membandingkan hasil dengan checklist.
- Buku tidak mengasumsikan operator mahir Git atau terminal.
## Format Bab Standar
Setiap bab operasional memakai urutan berikut:
1. **Tujuan** — hasil bisnis yang ingin dicapai.
2. **Hasil yang terlihat** — apa yang dapat diperiksa operator.
3. **Prasyarat** — akun, akses, wave, dan dokumen yang dibutuhkan.
4. **Istilah sederhana** — jargon dan arti awamnya.
5. **Langkah operator** — instruksi bernomor.
6. **Prompt Builder** — teks siap tempel untuk percakapan pembuat.
7. **Laporan Builder yang diharapkan** — format bukti kerja.
8. **Prompt Reviewer** — teks siap tempel untuk percakapan terpisah.
9. **Cara membaca verdict** — PASS, PASS WITH NOTES, FAIL, atau BLOCKED.
10. **Checklist lulus** — pemeriksaan pass/fail.
11. **Stop condition** — kapan wajib berhenti.
12. **Kesalahan umum** — peringatan senior.
13. **Commit/snapshot** — kapan aman menyimpan perubahan.
14. **Catat ke master Build Log** — chapter wave tidak menyimpan status duplikat.
## Simbol dan Ikon
<table fit-page-width="true" header-row="true">
<tr>
<td>Simbol</td>
<td>Arti</td>
<td>Tindakan operator</td>
</tr>
<tr>
<td>✅</td>
<td>Lulus / aman dilanjutkan</td>
<td>Lanjut setelah bukti tersimpan</td>
</tr>
<tr>
<td>⛔</td>
<td>Stop condition</td>
<td>Jangan lanjut atau merge</td>
</tr>
<tr>
<td>⚠️</td>
<td>Perlu perhatian</td>
<td>Baca risiko dan minta review</td>
</tr>
<tr>
<td>🔒</td>
<td>Aksi sensitif / step-up / secret</td>
<td>Gunakan kontrol tambahan</td>
</tr>
<tr>
<td>🧪</td>
<td>Wajib diuji</td>
<td>Simpan command dan hasil test</td>
</tr>
<tr>
<td>📸</td>
<td>Simpan bukti</td>
<td>Simpan screenshot/log tanpa secret</td>
</tr>
<tr>
<td>💾</td>
<td>Commit, tag, atau backup</td>
<td>Simpan hanya setelah gate lulus</td>
</tr>
<tr>
<td>🤖</td>
<td>Builder Agent</td>
<td>Percakapan pembuat perubahan</td>
</tr>
<tr>
<td>🔍</td>
<td>Reviewer Agent</td>
<td>Percakapan terpisah, review tanpa coding</td>
</tr>
<tr>
<td>👤</td>
<td>Operator non-IT</td>
<td>Menyetujui rencana dan membaca bukti</td>
</tr>
</table>
## Acceptance Criteria Buku
- [x] Mencakup perjalanan GitHub kosong hingga production deployment.
- [x] Dapat diikuti operator non-IT dengan Mode Terminal A atau B.
- [x] Mempertahankan 8 wave BUILD STRATEGY v1.
- [x] Builder dan Reviewer selalu percakapan terpisah.
- [x] Prompt generik wajib tersedia lengkap.
- [x] Prompt wajib tersedia untuk task berisiko tiap wave.
- [x] Prompt turunan dapat ditambahkan bertahap tanpa menunda kerangka.
- [x] Setiap bab operasional memiliki DoD dan stop condition.
- [x] Gate maker tidak boleh self-approve tercakup.
- [x] Fondasi `pending_request` dan after-commit ditempatkan di Wave 1.
- [x] Skeleton guard anonimisasi ditempatkan di Wave 3.
- [x] UI dan pengujian penuh anonimisasi ditempatkan di Wave 7.
- [x] Guest PII leakage memiliki gate wajib.
- [x] Staging ringan tidak mewajibkan multi-server.
- [x] Akun dan layanan disiapkan bertahap, bukan seluruhnya pada hari pertama.
- [x] Satu master Build Log menjadi sumber status progres.
- [x] Backup dan restore procedure tersedia.
- [x] Restore test menjadi hard gate go-live.
- [x] Tidak ada secret di Notion, GitHub, prompt, screenshot, atau log buku.
- [x] Approved HTML tetap non-authoritative.
- [x] Hygiene dokumentasi disimpan sebagai backlog terpisah.
- [x] Tidak ada prompt yang mengizinkan Codex berpindah wave sendiri.
## Metode Penyusunan
1. **Pass 1:** kerangka buku, daftar isi, format standar, simbol, acceptance criteria.
2. **Pass 2:** GitHub, Codex, operating model, prompt generik wajib.
3. **Pass 3a:** panduan Wave 0–2.
4. **Pass 3b:** panduan Wave 3–5.
5. **Pass 3c:** panduan Wave 6–7.
6. **Pass 4:** deployment, staging, backup, restore, recovery.
7. **Pass 5:** quality pass dan finalisasi.
---
## Hasil Quality Pass
- Seluruh 16 sub-page tersedia dan berada di bawah playbook.
- Urutan 8 wave konsisten dengan BUILD STRATEGY v1.
- Builder dan Reviewer dipisahkan pada operating model dan prompt.
- Prompt generik wajib tersedia; prompt berisiko berada di chapter terkait.
- `pending_request`, self-approval, anti-double-approval, dan after-commit ditempatkan pada Wave 1.
- Skeleton guard anonimisasi berada pada Wave 3; UI/full flow berada pada Wave 7.
- Guest whitelist, anonymized exclusion, dan PII leakage gate tercakup.
- Staging ringan tidak memaksa multi-server.
- Deployment, backup, restore, recovery, dan hard go-live gate tercakup.
- Tidak ada coding aplikasi atau secret yang dibuat selama penyusunan buku.
> [!NOTE]
> **Playbook FINAL v1 + VPS Harian Addendum.** Buku siap dipakai untuk memulai persiapan GitHub dan Wave 0. Local tetap primary build; VPS harian hanya ruang uji ephemeral; production tetap VPS stabil. Eksekusi build tetap harus mengikuti gate per task dan master Build Log.
>
---
## Quality Check Addendum VPS Harian
- Local + GitHub tetap source of truth.
- VPS harian hanya test/rehearsal; tidak mengubah 8 wave atau production target.
- VPS bukan prasyarat Wave 0 dan memakai commit/tag PASS.
- Test data/secret/R2/Drive dipisahkan dari production.
- SOP bootstrap, smoke test, teardown, destroy, dan billing closure tersedia.
- Prompt Builder/Reviewer serta session log tanpa secret tersedia.
- Minimal satu rehearsal production-like di VPS ephemeral dipersyaratkan Wave 7.
- Restore gate production tidak dilemahkan.
---
**Status akhir:** FINAL v1 + VPS Harian Ephemeral Addendum — 2026-07-15.
[14 — Build Log & Checklist](14_BUILD_LOG_TEMPLATE.md)
[02 — Menyiapkan Codex](02_MENYIAPKAN_CODEX.md)
[01 — GitHub & Repository](01_GITHUB_REPOSITORY.md)
[11 — Prompt Library](11_PROMPT_LIBRARY.md)
[00 — Mulai di Sini](00_MULAI_DI_SINI.md)
[04 — Wave 1: Auth, Audit & Approval Foundation](04_WAVE_1_AUTH_AUDIT.md)
[06 — Wave 3: Candidates](06_WAVE_3_CANDIDATES.md)
[05 — Wave 2: Lookup & Master Perusahaan](05_WAVE_2_LOOKUP.md)
[03 — Wave 0: Environment & Skeleton Aman](03_WAVE_0_ENVIRONMENT.md)
[07 — Wave 4: Jobs/Wawancara](07_WAVE_4_JOBS.md)
[08 — Wave 5: Placement](08_WAVE_5_PLACEMENT.md)
[10 — Wave 7: Hardening & Go-Live](10_WAVE_7_HARDENING.md)
[09 — Wave 6: Guest Access](09_WAVE_6_GUEST.md)
[15 — Backlog Pasca-MVP Awal](15_BACKLOG.md)
[12 — Deployment](12_DEPLOYMENT.md)
[13 — Backup, Restore & Recovery](13_BACKUP_RESTORE_RECOVERY.md)
