# UI-W7-T1 — RBAC Regression (Negative Suite) — Builder Report

**Task:** W7-T1 · **Branch:** `ui-w7-hardening` · **DB:** `kakehashi_test` (PostgreSQL)
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Perubahan

- `tests/Feature/Auth/RbacNegativeRegressionTest.php` (baru) — suite regresi negatif RBAC:
  - matriks role → permission persis `Rbac::ROLE_PERMISSIONS` (tanpa bypass, aktif);
  - user tanpa role: semua permission ditolak;
  - Super Admin Nonaktif ditolak di lapisan HTTP untuk semua permukaan (kandidat, jobs, placement, lookup, admin);
  - matriks route: tiap role dilarang 403 di semua route di luar policy-nya (termasuk Super Admin read-only operasional dan no-role);
  - self-decision guard: Maker tidak bisa memutus `pending_request` miliknya sendiri;
  - step-up missing: `UserRbacService::assignRoles` ditolak 403 `STEPUP_REQUIRED`, tanpa perubahan role dan tanpa audit.

Tidak ada perubahan schema, route, atau kode produksi.

## Command & hasil

```text
php artisan test --filter='RbacNegativeRegressionTest'
=> passed, 16 tests, 255 assertions

php artisan test --testsuite=Feature --filter='RbacNegativeRegressionTest|UserRbacTest|UserRbacConcurrencyTest|TotpStepUpTest|StepUpConcurrencyTest'
=> passed, 46 tests, 526 assertions

vendor/bin/pint --test tests/Feature/Auth/RbacNegativeRegressionTest.php
=> passed
```

## Risiko / catatan

- Ability mentah (`jobs.view`, `placement.view`, `users.view`, `candidate.anonymize`) lolos `Gate::check` pada user Nonaktif via spatie; pertahanan efektif adalah middleware `EnsureAccountIsActive` + policy/status di service. Diuji di lapisan HTTP (semua 403) dan di service T2.
- Super Admin read-only di modul operasional ditegaskan: create/submit/review jobs/placement/kandidat semuanya 403.

## Stop condition

Tidak ada yang terpicu.
