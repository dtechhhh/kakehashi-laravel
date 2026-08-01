---
title: "UI Build Plan — Wave 0–3"
status: "PLAN / REPO-FIRST"
updated_at: "2026-08-01"
authority_rank: "implementation-plan"
codex_edit_policy: "DOC-SYNC only"
---

# UI Build Plan — Wave 0–3

## Tujuan

Mengisi lapisan UI yang belum dibangun untuk Wave 0–3 agar operator dapat
melakukan uji coba manual sebelum Wave 4. Plan ini tidak mengubah kesiapan
domain Wave 0–3 dan tidak membuka scope Wave 4.

## Authority dan batasan

1. PRD, decisions, foundation, module, schema, API, security, dan privacy.
2. `UI_WIREFRAME_NOTES.md` untuk alur, role, state, step-up, i18n, Guest, dan
   error/interaction semantics.
3. `DESIGN.md` untuk token visual, tipografi, badge, dan shell.
4. `UI_WIREFRAME_APPROVED_REFS.md` untuk referensi visual saja.

Stack tetap Livewire 4, custom Blade/Livewire, dan Tailwind CSS 4. Approved
HTML tidak menjadi sumber business logic. Raw HTML per layar belum berada di
repo; implementasi awal memakai `DESIGN.md` dan `UI_WIREFRAME_NOTES.md`, lalu
mengekspor satu layar saja melalui DOC-SYNC bila detail visualnya diperlukan.

## Urutan build

### UI-W0 — Foundation shell

Bangun fondasi presentasi yang tidak mengandung domain bisnis:

- layout authenticated dan public, navigation berbasis permission, skip link,
  focus state, typography, spacing, dan token dari `DESIGN.md`;
- default bahasa internal ID dengan toggle ID/JP; Guest tetap JP-only;
- shared loading, empty, validation, forbidden, not-found, session-expired,
  conflict `409`, dan flash/notification states;
- status badge selalu memakai glyph + teks + warna, bukan warna saja;
- asset build dan route smoke untuk memastikan shell dapat dibuka.

Wave 0 tidak membuat login, Candidate, Jobs, Placement, Guest, atau mock
state switcher. Definition of done: shell dapat dirender lokal dengan data
sintetis dan tidak memuat keputusan bisnis.

### UI-W1 — Auth, audit, dan approval foundation

Implementasikan layar yang memakai kontrak Auth Wave 1:

- A1 Login — email-only, error validation, lockout response;
- A2 Force Change Password;
- A3 TOTP Enrollment;
- A4 TOTP Challenge;
- A5 Lockout;
- A6 Step-up re-auth modal;
- S4 account management dan S5 audit viewer bila route/service Wave 1 yang
  bersangkutan sudah tersedia.

Semua submit memakai endpoint/action dan Policy/Service yang ada. UI tidak
menentukan role, approval, step-up, atau lockout hanya dengan menyembunyikan
tombol. Uji manual minimum: login gagal/berhasil, user nonaktif, forced
password change, TOTP/recovery, lockout, step-up sukses/gagal/kedaluwarsa,
dan audit tanpa secret/PII rahasia.

### UI-W2 — Lookup dan master perusahaan

Implementasikan layar yang mengikuti pengecualian status Wave 2:

- S1 CRUD lookup bilingual;
- S2 antrean request lookup dan perusahaan;
- S3 master perusahaan.

Mutasi Super Admin wajib step-up dan audit. UI menampilkan status dari
`lookup_request.status` atau `company_request.status` untuk flow masing-masing;
UI tidak membuat atau mengasumsikan `pending_request` untuk kedua flow itu.
Lookup nonaktif tetap dapat dirender pada data lama, tetapi tidak boleh
dipilih untuk data baru.

Uji manual minimum: label ID/JA, create/edit/soft-disable sesuai permission,
request → decision, maker/checker guard, double decision `409`, dan error
enqueue setelah commit.

### UI-W3 — Candidates

Implementasikan layar Kandidat yang diizinkan Wave 3:

- K1 Candidate list;
- K2 Candidate detail read-only;
- K3 create/edit form;
- K4 review queue;
- K5 revision diff/revision flow.

K6 anonymization UI ditunda ke Wave 7 sesuai guide Wave 3. Layar Kandidat
harus memakai service publik Candidates dan Policy, bukan akses langsung ke
tabel/model dari UI.

Uji manual minimum:

1. buat Draft tanpa NIK/pending;
2. submit → NIK JST dibuat → status menunggu tinjauan;
3. similarity `>= 0.4` tampil sebagai soft warning, bukan block;
4. reviewer reject dengan note → maker edit/resubmit → reviewer approve;
5. revision aktif tunggal dan merge tidak mengubah NIK/history operasional;
6. version conflict menghasilkan `409` dan menyediakan reload;
7. foto privat, link dokumen Drive, loading/error signed link, serta audit
   `IDENTITY_DOC_VIEWED` mengikuti kontrak.

## Aturan lintas-wave

- Tiap layar menggunakan shell dan token yang sama; tidak membuat design
  system kedua.
- Tidak ada preview switcher, developer bar, fake data control, atau offline
  fallback CSS di layar production.
- Accessibility dasar wajib: label, keyboard focus, error association,
  semantic headings, dan status yang tidak bergantung pada warna.
- Polling hanya jika diperlukan dan maksimal 60 detik; tidak memakai WebSocket.
- Lima trigger step-up final dari Auth menjadi satu-satunya trigger step-up.
- Loading, empty, validation, authorization, stale data, `409`, dan server
  error harus memiliki state yang bisa diuji manual.
- Data uji lokal harus sintetis; tidak memakai credential atau URL produksi.
- Scope Guest, Jobs, Placement, dan anonymization penuh tidak dibangun dalam
  plan ini.

## Gate per wave

| Gate | Bukti minimum |
| --- | --- |
| W0 UI | Shell, asset build, route smoke, accessibility dasar, no business logic |
| W1 UI | A1–A6 manual auth/step-up/lockout dan S4/S5 jika service tersedia |
| W2 UI | S1–S3 manual bilingual request/company/soft-disable/step-up |
| W3 UI | K1–K5 manual Draft → submit → review → reject/revise → approve |
| Final W0–W3 | `composer lint`, test terkait pada PostgreSQL, browser smoke, diff review, no secrets |

## Urutan task implementasi

1. `UI-W0-T1` — shell, tokens, layout, navigation, shared states.
2. `UI-W1-T1` — A1–A5 authentication screens.
3. `UI-W1-T2` — A6 step-up plus S4/S5 foundation screens.
4. `UI-W2-T1` — S1 lookup CRUD and bilingual rendering.
5. `UI-W2-T2` — S2 request queue and S3 company master.
6. `UI-W3-T1` — K1/K2 list and read-only detail.
7. `UI-W3-T2` — K3 form and submit/reload/conflict states.
8. `UI-W3-T3` — K4/K5 review and revision flow.
9. `UI-W3-T4` — browser smoke, accessibility, security, and regression review.

Setiap task memakai patch terkecil, satu screen/flow yang dapat diuji, dan
commit terpisah. Reviewer UI tetap read-only dan memberi verdict terpisah.
