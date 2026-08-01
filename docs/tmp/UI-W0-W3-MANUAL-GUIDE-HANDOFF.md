# Handoff Agent Pemandu — Manual Test UI Wave 0–3

## Status

- Verdict build: PASS WITH NON-BLOCKING NOTES.
- UI siap untuk manual smoke operator.
- Branch: ui-w0-w3-build.
- Final evidence commit: 864b0c6.
- Database manual: kakehashi.
- User synthetic sebelum handoff: sudah dibersihkan; jumlah user saat ini 0.
- Lookup seed dan kode aplikasi tidak di-reset pada cleanup terakhir.
- Manual smoke belum mengesahkan Wave 4.

## Peran agent pemandu

Anda adalah Agent Pemandu Manual Test. Memori Anda terpisah dari Builder dan
Reviewer sebelumnya. Tugas Anda bukan memperbaiki kode, melainkan memandu
operator menguji UI dari awal secara bertahap.

Aturan interaksi:

1. Mulai dari satu langkah kecil saja.
2. Beri route, role, aksi, dan expected result dengan bahasa sederhana.
3. Tunggu hasil operator sebelum masuk ke langkah berikutnya.
4. Jika operator menemukan masalah, beri ID temuan, catat expected/actual, lalu
   tentukan apakah skenario perlu dihentikan atau dapat dilanjutkan.
5. Jangan meminta password, TOTP secret, recovery code, private URL, atau
   credential produksi dikirim ke chat.
6. Jangan membuat fake-data control, bypass Policy/service, atau mengubah kode.
7. Jangan mengubah verdict Build Log. Hanya hasil manual operator yang boleh
   dicatat pada report manual.
8. Jika data bisnis belum tersedia melalui UI, tandai BLOCKED dan jelaskan data
   yang kurang; jangan memaksa PASS dengan direct database bypass.

## Authority dan scope

Gunakan urutan authority repository:

1. AGENTS.md.
2. docs/kakehashi/README.md.
3. docs/kakehashi/BUILD_INVARIANTS.md.
4. docs/kakehashi/ui/UI_WIREFRAME_NOTES.md.
5. docs/kakehashi/ui/DESIGN.md.
6. UI_BUILD_EXECUTION_W0_W3_BUILDER.md.
7. BUILD_LOG.md dan file finalisasi ini.

Notion tidak diperlukan. Jangan mengubah docs/kakehashi/. Scope manual hanya:

- Auth A1–A6;
- Lookup/admin S1–S5;
- Candidate K1–K5;
- notification, ID/JP, loading, validation, forbidden, empty, dan 409 state.

Di luar scope: K6, Jobs, Placement, Guest, anonymization UI, Wave 4, dan
perubahan domain.

## Kondisi awal yang sudah diketahui

Database lokal dicek pada 2026-08-01:

- database: kakehashi;
- user tercatat: 0;
- empat user synthetic sebelumnya telah dihapus;
- password, TOTP, recovery code, dan token tidak dibaca.

Sebelum membuat user baru, minta operator memastikan aplikasi memakai local
database dan bukan production:

~~~bash
grep -E '^(APP_ENV|APP_URL|DB_HOST|DB_PORT|DB_DATABASE|REDIS_HOST)=' .env
php artisan about
~~~

Jika DB_DATABASE bukan database development local, stop.

## Temuan pertama operator

### M-001 — Switch bahasa tidak terlihat di halaman login

Status: OBSERVATION — VERIFY AGAINST BLUEPRINT.

Operator melaporkan bahwa pada route /login tidak terlihat switch ID/JP.

Authority yang perlu dibaca:

- UI_WIREFRAME_NOTES menyatakan semua layar internal memiliki toggle bahasa
  ID/JP, sementara Tamu JP terkunci.
- DESIGN.md menempatkan toggle ID/JP di top bar authenticated shell.
- A1 Login adalah route public/auth, sehingga perlu dicatat apakah aturan
  “semua layar internal” mencakup halaman login public.

Jangan langsung mengubah kode atau menyimpulkan FAIL. Agent pemandu harus:

1. Minta operator membuka /login dalam kondisi logout.
2. Catat viewport/browser, locale awal, dan elemen yang terlihat di header.
3. Konfirmasi bahwa yang hilang memang switch bahasa, bukan menu yang tertutup
   atau asset yang gagal dimuat.
4. Tanyakan expected operator: apakah ID/JP harus dapat diubah sebelum login?
5. Catat M-001 sebagai OPEN sampai ada keputusan UI/authority.
6. Lanjutkan A1 dengan locale default ID jika operator menyetujui, atau tandai
   BLOCKED jika switch login merupakan syarat wajib manual test.

Format catatan M-001:

~~~text
ID: M-001
Route: /login
Observation: switch ID/JP tidak terlihat
Locale awal:
Browser/viewport:
Expected menurut operator:
Authority yang mendukung:
Actual:
Status: OPEN / BLOCKED / ACCEPTED AS DESIGN
Tidak ada perubahan kode pada tahap manual smoke.
~~~

## Urutan pemanduan manual

### Langkah 0 — Jalankan aplikasi

Jika belum berjalan:

Terminal aplikasi:

~~~bash
php artisan serve --host=127.0.0.1 --port=8000
~~~

Asset production sudah tersedia dari npm run build. Vite HMR opsional:

~~~bash
npm run dev
~~~

Buka http://127.0.0.1:8000/login.

### Langkah 1 — Buat user synthetic local

Database saat ini kosong. Setelah operator menyetujui setup local, gunakan
Tinker untuk membuat user test. Jangan menampilkan password ke chat atau
report; password factory hanya untuk local smoke.

~~~bash
php artisan db:seed --class=RolePermissionSeeder --force
php artisan db:seed --class=LookupSeeder --force
php artisan tinker
~~~

Di Tinker:

~~~php
use App\Models\User;
use Modules\Auth\Rbac;

$admin = User::factory()->active()->create([
    'name' => 'Manual Super Admin',
    'email' => 'manual-admin@example.test',
]);
$admin->assignRole(Rbac::SUPER_ADMIN);

$staff = User::factory()->active()->create([
    'name' => 'Manual Staf Input',
    'email' => 'manual-staff@example.test',
]);
$staff->assignRole(Rbac::STAFF_INPUT);

$approver = User::factory()->active()->create([
    'name' => 'Manual Approver Kandidat',
    'email' => 'manual-approver@example.test',
]);
$approver->assignRole(Rbac::CANDIDATE_APPROVER);

$manager = User::factory()->active()->create([
    'name' => 'Manual Asisten Manajer',
    'email' => 'manual-manager@example.test',
]);
$manager->assignRole(Rbac::ASSISTANT_MANAGER);
~~~

Keluar dengan exit. Semua user factory memakai password local default.
Super Admin dan Approver Kandidat harus menyelesaikan enrollment 2FA melalui
UI, bukan melalui direct database update.

### Langkah 2 — A1 Login

Role pertama: Staf Input, karena tidak membutuhkan 2FA.

Uji:

- email valid + password valid;
- email kosong;
- password salah;
- email yang tidak ada;
- logout lalu kembali ke /login.

Expected:

- email adalah satu-satunya identifier;
- validasi tampil jelas;
- login gagal tidak membocorkan apakah email terdaftar;
- login sukses menuju internal shell;
- tidak ada akses internal tanpa autentikasi.

Catat hasil A1 sebelum masuk A2.

### Langkah 3 — A2 Forced Password

Gunakan user synthetic terpisah yang dibuat dengan must_change_password=true
atau gunakan flow reset password admin pada tahap S4.

Expected:

- user diarahkan ke forced password screen;
- password lama tidak dapat dipakai untuk melewati forced change;
- password baru mengikuti policy;
- setelah berhasil, user dapat login normal.

Jika belum ada user forced dan pembuatan melalui UI belum tersedia, tandai
BLOCKED — test data contract, jangan mengubah DB secara manual hanya untuk
memaksa PASS.

### Langkah 4 — A3/A4 TOTP

Gunakan Super Admin atau Approver Kandidat.

Uji:

- enrollment QR;
- konfirmasi kode valid;
- recovery codes ditampilkan sekali;
- logout/login ulang;
- TOTP valid;
- TOTP salah;
- session challenge expired bila dapat diuji tanpa brute force.

Expected:

- secret tidak muncul di query string, log, atau halaman yang tidak perlu;
- kode salah ditolak;
- role yang mewajibkan 2FA tidak masuk internal shell sebelum enrollment.

### Langkah 5 — A5 Lockout

Gunakan user synthetic khusus. Masukkan password salah sampai threshold yang
ditentukan aplikasi, lalu berhenti.

Expected:

- throttle/lockout tampil;
- countdown atau lockout state jelas;
- tidak ada percobaan brute force lanjutan;
- user lain tidak ikut terkunci.

### Langkah 6 — A6 Step-up

Setelah Super Admin selesai enrollment:

- S4 assign role/deactivate user;
- S1 manage lookup;
- S2 decision request;
- S3 manage company;
- uji kode salah, expired, dan wrong scope.

Expected:

- hanya trigger yang memerlukan step-up yang meminta step-up;
- reactivate/reset password mengikuti contract yang sudah ada;
- wrong entity/action tidak dapat menggunakan elevation token.

### Langkah 7 — S1–S5

Ikuti urutan:

1. S1 /lookup: ID/JP, create/edit, code immutable, soft-disable, inactive
   parent, permission negative.
2. S2 /lookup/requests: request → approve/reject, reject note wajib,
   double decision 409, tanpa pending_request.
3. S3 /companies: bilingual company data, edit, soft-disable, request flow.
4. S4 /admin/users: search, roles, deactivate/reactivate, reset password.
   User creation tetap deferred dan tidak boleh dianggap bug UI.
5. S5 /audit: filter action/entity/actor/date, detail tanpa password/TOTP/token/
   secret/raw private data.

Setiap screen harus diuji juga dengan role yang tidak berhak.

### Langkah 8 — K1–K5

Buat candidate hanya melalui flow UI yang tersedia.

1. K1 /candidates: search/filter/status/usia/sort/pagination.
2. K2 detail: read-only, photo signed URL bila storage tersedia, document
   reveal menghasilkan audit.
3. K3 /candidates/create: save draft, reload, submit, NIK server-side,
   similarity soft warning, photo setelah draft tersimpan.
4. K4 /candidates/review: approve/reject, note wajib, maker self-approval,
   stale/double decision 409.
5. K5 revision: approved candidate → create revision → edit/save/submit →
   review approve; NIK, availability, dan history tetap.

Jika R2/R2 storage atau Google Drive belum dikonfigurasi local, tandai hanya
sub-skenario file sebagai BLOCKED, bukan seluruh K2/K3.

## Format hasil manual

Agent pemandu membuat atau memperbarui:

docs/tmp/UI-W0-W3-MANUAL-TEST-RESULTS.md

Gunakan format:

~~~text
Tanggal:
Branch/commit:
Browser/OS:
Database: kakehashi local synthetic

| ID | Role | Result | Actual/Bukti | Blocker |
|----|------|--------|--------------|---------|
| M-001 | Guest | OPEN/BLOCKED/ACCEPTED | ... | ... |
| A1 | Staf Input | PASS/FAIL/BLOCKED | ... | ... |
~~~

Bukti boleh berupa screenshot yang sudah disensor. Jangan masukkan password,
TOTP secret, recovery code, token, email produksi, atau private Drive URL.

## Kriteria akhir manual smoke

Manual smoke selesai jika:

- semua skenario yang dapat dijalankan memiliki PASS/FAIL/BLOCKED;
- setiap FAIL memiliki actual result dan langkah reproduksi;
- setiap BLOCKED memiliki alasan lingkungan/data;
- M-001 memiliki status dan keputusan operator;
- tidak ada temuan yang ditutup hanya karena test automated lulus.

Hasil manual adalah feedback operator untuk UI. Hasil ini tidak mengubah
verdict build secara otomatis dan tidak mengesahkan Wave 4 tanpa gate W4.
