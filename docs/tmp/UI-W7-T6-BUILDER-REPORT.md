# UI-W7-T6 — Backup/Restore — Builder Report (BLOCKED)

**Task:** W7-T6 · **Branch:** `ui-w7-hardening` · **Status:** **BLOCKED — menunggu checkpoint operator**

## Checkpoint operator (handoff §4)

```text
CHECKPOINT OPERATOR — T6 — konfirmasi bucket R2 backup terpisah (test); izin restore ke
database temporary; hasil output disanitasi.
```

Checkpoint sudah disampaikan ke operator pada 2026-08-07; belum ada `LANJUT`/konfirmasi. Sesuai handoff: **tidak ada restore yang dijalankan, tidak ada bucket/infrastruktur yang disentuh**.

## Yang sudah siap

- Skema backup/restore mengikuti `BACKUP_AND_RECOVERY.md`: `pg_dump` harian → R2 bucket terpisah, retensi 14 harian + 12 mingguan, Redis **tidak** di-backup sebagai data bisnis.
- Postgres `pg_dump` tersedia (PostgreSQL 18 lokal, `kakehashi_test`).
- Restore test wajib ke database temporary (hard gate) — belum dieksekusi menunggu izin operator.

## Yang belum bisa diverifikasi (butuh checkpoint)

- Backup artifact di bucket R2 terpisah (test-only).
- Restore dump ke database temporary benar-benar berhasil.
- Aplikasi login + membaca data hasil restore.
- Skrip backup/cron production-like + log ukuran dump.

## Aksi operator

Balas `LANJUT` + konfirmasi bucket R2 backup test (atau instruksi memakai storage lokal test), izin restore ke DB temporary (mis. `kakehashi_restore`), dan konfirmasi sanitasi output.
