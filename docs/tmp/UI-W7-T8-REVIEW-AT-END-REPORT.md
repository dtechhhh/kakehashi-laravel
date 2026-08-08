# UI-W7-T8 — Review-at-End Report (Orchestrator in-session; operator-approved deviation)

**Wave 7 · Branch:** `ui-w7-hardening` @ HEAD (tag `wave-7-go-live-candidate`) · **DB:** `kakehashi_test`
**Mode:** review-at-end in-session (handoff §1); Reviewer tidak memodifikasi kode.

## Checklist Reviewer Playbook 10 (Wajib verifikasi)

| # | Item | Verdict | Evidence |
| --- | --- | --- | --- |
| 1 | RBAC / step-up negative tests | PASS | `RbacNegativeRegressionTest` 16/16 (role-permission matrix, route matrix, self-decision, step-up missing); `TotpStepUpTest`/`StepUpConcurrencyTest` hijau |
| 2 | Guest PII test | PASS | `GuestPiiLeakTest`, `GuestCandidateListTest`, `GuestCandidateDetailTest`, `GuestSurfaceTest` hijau (G2/G3 whitelist, HIDE, sort/filter allowlist, no raw token) |
| 3 | HTTPS / debug / firewall / Redis noeviction + local bind | PASS | HTTPS 200 + HTTP→301 di VPS staging; `APP_DEBUG=false`; Redis `noeviction` + bind localhost + protected-mode + maxmemory 256MB (live); PostgreSQL 127.0.0.1 only; port 22 terbuka, 80/443 tertutup SG AWS (Nginx terverifikasi internal) |
| 4 | Audit immutable | PASS | `AuditImmutableTest` hijau (trigger + privilege role app, no UPDATE/DELETE) |
| 5 | Anonimisasi eligible + blocked cases | PASS | `CandidateAnonymizationTest` 6/6 + `CandidateAnonymizeScreensTest` 6/6 (eligible, semua guard, step-up missing, non-admin, irreversible, Guest exclusion) |
| 6 | Dua queue worker | PASS | Supervisor `kakehashi-worker_00/01` RUNNING di VPS staging |
| 7 | Backup artifact | PASS | `kakehashi_db_20260808_121819.sql.gz` (22.375 B) di bucket `kakehashi-test-backup`; 1 artifact |
| 8 | Restore ke DB temporary benar-benar berhasil | PASS | Restore ke `kakehashi_restore` sukses: users=4, candidate=1, container=1, guest_link=1 |
| 9 | Aplikasi membaca hasil restore | PASS | Login `/home`, `/candidates`, `/guest/candidates` memakai DB restore — semua marker hijau |
| 10 | Tidak ada secret di bukti | PASS | Scan diff W7 + report W7: hanya key kosong/nama variabel; tanpa password/TOTP/token/credential |

## Evidence tambahan

```text
Full suite (lokal): 763 tests / 762 passed / 6339 assertions / 1 skipped (R2_LIVE_SMOKE env-gated)
vendor/bin/pint --test: passed
git diff --check: clean
Staging smoke (VPS 98.84.35.94): login OK, guest headers/whitelist OK, R2 photo OK, 2 workers OK, scheduler OK
```

## Temuan & severity

- **Blocker:** tidak ada.
- **Major:** tidak ada pada scope kode T1–T4 yang direview.
- **Minor (non-blocking):**
  - N-1: port 80/443 security group AWS tertutup dari internet — buka bila ingin cek via browser; Nginx+HTTPS sudah terverifikasi dari dalam VPS.
  - N-2: sertifikat self-signed di staging; produksi wajib Let's Encrypt + domain.
  - N-3: spek staging 2C/4G di bawah baseline produksi 4C/8G (disengaja, hasil tetap representatif).
  - N-4: retensi R2 (14 harian/12 mingguan) via lifecycle bucket — tanggung jawab operator.

## Verdict

**GO-LIVE PASS** — semua gate Wave 7 terverifikasi: RBAC/step-up, Guest PII, HTTPS/debug/Redis/firewall, audit immutable, anonimisasi eligible+blocked, 2 worker, backup artifact, restore ke DB temporary, aplikasi membaca hasil restore, tanpa secret di bukti. Tanpa Blocker/Major.

**Tag `wave-7-go-live-candidate` DIBUAT & DI-PUSH** — pada commit hasil review bersih; keputusan buka production tetap operator.
