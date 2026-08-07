# UI-W7-T7 — Go-Live Review Decision Record — Builder Report (DRAFT)

**Task:** W7-T7 · **Branch:** `ui-w7-hardening` · **Status:** **DRAFT — keputusan final menunggu T5/T6/T8**

## Decision record — status gate go-live (2026-08-07)

| Gate | Status | Bukti |
| --- | --- | --- |
| RBAC negative suite (T1) | PASS | `RbacNegativeRegressionTest` 16/16 (255 assertions) |
| Anonimisasi UI Super Admin + step-up (T2) | PASS | `CandidateAnonymizeScreensTest` 6/6; service + step-up |
| Anonimisasi E2E + Guest exclusion (T3) | PASS | `CandidateAnonymizationTest` 6/6; foto R2 terhapus, audit, guards |
| Security hardening headers/HTTPS/debug/Redis/log (T4) | PASS (code) | `SecurityHardeningTest` 6/6 + `RedisEnvironmentTest`; firewall/worker menunggu staging |
| Staging rehearsal (T5) | **BLOCKED** | Checkpoint operator belum dijawab; infra tidak disentuh |
| Backup/restore (T6) | **BLOCKED** | Checkpoint operator belum dijawab; restore test belum dijalankan |
| Review-at-end (T8) | IN PROGRESS | Checklist berjalan; item infra ikut BLOCKED |
| Tag `wave-7-go-live-candidate` | BELUM DIBUAT | Wajib hanya setelah T5/T6/T8 bersih |
| Production | **TIDAK DISENTUH** | — |

## Keputusan yang direkomendasikan

- **JANGAN buka production / data nyata** selama T5 (staging production-like) dan T6 (restore ke DB temporary benar-benar berhasil) belum lulus — hard stop condition handoff §7.
- Setelah operator menyediakan staging/backup test → lanjut T5/T6 → T8 review penuh → perbaiki temuan → tag `wave-7-go-live-candidate` → keputusan go-live final tetap operator.
- Opsi staging yang paling ringan sesuai authority: **staging lokal production-like** (Nginx/PHP-FPM opsional; PostgreSQL 18 + Redis lokal sudah tersedia), atau ephemeral VPS test jika operator menyediakan akses.

## Aksi operator

1. Jawab checkpoint T5 (pilihan staging + kredensial test-only) dan T6 (bucket R2 backup test + izin DB temporary).
2. Atau konfirmasi penundaan Wave 7; repo sudah berisi T1–T4 + report di `ui-w7-hardening` (`e0be2a5`).
