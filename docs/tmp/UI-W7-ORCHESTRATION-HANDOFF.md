# Wave 7 Hardening & Go-Live — Orchestration Handoff (STANDALONE, goal-driven)

**Task ID:** `W7-ORCHESTRATE-HARDENING-01`
**Status:** READY FOR ORCHESTRATOR — menunggu approval operator
**Intended executor:** Agent orkestrator lain (conversation baru, tanpa konteks sebelumnya)
**Mode:** Orkestrasi satu conversation — agent = Builder + Reviewer dalam satu sesi,
**review di akhir wave** (penyimpangan operator-approved, pola Wave 5/6).
**Branch:** `ui-w7-hardening` (dibuat dari `ui-w6-guest` @ `edb42cb`, sudah ter-push)
**Working material only** — tidak mengubah `docs/kakehashi/`, Build Log (kecuali
baris task yang ditentukan), credential pack, production

Dokumen ini **standalone** dan **goal-driven**: baca seluruhnya, kejar FINAL GOAL
sampai tuntas, jangan berhenti di tengah kecuali stop condition (bagian 7) atau
checkpoint operator (bagian 4 — T5/T6).

---

## 0. FINAL GOAL (ditetapkan sebelum eksekusi — kejar sampai tuntas)

**Selesai hanya jika SEMUA berikut terpenuhi:**

1. **W7-T1..W7-T7 terimplementasi dan hijau** di branch `ui-w7-hardening`
   (DB `kakehashi_test`, PostgreSQL; rehearsal memakai staging test — bukan
   production):
   - T1 RBAC regression: negative suite policy/role — tidak ada bypass.
   - T2 Anonimisasi UI: hanya Super Admin + step-up password+TOTP; guard
     Wave 3 direvalidasi dalam transaksi; UI tidak mengekspos soft-delete/
     restore.
   - T3 Anonimisasi E2E: tombstone `pii_anonymized_at` irreversible,
     PII kosong/scramble sesuai policy, foto R2 dihapus, URL dokumen aplikasi
     dikosongkan + prosedur Drive manual sesuai policy, audit
     `CANDIDATE_ANONYMIZED`, kandidat anonim tidak muncul Guest.
   - T4 Security hardening: headers, HTTPS, APP_DEBUG off, firewall, Redis
     `noeviction` + bind localhost, log tanpa secret, checklist
     SECURITY_CHECKLIST PASS.
   - T5 Staging rehearsal: production-like (ephemeral VPS atau staging lokal
     terpisah) — login + smoke + Redis 2 worker + scheduler + Guest headers/
     whitelist + R2 test + restore; **bukan production DB**.
   - T6 Backup/restore: backup PostgreSQL harian ke bucket R2 **terpisah**;
     **restore dump ke database temporary benar-benar berhasil** dan aplikasi
     bisa login + membaca hasil restore; Redis tidak di-backup sebagai data
     bisnis.
   - T7 Go-live review: decision record disiapkan; verdict Reviewer
     GO-LIVE PASS / BLOCKED; production tidak dibuka sebelum gate lulus.
2. **Self-verification per task**: setiap task diakhiri report
   `docs/tmp/UI-W7-T{n}-BUILDER-REPORT.md` (file diubah, command, hasil test,
   risiko) + test fokus + pint hijau **sebelum** lanjut.
3. **Review-at-end (W7-T8) selesai**: checklist Reviewer playbook 10 dijalankan
   penuh oleh orkestrator (RBAC/step-up negative, Guest PII, HTTPS/debug/
   firewall/Redis noeviction/local bind, audit immutable, anonimisasi eligible
   + blocked, dua worker, backup artifact, restore ke DB temporary, aplikasi
   membaca hasil restore, tanpa secret di bukti) → verdict + severity +
   evidence di `docs/tmp/UI-W7-T8-REVIEW-AT-END-REPORT.md`.
4. **Temuan review**: Blocker/Major **wajib diperbaiki dulu** (fix minimal) dan
   direview ulang sampai bersih — tag tidak boleh dibuat sebelum bersih.
5. **Tag `wave-7-go-live-candidate`** (annotated, "Wave 7 go-live candidate")
   dibuat di commit hasil review bersih dan **di-push ke origin**.
6. **BUILD_LOG.md diperbarui**: satu baris per task W7-T1..T7 + T8 (verdict,
   ditandai "review-at-end in-session, operator-approved deviation") + catatan
   rehearsal/restore + keputusan go-live.
7. **Tanpa secret**: tidak ada password/TOTP/token/credential production di
   chat, report, atau commit; semua bukti disanitasi; tidak ada perubahan
   `docs/kakehashi/`; production tidak disentuh.

**BUKAN goal (jangan dikerjakan):** fitur bisnis baru (termasuk Modul
Keuangan/Kelas/Report/CV), HA/Kubernetes/multi-region/PITR enterprise,
WAF/SIEM/SOC, deployment production, perubahan authority docs, re-audit W0–W6
penuh.

**Definisi "tuntas"**: nomor 1–7 selesai. Setelah itu tulis laporan akhir singkat
(`docs/tmp/UI-W7-ORCHESTRATION-FINAL-REPORT.md`) dan serahkan ke operator —
keputusan go-live final tetap di operator.

---

## 1. Deklarasi penyimpangan aturan produksi (operator-approved)

Aturan yang dilanggar (AGENTS.md + playbook 09/10): **"Builder dan Reviewer harus
conversation terpisah; wave tidak bisa PASS tanpa verdict Reviewer terpisah."**

Keputusan operator: Wave 7 dieksekusi orkestrasi satu agent (builder + reviewer),
review di akhir wave, mengikuti preseden Wave 4/5/6 yang berhasil
(`docs/tmp/UI-W6-ORCHESTRATION-HANDOFF.md`). Dicatat di BUILD_LOG; **tidak
mengubah authority docs**.

Mitigasi wajib:

1. Report per task dengan bukti command + hasil (pola Wave 4–6).
2. Test otomatis per task — suite fokus + regresi + pint hijau sebelum task
   berikutnya.
3. Review akhir memakai checklist Reviewer playbook 10 penuh; verdict + severity
   + evidence dicatat.
4. Kasus keamanan/irreversibilitas wajib punya test otomatis (step-up missing,
   guard gagal, rollback file failure, Guest exclusion, restore sukses) —
   bukan klaim manual.
5. Operator tetap boleh memanggil Reviewer terpisah sebelum go-live; hasil ini
   bukan pengganti permanen aturan produksi. Go-live PASS = prasyarat,
   keputusan final tetap operator.

---

## 2. Required reading (WAJIB dibaca sebelum task pertama)

1. `AGENTS.md`
2. `docs/kakehashi/README.md` · `docs/kakehashi/BUILD_INVARIANTS.md`
3. `docs/kakehashi/playbook/10_WAVE_7_HARDENING.md`
4. `docs/kakehashi/technical/SECURITY_CHECKLIST.md`
5. `docs/kakehashi/technical/DATA_RETENTION_AND_PRIVACY.md`
6. `docs/kakehashi/technical/DEPLOYMENT.md`
7. `docs/kakehashi/technical/BACKUP_AND_RECOVERY.md`
8. `docs/kakehashi/modules/MODULE_AUTH.md` · `MODULE_CANDIDATES.md` ·
   `MODULE_GUEST_ACCESS.md`
9. PRD §7.9, §9.1, §9.5, §9.6
10. Preseden: `docs/tmp/UI-W6-ORCHESTRATION-HANDOFF.md` (pola orkestrasi),
    `docs/tmp/UI-W5-MANUAL-SMOKE-AGENT-HANDOFF.md` (pola checkpoint operator +
    secret)

---

## 3. Environment

| Item | Nilai |
| --- | --- |
| Branch | `ui-w7-hardening` (dari `edb42cb`) — `git checkout ui-w7-hardening` + pull |
| DB test | `kakehashi_test` (PostgreSQL 18 — wajib untuk behavior/concurrency/migration) |
| Env test | `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql` |
| Redis | Local, `noeviction`, bind localhost (T4 verifikasi) |
| R2 | Test/fake sesuai pola existing; bucket backup terpisah untuk T6 (test-only) |
| Staging | Ephemeral VPS test **atau** staging lokal production-like — bukan production DB |
| Stack | PHP 8.4 · Laravel 13 · Livewire 4 · Tailwind 4 (tidak berubah) |
| Production | **TIDAK disentuh** |

---

## 4. Urutan eksekusi (JANGAN lompat; satu task selesai → verifikasi → lanjut)

| # | Task | Gate | Report |
| --- | --- | --- | --- |
| T1 | RBAC regression (negative suite) | Tidak ada bypass role/policy | `docs/tmp/UI-W7-T1-BUILDER-REPORT.md` |
| T2 | Anonimisasi UI (Super Admin + step-up) | Guard Wave 3 dipakai; tanpa soft-delete/restore | `docs/tmp/UI-W7-T2-BUILDER-REPORT.md` |
| T3 | Anonimisasi E2E (tombstone/file/audit) | Irreversible; Guest exclusion | `docs/tmp/UI-W7-T3-BUILDER-REPORT.md` |
| T4 | Security hardening (headers/HTTPS/Redis/logs) | Checklist SECURITY_CHECKLIST PASS | `docs/tmp/UI-W7-T4-BUILDER-REPORT.md` |
| T5 | Staging rehearsal ⚠ operator | Production-like smoke; bukan production DB | `docs/tmp/UI-W7-T5-BUILDER-REPORT.md` |
| T6 | Backup/restore ⚠ operator | Restore ke DB temporary berhasil (hard gate) | `docs/tmp/UI-W7-T6-BUILDER-REPORT.md` |
| T7 | Go-live review (decision record) | Verdict Reviewer; PASS sebelum data nyata | `docs/tmp/UI-W7-T7-BUILDER-REPORT.md` |
| T8 | **Review-at-end** | PASS bersih sebelum tag | `docs/tmp/UI-W7-T8-REVIEW-AT-END-REPORT.md` |

### Checkpoint operator (WAJIB STOP + minta persetujuan — T5/T6)

T5/T6 menyentuh infrastruktur test. Sebelum mulai, tulis di chat
`CHECKPOINT OPERATOR — <T5|T6> — <kebutuhan>` dan tunggu `LANJUT`:

- T5: konfirmasi staging = ephemeral VPS test **atau** staging lokal
  production-like; kredensial test-only; domain/email/Drive policy/TOTP test
  disiapkan; bukan production.
- T6: konfirmasi bucket R2 backup terpisah (test); izin restore ke database
  temporary; hasil output disanitasi.

Jika operator tidak menyediakan → tulis BLOCKED pada task itu, lanjut ke task
code yang bisa (T1–T4/T8 checklist), jangan improvise infrastruktur.

---

## 5. Aturan mengejar goal

- Sekali mulai: lanjut T1→T8 tanpa menunggu prompt tambahan, **kecuali**
  checkpoint T5/T6 dan stop condition. Jangan berhenti menunggu approval per
  task code.
- Setiap selesai task: self-verify (baca diff sendiri, jalankan test fokus +
  regresi + pint) → tulis report → lanjut.
- Bug lintas-task yang ditemukan saat jalan: fix minimal di task berjalan, catat
  di report task itu.
- Jika sebuah task membutuhkan keputusan produk yang tidak ada di authority →
  **stop** (bukan tebak), tulis temuan + aksi operator, tunggu keputusan.
- Secret: tidak pernah minta/baca/tulis secret di chat; kredensial test dipakai
  lewat env lokal (`.env.migrator`/test-only), bukan ditulis; output rehearsal
  disanitasi sebelum masuk report.

---

## 6. Test wajib (agregat)

- T1: negative suite RBAC — role tidak bisa akses di luar policy; self-decision
  guard; step-up missing → ditolak.
- T2/T3: eligible anonimisasi; setiap guard gagal (pending aktif, participation
  aktif, placement Bekerja, revision aktif, availability bukan Tersedia);
  step-up missing; rollback file failure sesuai desain; audit
  `CANDIDATE_ANONYMIZED`; kandidat anonim tidak muncul Guest; tombstone
  irreversible (tidak ada edit/restore).
- T4: headers, HTTPS redirect (staging), APP_DEBUG off, firewall, Redis
  `noeviction` + localhost bind, dua worker, scheduler, log tanpa secret.
- T5: smoke production-like — login, read data hasil restore, Redis 2 worker,
  schedule, R2 photo test, Guest headers/whitelist.
- T6: backup artifact ada di bucket terpisah; restore ke DB temporary sukses;
  aplikasi login + membaca hasil restore; Redis tidak di-backup sebagai bisnis.
- Per task: `php artisan test` fokus + `vendor/bin/pint --test`; migrasi fresh
  PostgreSQL hanya bila schema berubah.

---

## 7. Stop conditions (satu-satunya alasan berhenti di tengah)

- Restore test belum benar-benar berhasil → **stop, jangan lanjut go-live**.
- Guest PII test gagal.
- RBAC negative test gagal.
- Redis/PostgreSQL terbuka publik.
- `APP_DEBUG` aktif di environment production-like.
- Anonimisasi bisa dijalankan tanpa step-up/guard.
- Secret muncul di repository, prompt, atau bukti.
- Authority conflict (PRD/DATA_RETENTION/SECURITY) → stop, lapor, tunggu
  keputusan.
- T8 menemukan Blocker/Major belum difix → jangan tag; fix dulu, review ulang.

Jika stop condition terpicu: tulis laporan posisi, daftar apa yang selesai/belum,
dan aksi operator yang dibutuhkan — lalu berhenti.

---

## 8. Deliverables (wajib saat tuntas)

1. `docs/tmp/UI-W7-T1..T7-BUILDER-REPORT.md` (7 laporan)
2. `docs/tmp/UI-W7-T8-REVIEW-AT-END-REPORT.md` (verdict + severity + evidence +
   temuan + hasil fix)
3. `docs/tmp/UI-W7-ORCHESTRATION-FINAL-REPORT.md` (ringkasan akhir, commit yang
   dibuat, tag, state, bukti rehearsal/restore yang disanitasi)
4. BUILD_LOG.md: baris W7-T1..T7 + T8 (ditandai "review-at-end in-session,
   operator-approved deviation") + catatan rehearsal/restore + status keputusan
   go-live
5. Commit per task (`w7(t{1..7}): ...` atau setara pola repo); branch di-push;
   tag `wave-7-go-live-candidate` dibuat di commit review bersih dan di-push

---

## 9. Perintah pertama (untuk agent eksekutor — salin apa adanya)

```text
Anda adalah Orchestrator Wave 7 Kakehashi (builder + reviewer dalam satu sesi,
review di akhir — penyimpangan operator-approved, pola Wave 5/6). Kejar FINAL
GOAL dokumen ini sampai tuntas: docs/tmp/UI-W7-ORCHESTRATION-HANDOFF.md

Baca §0–§3 dulu, lalu eksekusi §4–§6 tanpa berhenti di tengah, kecuali stop
condition §7 dan checkpoint operator §4 (T5/T6 — tulis CHECKPOINT OPERATOR dan
tunggu LANJUT). Verifikasi diri per task; jangan menunggu prompt tambahan.
Branch: ui-w7-hardening. DB: kakehashi_test. Tanpa secret; tanpa ubah
docs/kakehashi/; production TIDAK disentuh; Build Log hanya baris task §8.
Selesai = tag wave-7-go-live-candidate ter-push + final report + BUILD_LOG
terisi + keputusan go-live diserahkan ke operator.
```

Approval operator untuk mulai:
`APPROVED — START UI-W7 ORCHESTRATION`
