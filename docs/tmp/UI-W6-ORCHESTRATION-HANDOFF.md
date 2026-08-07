# Wave 6 Guest Access — Orchestration Handoff (STANDALONE, goal-driven)

**Task ID:** `W6-ORCHESTRATE-GUEST-01`
**Status:** **APPROVED** — operator 2026-08-07; siap dieksekusi agent orkestrator
**Intended executor:** Agent orkestrator lain (conversation baru, tanpa konteks sebelumnya)
**Mode:** Orkestrasi satu conversation — agent = Builder + Reviewer dalam satu sesi,
**review di akhir wave** (penyimpangan operator-approved, pola Wave 5
`docs/tmp/UI-W5-ORCHESTRATION-HANDOFF.md`). **UI Wave 6 dikerjakan SETELAH review
wave lulus.**
**Branch:** `ui-w6-guest` (dibuat dari `ui-w5-placement` @ `3bdd92f`, sudah ter-push)
**Working material only** — tidak mengubah `docs/kakehashi/`, Build Log (kecuali
baris task yang ditentukan), credential pack

Dokumen ini **standalone** dan **goal-driven**: baca seluruhnya, kejar FINAL GOAL
sampai tuntas, jangan berhenti di tengah kecuali stop condition (bagian 7).

---

## 0. FINAL GOAL (ditetapkan sebelum eksekusi — kejar sampai tuntas)

**Selesai hanya jika SEMUA berikut terpenuhi:**

1. **Fase Domain — W6-T1..W6-T7 terimplementasi dan hijau** di branch
   `ui-w6-guest` (DB `kakehashi_test`, PostgreSQL):
   - T1 Token/link: request + approval; token acak panjang, **hanya `token_hash`
     disimpan**; token muncul hanya setelah approval; satu token untuk satu
     `interview_container`; token mentah tidak pernah di DB/log.
   - T2 Gate/code: validasi berurutan — token ada → belum kadaluarsa → kontainer
     Aktif → kode tambahan bila ada; kegagalan pesan **generik**; compare
     constant-time; lockout kode (5 gagal → 15 menit).
   - T3 Rate limit (Redis): invalid 10/menit/IP; valid 60/menit/token; kode
     tambahan 5 gagal → lock 15 menit.
   - T4 G2: list pseudonim — identifier **NIK `K-YYYY-NNNNN`**, tanpa
     nama/foto/riwayat; kandidat anonymized **dikecualikan**; tidak ada
     sort/filter nama/foto/perusahaan/lembaga/field HIDE.
   - T5 G3: detail whitelist sesuai PRD Lampiran C; audit `GUEST_DETAIL_VIEWED`;
     detail langsung kandidat anonymized ditolak generik; object Candidate penuh
     tidak pernah dikirim.
   - T6 Aset/headers: foto R2 signed URL **TTL 15 menit scoped token**;
     dokumen hanya jika shareable via Drive; `Cache-Control: no-store`; JP-only;
     security headers/CSP guest.
   - T7 PII review: **response leak suite** (field HIDE tidak boleh ada di
     response level, serialization whitelist, sort/filter allowlist).
2. **Self-verification per task**: setiap task diakhiri report
   `docs/tmp/UI-W6-T{n}-BUILDER-REPORT.md` (file diubah, command, hasil test,
   risiko) + test fokus + pint hijau **sebelum** lanjut.
3. **Review-at-end (W6-T8) selesai**: checklist Reviewer playbook 09 dijalankan
   penuh oleh orkestrator (token hash, validasi urut, rate limits, generic
   failures, G2/G3 whitelist, anonymized exclusion, serialization, sort/filter
   allowlist, signed photo scope, Drive shareable rule, headers/no-store, audit)
   → verdict + severity + evidence di `docs/tmp/UI-W6-T8-REVIEW-AT-END-REPORT.md`.
4. **Temuan review**: Blocker/Major **wajib diperbaiki dulu** (fix minimal) dan
   direview ulang sampai bersih — tag tidak boleh dibuat sebelum bersih.
5. **Tag `wave-6-guest-complete`** (annotated, "Wave 6 Guest complete") dibuat di
   commit hasil review bersih dan **di-push ke origin** — **sebelum** fase UI.
6. **Fase UI (setelah review lulus)**: UI Wave 6 (Guest pages + pengelolaan link
   Maker/Checker) dibangun di branch yang sama, pola UI-W4/W5
   (`routes/web.php`, `config/navigation.php`, `lang/id+ja/ui.php`,
   `app/Livewire/Guest/`, `resources/views/guest/` + `livewire/guest/`), dengan
   report `docs/tmp/UI-W6-UI-T{n}-BUILDER-REPORT.md` per slice dan selfcheck
   akhir. Manual smoke tetap sesi operator terpisah (pola W5), bukan bagian dari
   goal ini.
7. **BUILD_LOG.md diperbarui**: satu baris per task W6-T1..T7 + T8 (verdict,
   ditandai "review-at-end in-session, operator-approved deviation") + baris UI
   per slice (ditandai "UI setelah review wave lulus").
8. **Tanpa secret**: tidak ada password/TOTP/token guest mentah/recovery di
   chat, report, atau commit; tidak ada perubahan `docs/kakehashi/`.

**BUKAN goal (jangan dikerjakan):** deployment/VPS, manual smoke UI Wave 6,
Wave 7 hardening, tag lain, perubahan authority docs, re-audit W0–W5 penuh.

**Definisi "tuntas"**: nomor 1–8 selesai. Setelah itu tulis laporan akhir singkat
(`docs/tmp/UI-W6-ORCHESTRATION-FINAL-REPORT.md`) dan serahkan ke operator.

---

## 1. Deklarasi penyimpangan aturan produksi (operator-approved)

Aturan yang dilanggar (AGENTS.md + playbook 09): **"Builder dan Reviewer harus
conversation terpisah; wave tidak bisa PASS tanpa verdict Reviewer terpisah."**

Keputusan operator: Wave 6 dieksekusi orkestrasi satu agent (builder + reviewer),
review di akhir wave, mengikuti preseden Wave 4/5 yang berhasil
(`docs/tmp/UI-W5-ORCHESTRATION-HANDOFF.md`). Dicatat di BUILD_LOG; **tidak
mengubah authority docs**.

Mitigasi wajib:

1. Report per task dengan bukti command + hasil (pola Wave 4/5).
2. Test otomatis per task — suite fokus + regresi + pint hijau sebelum task
   berikutnya.
3. Review akhir memakai checklist Reviewer playbook 09 penuh; verdict + severity
   + evidence dicatat.
4. Kasus keamanan/konkurrensi wajib punya test otomatis (rate limit, lockout,
   scope token, anonymized exclusion, no raw token in log) — bukan klaim manual.
5. Operator tetap boleh memanggil Reviewer terpisah sebelum Wave 7; hasil ini
   bukan pengganti permanen aturan produksi.

---

## 2. Required reading (WAJIB dibaca sebelum task pertama)

1. `AGENTS.md`
2. `docs/kakehashi/README.md` · `docs/kakehashi/BUILD_INVARIANTS.md`
3. `docs/kakehashi/playbook/09_WAVE_6_GUEST.md`
4. PRD `docs/kakehashi/authority/PRD_Kakehashi_v0_3_14.md` (§4.3, §6.3, §7.7,
   §9.8, Lampiran C)
5. `docs/kakehashi/modules/MODULE_GUEST_ACCESS.md`
6. `docs/kakehashi/technical/API_CONTRACTS.md` (GuestCandidateReadModel)
7. `docs/kakehashi/technical/DATA_RETENTION_AND_PRIVACY.md`
8. `docs/kakehashi/technical/SECURITY_CHECKLIST.md`
9. `docs/kakehashi/technical/DATABASE_SCHEMA.md` (guest_link / access log)
10. `docs/kakehashi/foundation/STATUS_STATE_MACHINE.md` + `BUSINESS_RULES.md`
    (kontainer Wawancara Aktif, anonymized)
11. Preseden: `docs/tmp/UI-W5-ORCHESTRATION-HANDOFF.md` (pola orkestrasi),
    `docs/tmp/UI-W5-MANUAL-SMOKE-AGENT-HANDOFF.md` (pola UI+secret),
    `docs/tmp/UI-W4-T0-T8-REVIEWER-REPORT.md` (pola review)

---

## 3. Environment

| Item | Nilai |
| --- | --- |
| Branch | `ui-w6-guest` (dari `3bdd92f`) — `git checkout ui-w6-guest` + pull |
| DB test | `kakehashi_test` (PostgreSQL 18 — wajib untuk behavior/concurrency/migration) |
| Env test | `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql` |
| Redis | Wajib untuk rate limit (local, pola existing) |
| R2 | Test/fake sesuai pola existing (signed URL TTL); tanpa secret produksi |
| Stack | PHP 8.4 · Laravel 13 · Livewire 4 · Tailwind 4 (tidak berubah) |
| VPS | Tidak diperlukan |

---

## 4. Urutan eksekusi (JANGAN lompat; satu task selesai → verifikasi → lanjut)

### Fase Domain

| # | Task | Gate | Report |
| --- | --- | --- | --- |
| T1 | Token/link (request/approval/hash) | Token hanya setelah approval; hash-only at rest | `docs/tmp/UI-W6-T1-BUILDER-REPORT.md` |
| T2 | Gate/kode (validasi urut & lockout) | Pesan generik; constant-time | `docs/tmp/UI-W6-T2-BUILDER-REPORT.md` |
| T3 | Rate limit (Redis) | Invalid/valid/kode — tiga lapis | `docs/tmp/UI-W6-T3-BUILDER-REPORT.md` |
| T4 | G2 (list pseudonim) | NIK saja; anonymized excluded | `docs/tmp/UI-W6-T4-BUILDER-REPORT.md` |
| T5 | G3 (detail whitelist) | Audit GUEST_DETAIL_VIEWED | `docs/tmp/UI-W6-T5-BUILDER-REPORT.md` |
| T6 | Aset/headers | R2 scoped+TTL; Drive shareable; no-store/CSP | `docs/tmp/UI-W6-T6-BUILDER-REPORT.md` |
| T7 | PII review (leak suite) | Response-level whitelist | `docs/tmp/UI-W6-T7-BUILDER-REPORT.md` |
| T8 | **Review-at-end** | PASS bersih sebelum tag | `docs/tmp/UI-W6-T8-REVIEW-AT-END-REPORT.md` |

### Fase UI (SETELAH T8 PASS dan tag `wave-6-guest-complete` dibuat)

| # | Slice UI | Gate | Report |
| --- | --- | --- | --- |
| U1 | Guest link management Maker (request) + Checker (approve/reject), panel token sekali | Token mentah hanya sekali, hilang setelah reload | `docs/tmp/UI-W6-UI-T1-BUILDER-REPORT.md` |
| U2 | Guest public pages: gate (token/kode), G2 list, G3 detail | JP-only; no-store; tanpa field HIDE | `docs/tmp/UI-W6-UI-T2-BUILDER-REPORT.md` |
| U3 | Polish + selfcheck: i18n id/ja, nav permission-aware, route smoke, audit, no-store headers | Full suite + pint + build hijau | `docs/tmp/UI-W6-UI-T3-BUILDER-REPORT.md` |

Setiap task: patch terkecil, satu slice, mutasi hanya lewat public service /
read-model (`GuestCandidateReadModel`, `AuditLogger`, `NotificationService` +
after-commit, Redis rate limiter). Tidak ada akses langsung tabel lintas-modul.
Tidak ada abstraksi spekulatif.

---

## 5. Aturan mengejar goal

- Sekali mulai: **lanjut terus T1→T8→UI tanpa menunggu prompt tambahan**. Jangan
  berhenti menunggu approval per task.
- Setiap selesai task: self-verify (baca diff sendiri, jalankan test fokus +
  regresi + pint) → tulis report → lanjut.
- Bug lintas-task yang ditemukan saat jalan: fix minimal di task berjalan, catat
  di report task itu.
- Jika sebuah task membutuhkan keputusan produk yang tidak ada di authority →
  **stop** (bukan tebak), tulis temuan + aksi operator, tunggu keputusan.
- Secret: tidak pernah minta/baca/tulis secret di chat; token guest mentah tidak
  pernah dicatat (chat/report/log/commit); jalur manual (jika ada) memakai
  `STOP FOR OPERATOR INPUT — <label> — <jenis>` dan tunggu `LANJUT`.

---

## 6. Test wajib (agregat)

- T1: token tidak tersimpan mentah (hash-only); token sebelum approval tidak
  valid; satu token tidak bisa dipakai lintas kontainer.
- T2: token invalid/expired/closed-container → pesan generik; kode salah 5× →
  lockout; perbandingan constant-time.
- T3: rate limit invalid 10/menit/IP, valid 60/menit/token, lock kode 15 menit
  (Redis).
- T4: G2 berisi NIK saja (tanpa nama/foto); anonymized tidak muncul; sort/filter
  PII tidak ada.
- T5: G3 hanya whitelist Lampiran C; audit `GUEST_DETAIL_VIEWED`; detail
  anonymized ditolak generik.
- T6: signed URL TTL 15 menit scoped token; dokumen non-shareable ditolak;
  `Cache-Control: no-store` + headers/CSP; JP-only.
- T7: response leak suite — field HIDE tidak ada di JSON/HTML response, token
  mentah tidak pernah di log.
- Per task: `php artisan test` fokus + `vendor/bin/pint --test`; migrasi fresh
  PostgreSQL hanya bila schema berubah.

---

## 7. Stop conditions (satu-satunya alasan berhenti di tengah)

- Response mengandung field HIDE (walau UI tidak menampilkannya).
- Token mentah tampil di DB/log/response.
- G2 memuat nama/foto, atau Guest bisa sort/filter PII.
- Guest bisa keluar dari scope container (akses kontainer lain / kandidat lain).
- Authority conflict (PRD/MODULE_GUEST_ACCESS/DATABASE_SCHEMA) → stop, lapor,
  tunggu keputusan.
- T8 menemukan Blocker/Major belum difix → jangan tag; fix dulu, review ulang.

Jika stop condition terpicu: tulis laporan posisi, daftar apa yang selesai/belum,
dan aksi operator yang dibutuhkan — lalu berhenti.

---

## 8. Deliverables (wajib saat tuntas)

1. `docs/tmp/UI-W6-T1..T7-BUILDER-REPORT.md` (7 laporan domain)
2. `docs/tmp/UI-W6-T8-REVIEW-AT-END-REPORT.md` (verdict + severity + evidence +
   temuan + hasil fix)
3. `docs/tmp/UI-W6-UI-T1..T3-BUILDER-REPORT.md` (3 laporan UI)
4. `docs/tmp/UI-W6-ORCHESTRATION-FINAL-REPORT.md` (ringkasan akhir, commit yang
   dibuat, tag, state)
5. BUILD_LOG.md: baris W6-T1..T7 + T8 (ditandai "review-at-end in-session,
   operator-approved deviation") + baris UI (ditandai "UI setelah review wave
   lulus")
6. Commit per task (`w6(t{1..7}): ...`, `ui(w6): ...` atau setara pola repo);
   branch di-push; tag `wave-6-guest-complete` dibuat di commit review bersih
   dan di-push **sebelum** fase UI

---

## 9. Perintah pertama (untuk agent eksekutor — salin apa adanya)

```text
Anda adalah Orchestrator Wave 6 Kakehashi (builder + reviewer dalam satu sesi,
review di akhir — penyimpangan operator-approved, pola Wave 5). Kejar FINAL
GOAL dokumen ini sampai tuntas: docs/tmp/UI-W6-ORCHESTRATION-HANDOFF.md

Baca §0–§3 dulu, lalu eksekusi §4–§6 tanpa berhenti di tengah, kecuali stop
condition §7. Verifikasi diri per task; jangan menunggu prompt tambahan.
Urutan WAJIB: domain W6-T1..T7 → review T8 → tag wave-6-guest-complete →
baru fase UI (U1..U3). Branch: ui-w6-guest. DB: kakehashi_test.
Tanpa secret; tanpa ubah docs/kakehashi/; Build Log hanya baris task §8.
Selesai = tag ter-push + final report + BUILD_LOG terisi.
```

Approval operator untuk mulai:
`APPROVED — START UI-W6 ORCHESTRATION`
