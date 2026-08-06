# UI-W5 Manual Smoke — Reviewer Report

- Task: `UI-W5-T9-MANUAL-SMOKE-01` (review)
- Date: 2026-08-07
- Branch / HEAD ditinjau: `ui-w5-placement` @ `c86ff67`
- DB diverifikasi: `kakehashi_r3_manual` (PostgreSQL 127.0.0.1:5432)
- Sumber: `docs/tmp/UI-W5-MANUAL-SMOKE-RESULTS.md` + `docs/tmp/ui-w5-manual-smoke-evidence/`
- Reviewer: percakapan terpisah — tidak mengubah kode
- **Verdict: PASS WITH NON-BLOCKING NOTES**

---

## Metode verifikasi

1. Baca RESULTS lengkap (preflight, 40 baris skenario, findings, handoff).
2. Cross-check klaim invariant terhadap DB `kakehashi_r3_manual`
   (tabel `placement_container`, `placement_participants`, `participation`,
   `candidate`, `pending_request`, `audit_log`) dan kode lang/component.
3. Evidence folder: 40 PNG hadir sesuai nama matriks (tidak dapat diinspeksi
   visual oleh Reviewer; diverifikasi eksistensi + konsistensi nama).

## Cross-check invariant (klaim agent vs bukti DB/kode)

| Invariant | Klaim agent | Bukti verifikasi Reviewer |
| --- | --- | --- |
| Transfer normal: source→Terkirim | PASS (S4-03) | `participation` id 3,4 = `Terkirim`, unfrozen ✓ |
| Transfer normal: availability tetap Sedang Dipakai | PASS | Kandidat 28/29 final `TERSEDIA` hanya setelah status terminal; tidak ada jejak `Tersedia` pada fase batch (konsisten dengan service + picker) ✓ |
| Batch atomik | PASS | `PLACEMENT_BATCH` approved 1; semua partisipan kontainer 6 punya status konsisten; tidak ada partial ✓ |
| Bekerja satu per kandidat | PASS | Tidak ada `Bekerja` aktif tersisa; tiap kandidat maksimal 1 baris placement ✓ |
| Force-Majeur tanpa step-up | PASS (S5-04) | Audit `FORCE_MAJEUR_ADDED` ada; tidak ada `STEPUP_REAUTH` pada jalur FM ✓ |
| FM_REJECTED kanonik | PASS (S5-05) | `pending_request` FORCE_MAJEUR rejected 1 + audit `FM_REJECTED` ✓ |
| Expel wajib step-up | PASS (S6-05/06) | `STEPUP_REAUTH` ada (5× total sesi); approve expel tidak jalan tanpa modal ✓ |
| Formula akhir kontrak | PASS (S4-03) | 2026-08-10 + 6 bulan − 1 hari = 2027-02-09 (DB cocok) ✓ |
| Arsip otomatis | PASS (S6-08) | Kontainer 6 `Arsip`, `archived_at` 2026-08-06 23:00:52 setelah 3 partisipan terminal ✓ |
| Tidak ada archive manual | PASS | Tidak ada aksi/tombol; kontainer terminal read-only (S7-04) ✓ |
| Perusahaan immutable | PASS (S2-03) | `PC_COMPANY_IMMUTABLE`; DB tetap perusahaan id 1 ✓ |
| Self-approve diblokir | PASS (S2-05/S7-01) | Maker akses queue → 403 ✓ |
| 409 versi | PASS (S7-03) | Kontainer 1 versi 3, mutasi stale tidak diterapkan ✓ |
| Audit keputusan | PASS | `BATCH_SENT`, `FM_REJECTED`, `FORCE_MAJEUR_ADDED`, `PC_*`, `RESIGN_*`, `PLACEMENT_EXPEL_*` hadir ✓ |

## Disposisi temuan agent

| ID | Severity agent | Disposisi Reviewer |
| --- | --- | --- |
| F1 | Non-blocking (UI) | **Dipertahankan sebagai note — belum tereproduksi oleh inspeksi kode**: `lang/id/ui.php` (baris 272–278) dan `lang/ja/ui.php` (baris 426–430) memuat `ui.placement.status.*` untuk kelima status; `APP_LOCALE=id`, fallback `id`. Kemungkinan penyebab: locale sesi selain id/ja (mis. en tanpa `lang/en/ui.php`), atau cache terjemahan. Perlu re-check/repro 5 menit; cosmetic saja, tidak menahan gate. |
| F2 | Info | Diterima — leftover pending `PC_CREATE` P-2026-00001 (data display lama); tidak mengganggu path inti. |
| F3 | Info | Diterima — batch >50 tidak bisa dikonstruksi (hanya 2 eligible); limit dikonfirmasi di service (`MAX_BATCH=50`) + teks UI. |
| F4 | Info | Diterima — S2-06 diverifikasi in-session; screenshot tidak wajib. |
| F5 | Info | Diterima — mutasi kontainer leftover synthetic (409 test); bukan production. |

## Path inti minimum (§8 handoff)

1. S2 draft→submit→approve tanpa step-up — PASS
2. S4 Bekerja + Terkirim + availability tetap Sedang Dipakai — PASS
3. S5 Force-Majeur tanpa step-up — PASS
4. S6 expel dengan step-up + arsip otomatis — PASS
5. S7 self-approve diblokir + 409 — PASS

Semua terpenuhi. Tidak ada Blocker/Major; tidak ada pelanggaran invariant
Wave 5 (tanpa window Tersedia normal, tanpa markInUse normal, tanpa partial
batch, tanpa step-up FM, tanpa archive manual, `FM_REJECTED` kanonik).

## Gate

- UI Wave 5 manual smoke: **PASS WITH NON-BLOCKING NOTES**.
- Catatan F1 layak diperbaiki sebelum/lintas Wave 6 (kosmetik); tidak
  memblokir dimulainya Wave 6 (Guest) dari sisi smoke UI.
- Rekomendasi: catat hasil ini di `BUILD_LOG.md` oleh operator; tag
  `ui-w5-placement-complete` opsional.

## Lampiran bukti

- `docs/tmp/ui-w5-manual-smoke-evidence/` — 40 PNG sesuai matriks S1–S7 + DP.
- Query DB verifikasi: placement_container (6), placement_participants (3,
  semua terminal), participation (3,4 Terkirim), candidate 26–29
  Disetujui+TERSEDIA, pending_request (leftover PC_CREATE 1), audit_log
  (BATCH_SENT, FM_REJECTED, FORCE_MAJEUR_ADDED, STEPUP_REAUTH 5).
