# UI-W5-T8 — Review-at-End Report

**Mode:** review-at-end in-session, operator-approved deviation (handoff §1; preseden Wave 4). Reviewer = orkestrator yang sama, **tanpa mengubah kode selama review**; perbaikan temuan dilakukan setelah checklist (commit `0cd0f71` untuk atomicity sweeper).

**Cakupan review:** seluruh diff `0a50b2c..HEAD` (branch `wave-5-placement`) terhadap Playbook 08 Reviewer prompt + MODULE_PLACEMENT + STATUS_STATE_MACHINE §2/§4/§7 + BUSINESS_RULES + DATABASE_SCHEMA §5.4 + API_CONTRACTS.

## Checklist Reviewer (Playbook 08)

| No | Item | Hasil | Evidence |
|---|---|---|---|
| 1 | Transfer normal atomik (insert Bekerja + source→Terkirim + availability tetap Sedang Dipakai) | ✅ | `PlacementBatchApproveTest::test_valid_batch_...`: participant `Bekerja`+`source_participation_id`, source `Terkirim` version 1, candidate `SEDANG_DIPAKAI` **version 0** (tidak ada flip `markInUse`) |
| 2 | Tidak ada window `Tersedia` | ✅ | idem (version kandidat tidak berubah; `assertInUse` dipakai, `markInUse`/`markAvailable` tidak ada di `PlacementBatchService` — grep terverifikasi) |
| 3 | Rollback total batch (satu invalid → seluruh batch batal) | ✅ | `test_one_invalid_row_rolls_back_the_whole_batch`: 0 participant, semua source tetap `Siap Dikirim`, pending tetap pending, 0 audit `BATCH_SENT`; juga `test_second_container_cannot_pull_the_same_candidate` |
| 4 | Lock & revalidasi (container → source `FOR UPDATE`, version, pending) | ✅ | `activeContainer()` + `InterviewPlacementTransferService::assertReadyForPlacement(lock: true)`; stale version → `CONFLICT` 409; double approve → `APV_DONE` 409 |
| 5 | Payload snapshot submit (≤50, source belum diubah) | ✅ | `PlacementBatchSubmitTest`: pending `PLACEMENT_BATCH` + snapshot lengkap (source/candidate version, visa, tanggal, end-date); source & availability tidak berubah; `PC_BATCH_TOO_LARGE` untuk 51 |
| 6 | Maker tidak self-approve | ✅ | `PlacementContainerLifecycleTest`, `PlacementBatchApproveTest`, `PlacementForceMajeurTest` — `APV_SELF` 403 |
| 7 | Force-Majeur: source null, Tersedia+Disetujui, kategori+alasan wajib, atomik | ✅ | `PlacementSchemaTest` (CHECK `pp_force_majeur_chk`), `PlacementForceMajeurTest` (`FM_STATE`, `FM_REASON`, rollback saat kandidat berpindah) |
| 8 | Force-Majeur **tanpa step-up** | ✅ | `test_approve_is_routine_without_stepup...`: sukses tanpa token step-up |
| 9 | `FM_REJECTED` kanonik | ✅ | `test_rejection_records_canonical_fm_rejected...`: audit `FM_REJECTED`, kandidat tidak berubah |
| 10 | Formula akhir kontrak inklusif (`mulai + durasi − 1 hari`) | ✅ | `2026-01-15 + 3 → 2026-04-14`; `2026-09-01 + 12 → 2027-08-31` (submit & approve test); `ContractDates` satu implementasi dipakai batch + FM |
| 11 | Resign approval rutin; expel wajib step-up | ✅ | `PlacementContractStatusTest`: resign approve tanpa token sukses; expel tanpa token → 403 `STEPUP_REQUIRED`; dengan token → `Dikeluarkan`; `APV_NOTE` untuk justifikasi checker |
| 12 | Archive otomatis setelah `Bekerja` terakhir terminal, dicek setelah batch; tidak prematur; sweeper idempoten; tanpa manual | ✅ | `PlacementArchiveTest` 4/4: tidak prematur (2 Bekerja → 1 terminal tetap Aktif), arsip setelah terakhir terminal, empty container tidak diarsip, sweeper idempoten (audit tunggal), guard hanya dari `Aktif`; `maybeArchiveContainer` transaksional |
| 13 | P-YYYY-NNNNN saat submit pertama; perusahaan immutable; cancel pre-Aktif; version → 409 | ✅ | `PlacementContainerLifecycleTest` 8/8 |
| 14 | One `Bekerja` partial unique + FM CHECK + index pagination (PostgreSQL) | ✅ | `PlacementSchemaTest` 6/6 via `pg_indexes` + violation test |
| 15 | Audit enum kanonik benar | ✅ | `PC_*`, `BATCH_SENT`, `FORCE_MAJEUR_ADDED`, `FM_REJECTED`, `PLACEMENT_STATUS_CHANGED`, `RESIGN_*`, `PLACEMENT_EXPEL_*`, `CONTAINER_ARCHIVED` diuji; `BATCH_REJECTED` = ekstensi non-breaking (lihat temuan N-2) |
| 16 | Lintas-modul: mutasi hanya via public service | ✅ | Placement services tidak menyentuh tabel `participation`/`candidate` (grep terverifikasi); `InterviewPlacementTransferService` (Jobs) + `CandidateAvailabilityService` (Candidates) |

## Severity & temuan

**Blocker:** tidak ada.

**Major:** tidak ada.

**Non-blocking notes:**
- **N-1 (scope):** escape `Aktif → Dibatalkan` saat `count(participants)=0` (GAP-4, PRD §7.6) tidak diimplementasikan karena handoff T1 menetapkan "cancel hanya pre-Aktif". Perlu keputusan operator bila escape dibutuhkan (kemungkinan saat UI Wave 5).
- **N-2 (enum):** `BATCH_REJECTED` ditambahkan ke `ActionType` untuk mendukung reject batch yang diwajibkan PRD §6.4 ("disetujui atau ditolak seluruhnya"); penambahan non-breaking sesuai DATABASE_SCHEMA §7 (pola F-016 `FM_REJECTED`).
- **N-3 (audit submit):** submit batch/FM tidak menulis event audit submit karena tidak ada event kanonik; jejak = row `pending_request` + audit keputusan (`BATCH_SENT`/`FORCE_MAJEUR_ADDED`/`FM_REJECTED`).
- **N-4 (notifikasi):** notifikasi in-app saat request FM memakai label `FORCE_MAJEUR_ADDED` (tidak ada event REQUESTED); tidak memengaruhi kebenaran bisnis/audit.

## Perbaikan selama review
- `maybeArchiveContainer` dibungkus `DB::transaction` agar jalur sweeper (di luar transaksi terminal) tetap atomik: update status + insert audit commit bersama (commit `0cd0f71`, termasuk commit T7).

## Verifikasi agregat
- Full suite: **610 tests / 609 passed / 4992 assertions / 1 skipped** (R2 live smoke, env-gated).
- `vendor/bin/pint --test` → passed; `git diff --check` → bersih.
- Tidak ada perubahan `docs/kakehashi/`; tidak ada secret di commit/report.

## Verdict

**PASS WITH NON-BLOCKING NOTES** — seluruh gate W5-T1..T7 terpenuhi; tidak ada Blocker/Major; tag boleh dibuat.
