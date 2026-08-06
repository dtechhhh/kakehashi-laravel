# Wave 5 Placement — Orchestration Handoff (STANDALONE, goal-driven)

**Task ID:** `W5-ORCHESTRATE-PLACEMENT-01`
**Status:** READY FOR ORCHESTRATOR — menunggu approval operator
**Intended executor:** Agent orkestrator lain (conversation baru, tanpa konteks sebelumnya)
**Mode:** Orkestrasi satu conversation — agent = Builder + Reviewer dalam satu sesi, review di akhir (penyimpangan operator-approved, pola Wave 4)
**Branch:** `wave-5-placement` (dibuat dari `0a50b2c`, sudah ter-push ke origin)
**Working material only** — tidak mengubah `docs/kakehashi/`, Build Log (kecuali baris task yang ditentukan), credential pack

Dokumen ini **standalone** dan **goal-driven**: baca seluruhnya, kejar FINAL GOAL sampai tuntas, jangan berhenti di tengah kecuali stop condition (bagian 7).

---

## 0. FINAL GOAL (ditetapkan sebelum eksekusi — kejar sampai tuntas)

**Selesai hanya jika SEMUA berikut terpenuhi:**

1. **W5-T1..W5-T7 terimplementasi dan hijau** di branch `wave-5-placement` (DB `kakehashi_test`, PostgreSQL):
   - T1 Container Placement lifecycle: Draft → Menunggu Approval → Aktif, kode **P-YYYY-NNNNN** saat submit pertama, `perusahaan` immutable setelah dibuat, cancel hanya pre-Aktif, Maker tidak self-approve, optimistic `version` → 409.
   - T2 Schema Participation: CHECK Force-Majeur (`source_participation_id IS NULL` ⟺ kategori+alasan terisi), partial unique **satu `Bekerja` per kandidat**, index pagination — lulus migration test PostgreSQL.
   - T3 Batch submit: maksimum **50**, pending `PLACEMENT_BATCH` dengan **payload snapshot**, source participation **belum diubah**.
   - T4 Batch approve: **transfer atomik** — insert placement `Bekerja`, source→`Terkirim`, availability **tetap `Sedang Dipakai`** (pakai `assertInUse`, **bukan** `markInUse`); revalidasi pending/source/candidate dalam satu transaksi; **satu kandidat invalid → rollback seluruh batch**; double approve → 409; stale source → reject.
   - T5 Force-Majeur: source null, kandidat `Disetujui + Tersedia`, kategori+alasan wajib, pending, approval rutin **tanpa step-up**, `markInUse` saat approve, audit `FORCE_MAJEUR_ADDED` / **`FM_REJECTED` canonical**.
   - T6 Status kontrak: `Selesai Kontrak`, `Mengundurkan Diri` (approval rutin), `Dikeluarkan` (**step-up wajib**); tanggal akhir kontrak = mulai + durasi bulan − **1 hari** (inklusif); catatan wajib untuk terminal non-auto.
   - T7 Archive: hanya `Aktif → Arsip` **otomatis** setelah seluruh kandidat `Bekerja` terminal, dicek setelah batch, sinkron in-transaction + sweeper harian idempoten sebagai safety; **tidak ada archive manual**.

2. **Self-verification per task**: setiap task diakhiri report `docs/tmp/UI-W5-T{n}-BUILDER-REPORT.md` (file diubah, command, hasil test, risiko) dan test fokus + pint hijau **sebelum** lanjut ke task berikutnya.

3. **Review-at-end (W5-T8) selesai**: checklist Reviewer playbook 08 dijalankan penuh oleh orkestrator (atomicity, rollback total, no-window-Tersedia, FM tanpa step-up, FM_REJECTED, formula kontrak inklusif, expel step-up, archive tidak prematur/idempoten) → verdict + severity + evidence di `docs/tmp/UI-W5-T8-REVIEW-AT-END-REPORT.md`.

4. **Temuan review**: Blocker/Major apa pun yang ditemukan T8 **wajib diperbaiki dulu** (fix minimal) dan direview ulang sampai bersih — tag tidak boleh dibuat sebelum bersih.

5. **Tag `wave-5-placement-complete`** (annotated, pesan "Wave 5 Placement complete") dibuat di HEAD dan **di-push ke origin**; branch `wave-5-placement` di-push.

6. **BUILD_LOG.md diperbarui**: satu baris per task W5-T1..T7 + baris T8 dengan verdict, ditandai "review-at-end in-session, operator-approved deviation".

7. **Tanpa secret**: tidak ada password/TOTP/token/credential di chat, report, atau commit; tidak ada perubahan `docs/kakehashi/`.

**BUKAN goal (jangan dikerjakan):** Guest public Wave 6, deployment/VPS, UI Wave 5 screens, tag lain, perubahan authority docs.

**Definisi "tuntas"**: nomor 1–7 di atas selesai. Setelah itu tulis laporan akhir singkat (`docs/tmp/UI-W5-ORCHESTRATION-FINAL-REPORT.md`) dan serahkan ke operator.

---

## 1. Deklarasi penyimpangan aturan produksi (operator-approved)

Aturan yang dilanggar (AGENTS.md + playbook 07/08): **"Builder dan Reviewer harus conversation terpisah; wave tidak bisa PASS tanpa verdict Reviewer terpisah."**

Keputusan operator: W5 dieksekusi orkestrasi satu agent (builder + reviewer), review di akhir wave, mengikuti preseden Wave 4 yang berhasil. Dicatat di BUILD_LOG; **tidak mengubah authority docs**.

Mitigasi wajib:

1. Report per task dengan bukti command + hasil (pola Wave 4).
2. Test otomatis per task — suite fokus + regresi + pint hijau sebelum task berikutnya.
3. Review akhir memakai checklist Reviewer playbook 08 penuh; verdict + severity + evidence dicatat.
4. Kasus konkurrensi/atomicity wajib punya test otomatis (double approve, rollback total, stale source) — bukan klaim manual.
5. Operator tetap boleh memanggil Reviewer terpisah sebelum Wave 6; hasil ini bukan pengganti permanen aturan produksi.

## 2. Required reading (WAJIB dibaca sebelum task pertama)

1. `AGENTS.md`
2. `docs/kakehashi/README.md` · `docs/kakehashi/BUILD_INVARIANTS.md`
3. `docs/kakehashi/playbook/08_WAVE_5_PLACEMENT.md`
4. `docs/kakehashi/modules/MODULE_PLACEMENT.md`
5. `docs/kakehashi/foundation/STATUS_STATE_MACHINE.md` (§2, §4, §7)
6. `docs/kakehashi/foundation/BUSINESS_RULES.md` (availability/Force-Majeur/concurrency)
7. `docs/kakehashi/technical/DATABASE_SCHEMA.md` (§5.4 Placement, `container_counter` P-)
8. `docs/kakehashi/technical/API_CONTRACTS.md`
9. `docs/kakehashi/modules/MODULE_AUTH.md` (step-up)
10. `docs/kakehashi/technical/SECURITY_CHECKLIST.md`
11. Preseden: `docs/tmp/UI-W0-W3-R2-TASK3-BUILDER-DELEGATION.md` (pola delegasi), `docs/tmp/UI-W4-MANUAL-SMOKE-EXECUTION-PLAN.md` (struktur goal-driven)

## 3. Environment

| Item | Nilai |
| --- | --- |
| Branch | `wave-5-placement` (dari `0a50b2c`) — pastikan `git checkout wave-5-placement` + pull |
| DB test | `kakehashi_test` (PostgreSQL 18 — wajib untuk behavior/concurrency/migration) |
| Env test | `set -a; source .env.migrator; set +a; export DB_DATABASE=kakehashi_test; export DB_CONNECTION=pgsql` |
| Stack | PHP 8.4 · Laravel 13 · Livewire 4 · Tailwind 4 (tidak berubah) |
| VPS | Tidak diperlukan |

## 4. Urutan eksekusi (JANGAN lompat; satu task selesai → verifikasi → lanjut)

| # | Task | Gate | Report |
| --- | --- | --- | --- |
| T1 | Container lifecycle | Perusahaan immutable; kode P saat submit pertama | `docs/tmp/UI-W5-T1-BUILDER-REPORT.md` |
| T2 | Participant schema | FM CHECK + one-Bekerja unique; migration test PostgreSQL | `docs/tmp/UI-W5-T2-BUILDER-REPORT.md` |
| T3 | Batch submit | Payload snapshot ≤50; source belum diubah | `docs/tmp/UI-W5-T3-BUILDER-REPORT.md` |
| T4 | Batch approve | Transfer atomik; tidak ada window Tersedia | `docs/tmp/UI-W5-T4-BUILDER-REPORT.md` |
| T5 | Force-Majeur | Tanpa step-up; FM_REJECTED | `docs/tmp/UI-W5-T5-BUILDER-REPORT.md` |
| T6 | Status kontrak | Formula inklusif; expel step-up | `docs/tmp/UI-W5-T6-BUILDER-REPORT.md` |
| T7 | Archive + sweeper | Setelah Bekerja terakhir terminal; idempoten | `docs/tmp/UI-W5-T7-BUILDER-REPORT.md` |
| T8 | **Review-at-end** | PASS bersih sebelum tag | `docs/tmp/UI-W5-T8-REVIEW-AT-END-REPORT.md` |

Setiap task: patch terkecil, satu slice, mutasi hanya lewat public service (`InterviewPlacementTransferService`, `CandidateAvailabilityService` `assertInUse`/`markInUse` sesuai kasus, `StepUpService`, `AuditLogger`, `NotificationService` + after-commit). Tidak ada akses langsung tabel lintas-modul. Tidak ada abstraksi spekulatif.

## 5. Aturan mengejar goal

- Sekali mulai: **lanjut terus T1→T8 tanpa menunggu prompt tambahan**. Jangan berhenti menunggu approval per task.
- Setiap selesai task: self-verify (baca diff sendiri, jalankan test fokus + regresi + pint) → tulis report → lanjut.
- Bug lintas-task yang ditemukan saat jalan: fix minimal di task berjalan, catat di report task itu.
- Jika sebuah task membutuhkan keputusan produk yang tidak ada di authority → **stop** (bukan tebak), tulis temuan + aksi operator, tunggu keputusan.
- Secret: tidak pernah minta/baca/tulis secret di chat; jika ada jalur manual (tidak diharapkan di W5 domain), gunakan `STOP FOR OPERATOR INPUT — <label> — <jenis>` dan tunggu `LANJUT`.

## 6. Test wajib (agregat)

- T2: migration + CHECK constraint (PostgreSQL).
- T3/T4: valid batch; 1 row invalid → rollback total; double approve → 409; stale source → reject; availability tetap `Sedang Dipakai`; source→`Terkirim`; placement→`Bekerja`; `assertInUse` bukan `markInUse` pada transfer normal.
- T5: guard kategori+alasan; no step-up; FM_REJECTED canonical; rollback.
- T6: formula `mulai + durasi − 1 hari`; resign rutin; expel tanpa step-up → ditolak.
- T7: archive tidak prematur; otomatis saat Bekerja terakhir terminal; sweeper idempoten; in-transaction.
- Per task: `php artisan test` fokus + `vendor/bin/pint --test`; migrasi fresh hanya bila schema berubah (T2).

## 7. Stop conditions (satu-satunya alasan berhenti di tengah)

- Batch mengubah sebagian data saat error (partial success).
- Transfer normal memanggil `markInUse` atau membuat availability `Tersedia`.
- Force-Majeur meminta step-up.
- Archive manual / prematur.
- Authority conflict (PRD/MODULE_PLACEMENT/DATABASE_SCHEMA) → stop, lapor, tunggu keputusan.
- T8 menemukan Blocker/Major belum difix → jangan tag; fix dulu, review ulang.

Jika stop condition terpicu: tulis laporan posisi, daftar apa yang selesai/belum, dan aksi operator yang dibutuhkan — lalu berhenti.

## 8. Deliverables (wajib saat tuntas)

1. `docs/tmp/UI-W5-T1..T7-BUILDER-REPORT.md` (7 laporan)
2. `docs/tmp/UI-W5-T8-REVIEW-AT-END-REPORT.md` (verdict + severity + evidence + temuan + hasil fix)
3. `docs/tmp/UI-W5-ORCHESTRATION-FINAL-REPORT.md` (ringkasan akhir, commit yang dibuat, tag, state)
4. BUILD_LOG.md: baris per task W5-T1..T7 + T8 (ditandai "review-at-end in-session, operator-approved deviation")
5. Commit per task (`w5(t{1..7}): ...` atau setara pola repo), branch di-push, tag `wave-5-placement-complete` di-push

## 9. Perintah pertama (untuk agent eksekutor — salin apa adanya)

```text
Anda adalah Orchestrator Wave 5 Kakehashi (builder + reviewer dalam satu sesi,
review di akhir — penyimpangan operator-approved). Kejar FINAL GOAL dokumen ini
sampai tuntas: docs/tmp/UI-W5-ORCHESTRATION-HANDOFF.md

Baca §0–§3 dulu, lalu eksekusi §4–§6 tanpa berhenti di tengah, kecuali stop
condition §7. Verifikasi diri per task; jangan menunggu prompt tambahan.
Branch: wave-5-placement. DB: kakehashi_test. Tanpa secret; tanpa ubah
docs/kakehashi/; Build Log hanya baris task §8.
Selesai = tag wave-5-placement-complete ter-push + final report + BUILD_LOG terisi.
```

Approval operator untuk mulai:
`APPROVED — START UI-W5 ORCHESTRATION`
