---
title: "04 — Wave 1: Auth, Audit & Approval Foundation"
status: "FINAL v1"
source_notion_title: "04 — Wave 1: Auth, Audit & Approval Foundation"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 04 — Wave 1: Auth, Audit & Approval Foundation

> [!NOTE]
> **Wave 1 — Auth, Audit & Approval Foundation.** Bangun satu fondasi bersama untuk identitas, otorisasi, audit, approval, notifikasi in-app, dan after-commit sebelum domain lain dibuat.
>
## Apa Artinya untuk Operator
Setelah wave ini, siapa pun yang memakai aplikasi punya identitas dan hak akses yang benar. Semua domain selanjutnya memakai cara approval yang sama—bukan membuat pola sendiri.
## Prasyarat
- [ ] Wave 0 lulus dan tag `wave-0-baseline` tercatat.
- [ ] PostgreSQL test dan Redis development bekerja.
- [ ] Builder dan Reviewer terpisah.
## Dokumen Wajib untuk Builder
- PRD §4–§6.1, §7.4, §7.10, Lampiran A/D
- MODULE_AUTH
- ROLES_AND_PERMISSIONS
- BUSINESS_RULES §1 dan §8A
- STATUS_STATE_MACHINE
- DATABASE_SCHEMA §5.6–§5.7
- API_CONTRACTS §5–§7
- SECURITY_CHECKLIST
## Lingkup Boleh
- User, role, permission, Policy foundation.
- Login email-only, password policy, session idle 30 menit, lockout.
- TOTP, recovery codes, dan step-up.
- Audit immutable dengan role snapshot.
- `pending_request` dan partial unique pending aktif.
- Submit/approve/reject transaction service.
- Notifikasi in-app dan email after-commit stub.
## Lingkup Dilarang
- Tidak membuat Kandidat, Wawancara, Penempatan, atau Guest.
- Tidak memberi Super Admin write pada domain operasional.
- Tidak membuat Tamu sebagai user internal.
- Tidak menggunakan password-only sebagai step-up.
- Tidak memasukkan email mentah, password, TOTP, atau token ke audit.
- Tidak menjalankan email/queue di dalam transaksi bisnis.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W1-T1 User/RBAC schema</td>
<td>users, role, permission, policy baseline</td>
<td>SoD dan self-action guard</td>
</tr>
<tr>
<td>W1-T2 Login/session</td>
<td>Email-only, password policy, session, nonaktif</td>
<td>403/lockout benar</td>
</tr>
<tr>
<td>W1-T3 TOTP/step-up</td>
<td>2FA, recovery, step-up 5 menit per aksi</td>
<td>Hanya 5 trigger final</td>
</tr>
<tr>
<td>W1-T4 Audit immutable</td>
<td>Audit role snapshot dan PII-minimized</td>
<td>Tidak bisa UPDATE/DELETE</td>
</tr>
<tr>
<td>W1-T5 Pending foundation</td>
<td>Schema, partial unique, approve/reject</td>
<td>Pending direvalidasi di transaksi</td>
</tr>
<tr>
<td>W1-T6 Maker-checker gate</td>
<td>Self-approval ditolak</td>
<td>Semua domain nanti wajib memakai</td>
</tr>
<tr>
<td>W1-T7 After-commit</td>
<td>In-app notif + email/queue after commit</td>
<td>Enqueue gagal tidak rollback bisnis</td>
</tr>
<tr>
<td>W1-T8 Review akhir</td>
<td>Regression/security review</td>
<td>PASS sebelum Wave 2</td>
</tr>
</table>
## Prompt Builder — Pending dan Maker-Checker
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W1-T5 atau W1-T6].

Authority: PRD v0.3.14; DECISIONS_LOG Batch A/B; BUSINESS_RULES BR-APV; DATABASE_SCHEMA pending_request; API_CONTRACTS PendingRequestService; ROLES; SECURITY_CHECKLIST.

Wajib implementasikan:
- pending_request sebagai sumber keputusan Checker;
- satu pending aktif per (type,target_type,target_id) melalui partial unique;
- approve/reject di dalam transaksi;
- revalidasi status masih pending di transaksi;
- aksi kedua menghasilkan 409;
- Maker tidak dapat menyetujui request sendiri;
- penolakan wajib note;
- audit sesuai kontrak.

Dilarang membuat domain-specific approval baru atau bypass untuk domain tertentu.

Tambahkan test: sukses, note kosong, self-approve, double-approve konkuren, dan rollback bila audit/validasi gagal.

Laporan akhir harus menunjukkan constraint, transaction boundary, dan hasil test.
```
## Prompt Builder — After-Commit
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan W1-T7: fondasi notification dan after-commit.

Wajib:
1. bisnis + audit + notifikasi in-app DB commit dahulu;
2. email/queue Redis hanya dijadwalkan setelah commit;
3. gagal enqueue dicatat tetapi tidak me-rollback bisnis;
4. fondasi dapat dipakai domain berikutnya tanpa mengarang ulang pola;
5. tidak ada email sinkron pada transaction bisnis.

Tambahkan test yang membuktikan: commit bisnis tetap ada ketika enqueue email disimulasikan gagal; audit dan notifikasi in-app tetap sesuai kontrak.

Jangan membangun workflow engine atau event bus.
```
## Prompt Reviewer — Approval dan After-Commit
```plain text
Anda adalah Reviewer Agent terpisah untuk Wave 1. Jangan mengubah kode.

Tinjau [DIFF/COMMIT dan LAPORAN BUILDER]. Verifikasi:
- email satu-satunya identifier login;
- user Nonaktif tidak bisa login;
- TOTP/recovery/step-up sesuai trigger final;
- audit tidak mencatat PII rahasia;
- audit immutable;
- pending partial unique benar di PostgreSQL;
- approve/reject transaction merevalidasi pending;
- self-approval ditolak server-side;
- double approval memberi satu sukses dan satu 409;
- email/queue benar-benar after-commit;
- enqueue failure tidak rollback bisnis.

Berikan temuan severity dan verdict.
```
## Definition of Done
- [ ] Email-only login dan normalisasi lowercase.
- [ ] Session idle 30 menit dan regenerate pada login/step-up.
- [ ] Peran wajib 2FA tidak dapat akses sebelum enrollment selesai.
- [ ] Recovery code single-use.
- [ ] Step-up hanya pada lima trigger final.
- [ ] Audit immutable; actor role snapshot terisi bila relevan.
- [ ] `pending_request` punya unique aktif dan payload/guard sesuai kontrak.
- [ ] Approve/reject atomik dan anti-double-approval lulus.
- [ ] Maker tidak dapat self-approve.
- [ ] In-app notification commit bersama bisnis.
- [ ] Email/queue after-commit; failure tidak rollback.
- [ ] Test auth negative, concurrency, audit, dan after-commit lulus.
- [ ] Reviewer PASS dan snapshot `wave-1-auth-complete` tercatat.
## Stop Condition
- Approval dapat dilakukan tanpa pending.
- Self-approval lolos meski tombol disembunyikan.
- Queue/email berjalan sebelum commit.
- Email raw atau secret muncul di audit/log.
- Step-up diterapkan ke aksi rutin atau Force-Majeur.
## Kesalahan Umum
- Membuat pola pending sendiri di setiap modul.
- Menggunakan Redis lock sebagai pengganti partial unique/transaksi DB.
- Menganggap UI hidden button sebagai authorization.
- Memakai `password.confirm` untuk step-up password+TOTP.
- Membuat email sebagai prasyarat commit.
## Bukti Sukses Minimum
1. Dua Checker memutus pending sama: satu sukses, satu 409.
2. Maker mencoba approve request sendiri: ditolak server-side.
3. Simulasi enqueue gagal: perubahan bisnis/audit/notifikasi in-app tetap tersimpan.
## Commit dan Snapshot
Commit per kemampuan, bukan “semua auth”. Setelah audit akhir lulus, tag `wave-1-auth-complete` dan catat di Build Log.
---
**Status:** FINAL v1 — panduan operasional Wave 1 siap digunakan.
