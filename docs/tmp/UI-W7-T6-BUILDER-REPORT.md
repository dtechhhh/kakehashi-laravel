# UI-W7-T6 — Backup/Restore Rehearsal — Builder Report

**Task:** W7-T6 · **Branch:** `ui-w7-hardening` (`4f63403`) · **Date:** 2026-08-08
**Mode:** review-at-end in-session, operator-approved deviation (handoff §1)

## Perubahan kode

- `app/Console/Commands/BackupDatabase.php` (baru) — `php artisan backup:database`:
  `pg_dump --no-owner --no-acl` → `gzip` → upload ke bucket R2 **terpisah** (`R2_BACKUP_BUCKET`), verifikasi `headObject` size > 0, retensi lokal 3 hari, log `backup.log`. Redis tidak pernah di-backup.

## Rehearsal (test-only)

1. **Backup artifact**: `php artisan backup:database` → `kakehashi_db_20260808_121819.sql.gz` (22.375 bytes) ter-upload ke `kakehashi-test-backup`; `listObjectsV2` = 1 artifact.
2. **Restore ke DB temporary**: `DROP/CREATE kakehashi_restore` → `gunzip | psql` sukses (ON_ERROR_STOP).
3. **Data hasil restore**: users=4, candidate=1, interview_container=1, guest_link=1.
4. **Aplikasi membaca hasil restore**: `.env` diarahkan ke `kakehashi_restore` → login `/home` menampilkan `W7 Staff`; `/candidates` menampilkan kandidat uji; `/guest/candidates` menampilkan NIK. Setelah itu app dikembalikan ke DB utama dan login ulang sukses.
5. **Redis**: tidak di-backup (tidak ada dump/artefak Redis); setelah restore, sesi Redis tetap valid (login ulang OK).
6. **Cron harian**: `0 2 * * *` backup terpasang di `/etc/cron.d/kakehashi`.

## Temuan penting (runbook)

- Restore ke database baru memerlukan **grant ulang role runtime** setelah dump:
  `GRANT USAGE ON SCHEMA public` + `GRANT SELECT/INSERT/UPDATE/DELETE ON ALL TABLES` + sequence + `ALTER DEFAULT PRIVILEGES` (dump tidak membawa default privileges database tujuan). Tanpa ini, aplikasi dapat `permission denied` — sudah terbukti dan diverifikasi kembali setelah grant.

## Risiko / catatan

- Retensi R2 (14 harian/12 mingguan) dikelola operator via lifecycle bucket — di luar scope code.
- Prosedur Drive manual anonimisasi tetap terpisah (DATA_RETENTION).

## Stop condition

Restore test **berhasil** — hard gate terpenuhi; tidak ada yang terpicu.
