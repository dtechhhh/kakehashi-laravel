# Handoff Reviewer Masa Depan — Penyelesaian Manual Test UI W0–W3

## 1. Misi

Anda adalah Reviewer/Pemandu Manual Test dengan memory terpisah dari Builder
dan Reviewer automated sebelumnya. Tugas Anda adalah memandu operator sampai
catatan manual UI W0–W3 selesai dan mencatat hasil aktual. Tugas ini bukan
implementasi kode, bukan redesign, dan bukan pembukaan Wave 4.

Mulai dari awal secara bertahap. Berikan satu instruksi pendek setiap giliran,
tunggu hasil operator, lalu lanjutkan skenario berikutnya. Jangan mengasumsikan
operator sudah membaca percakapan sebelumnya.

## 2. Konteks percakapan yang harus dipahami

- Notion tidak pernah terhubung ke repository. Mode repo-first aktif; repository
  dan BUILD_LOG.md adalah sumber operasional.
- UI test surface dibangun untuk Wave 0–3 agar operator dapat menguji A1–A6,
  S1–S5, dan K1–K5 sebelum Wave 4.
- Builder UI selesai pada branch ui-w0-w3-build. Repair R1–R3 memindahkan
  query User/Schema dari Livewire ke public service dan menambah negative/
  regression tests.
- Automated verification final:
  - focused UI/notification: 124 passed;
  - auth/RBAC/lookup contract suite: 22 passed;
  - full PostgreSQL suite: 423 passed, 1 skipped;
  - lint, asset build, route list, diff check, dan static boundary scan lulus.
- Final Reviewer verdict build: PASS WITH NON-BLOCKING NOTES — READY FOR
  OPERATOR MANUAL SMOKE.
- Final documentation commit: 864b0c6, sudah dipush ke branch ini.
- S4 user creation masih deferred karena public contract createUser belum ada.
- K6, Jobs, Placement, Guest, dan Wave 4 bukan scope manual UI W0–W3.
- Foto R2 dan Google Drive dapat BLOCKED bila credential/storage local belum
  tersedia; jangan mengubah BLOCKED menjadi PASS dengan bypass.

## 3. Kondisi database saat handoff

Database lokal yang dicek adalah kakehashi. Empat user synthetic sebelumnya:

- Manual Super Admin;
- Manual Staf Input;
- Manual Approver Kandidat;
- Manual Asisten Manajer.

sudah dihapus secara eksplisit. Database tidak di-migrate-fresh pada cleanup
terakhir. Jumlah user saat handoff: 0. Lookup seed tetap tersedia.

Sebelum membuat user baru, minta operator memverifikasi .env menunjuk database
development local, bukan production. Jangan meminta atau menampilkan password,
TOTP secret, recovery code, token, private URL, atau credential apa pun.

## 4. File sumber dan file hasil

Baca sebelum memandu:

1. AGENTS.md.
2. docs/kakehashi/README.md.
3. docs/kakehashi/BUILD_INVARIANTS.md.
4. docs/kakehashi/ui/UI_WIREFRAME_NOTES.md.
5. docs/kakehashi/ui/DESIGN.md.
6. UI_BUILD_EXECUTION_W0_W3_BUILDER.md.
7. BUILD_LOG.md.
8. docs/tmp/UI-W0-W3-MANUAL-GUIDE-HANDOFF.md.
9. docs/tmp/UI-W0-W3-MANUAL-FINDINGS.md.

Gunakan file berikut:

- Panduan langkah lengkap: docs/tmp/UI-W0-W3-MANUAL-GUIDE-HANDOFF.md.
- Temuan awal: docs/tmp/UI-W0-W3-MANUAL-FINDINGS.md.
- Hasil yang harus dibuat/diperbarui: docs/tmp/UI-W0-W3-MANUAL-TEST-RESULTS.md.

docs/tmp adalah working material. Jangan mengubah docs/kakehashi/ dan jangan
mengubah application code dalam tugas manual ini.

## 5. Temuan pertama yang harus diselesaikan

### M-001 — Switch bahasa tidak terlihat di /login

Operator melaporkan tidak menemukan switch ID/JP pada halaman login.

Mulai manual test dengan langkah ini:

1. Minta operator logout.
2. Minta operator membuka /login.
3. Minta operator menyebutkan browser, viewport, locale awal, dan control
   header yang terlihat.
4. Minta operator memastikan tidak ada toggle yang tersembunyi di menu,
   terpotong viewport, atau gagal karena asset.
5. Bandingkan dengan authority:
   - semua layar internal memiliki toggle ID/JP;
   - DESIGN menempatkan toggle di authenticated top bar;
   - login adalah public auth route.
6. Catat actual dan expected di UI-W0-W3-MANUAL-TEST-RESULTS.md.
7. Jangan menyimpulkan defect tanpa keputusan expected behavior operator/
   authority.

Status M-001 yang valid:

- OPEN — perlu keputusan;
- ACCEPTED AS DESIGN — login public tidak membutuhkan toggle, authenticated
  shell memilikinya;
- BLOCKED — tidak dapat diverifikasi karena environment;
- FAIL — FIX REQUIRED — blueprint dan actual jelas bertentangan.

## 6. Urutan manual test

Setelah M-001 dicatat, pandu satu per satu:

1. A1 Login: email-only, validasi, password salah, guest access.
2. A2 Forced password: redirect dan password policy.
3. A3 TOTP enrollment: QR, confirm, recovery-code display.
4. A4 TOTP challenge: valid/invalid code dan recovery flow.
5. A5 Lockout: gunakan user synthetic, berhenti setelah threshold.
6. A6 Step-up: role/deactivate, lookup/company/request scope.
7. S1 Lookup: ID/JP, CRUD, immutable code, soft-disable, parent inactive.
8. S2 Request queue: approve/reject, note wajib, double decision 409,
   decision source lookup_request/company_request tanpa pending_request.
9. S3 Company: bilingual CRUD, soft-disable, request flow.
10. S4 User management: list/search/roles/deactivate/reactivate/reset; user
    creation tetap deferred.
11. S5 Audit: filter/detail dan tidak ada secret.
12. K1 Candidate list: filter, sort whitelist, pagination, permission.
13. K2 Candidate detail: read-only, private photo, document reveal audit.
14. K3 Candidate form: draft, submit/NIK, similarity warning, photo after save.
15. K4 Review: approve/reject, note wajib, maker guard, stale/double 409.
16. K5 Revision: diff, resubmit, approve, NIK/history preservation.

Untuk setiap langkah, laporkan:

- role dan route;
- aksi operator;
- expected;
- actual;
- PASS, FAIL, atau BLOCKED;
- screenshot/reference yang sudah disensor;
- blocker dan langkah reproduksi bila gagal.

## 7. Aturan keputusan

- Automated PASS tidak menutup manual finding.
- BLOCKED hanya untuk dependency/environment/data yang nyata, bukan untuk
  behavior yang gagal.
- FAIL harus memiliki actual result dan reproduction.
- Jika menemukan behavior failure authorization, privacy, step-up, approval,
  atau state transition, hentikan flow terkait dan laporkan sebelum meminta
  perubahan kode.
- Jangan membuat fake-data control, direct DB bypass, atau secret di report.
- Manual result tidak mengubah Build Log final verdict secara otomatis.
- Wave 4 tetap menunggu gate Wave 4 meskipun manual UI lulus.

## 8. Hasil akhir

Buat atau perbarui:

docs/tmp/UI-W0-W3-MANUAL-TEST-RESULTS.md

Minimal header:

~~~text
Tanggal:
Branch:
Commit:
Browser/OS:
Database: kakehashi local synthetic
Operator:

| ID | Role | Result | Actual/Bukti | Blocker |
|----|------|--------|--------------|---------|
| M-001 | Guest/logout | OPEN | ... | ... |
| A1 | Staf Input | PASS/FAIL/BLOCKED | ... | ... |
~~~

Tutup handoff hanya setelah semua skenario yang dapat dijalankan memiliki
status dan semua blocker tercatat. Jika operator berhenti di M-001, simpan
M-001 sebagai OPEN dan jangan mengklaim manual smoke selesai.

## 9. Pesan pembuka yang disarankan

Mulai dengan:

“Manual smoke kita mulai dari M-001. Silakan logout dan buka /login.
Sebutkan browser, ukuran viewport, locale awal, dan apakah ada control bahasa
di header. Jangan kirim password atau secret. Setelah itu saya catat hasilnya
sebelum kita lanjut ke A1.”

## 10. Stop conditions

Stop dan laporkan bila:

- database bukan local synthetic;
- perlu credential produksi atau secret dikirim;
- authority UI bertentangan;
- pengguna meminta perubahan kode di tengah manual test;
- diperlukan bypass Policy/service/2FA;
- manual failure berdampak pada privacy, authorization, approval, atau state
  machine;
- data/storage external tidak tersedia; tandai sub-skenario BLOCKED.

