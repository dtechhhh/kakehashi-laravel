# UI_WIREFRAME — HTML (Approved Refs)

<aside>
🖼️

**Koleksi HTML Disetujui — Kakehashi (Referensi Visual).** Kumpulan **HTML hasil Stitch yang SUDAH diverifikasi & disetujui** per layar. **Non-authoritative visual reference** — sumber kebenaran tetap: **PRD_Kakehashi_v0.3.14 > UI_WIREFRAME_NOTES > html/\***. Menggantikan koleksi lama (dinonaktifkan user 2026-07-01 karena mengikuti flow orkestrator lama).

**Gaya = [DESIGN.md](http://DESIGN.md) (§10 Shell) · Field = DATABASE_SCHEMA · Logika/otoritas = UI_WIREFRAME_NOTES.** Stack: A2 — Livewire 4 + Blade/Livewire custom + Tailwind 4 · bahasa default ID (Tamu JP).

</aside>

## Konvensi

- **Hanya HTML yang sudah diverifikasi & disetujui** yang masuk koleksi ini.
- **Tiap layar = 1 sub-halaman** di bawah dokumen ini: berisi (a) prompt Stitch final, (b) HTML dalam code block, (c) tag “Non-authoritative visual reference” + catatan engineer.
- Setiap HTML wajib mewarisi **shell** dari [DESIGN.md](http://DESIGN.md) §10 (bukan menyalin HTML lama mentah — cegah drift).
- Perubahan aturan bisnis/field → perbarui PRD/NOTES/DATABASE_SCHEMA dulu, lalu selaraskan HTML di sini.

## Index Layar

| Kode | Layar | Peran utama | Status | Halaman HTML |
| --- | --- | --- | --- | --- |
| K3 | Kandidat — Form Create/Edit (ANCHOR) | Staf Input | ✅ Disetujui · v3 (selaras PRD v0.3.8) | [Kandidat — Form Create/Edit (ANCHOR)](https://app.notion.com/p/Kandidat-Form-Create-Edit-ANCHOR-575456e1346741f5b131439c476fc1b8?pvs=21) |
| K2 | Kandidat — Detail (read-only) | Approver Kandidat / Staf Input / Super Admin | ✅ Disetujui · final 2026-07-10 | [K2 — Kandidat Detail (read-only)](https://app.notion.com/p/K2-Kandidat-Detail-read-only-b333b020d08e42b8a3d9a63ceb57639c?pvs=21) |
| K1 | Kandidat — List | Staf Input / Approver / Super Admin | ✅ Disetujui · final 2026-07-01 | [K1 — Kandidat List](https://app.notion.com/p/K1-Kandidat-List-fd6cc66166a84631a26dec08bb728c7a?pvs=21) |
| K4 | Kandidat — Antrian Tinjauan (Approver) | Approver Kandidat | ✅ Disetujui · final 2026-07-10 | [K4 — Antrian Tinjauan Kandidat](https://app.notion.com/p/K4-Antrian-Tinjauan-Kandidat-0d68f6ef9aaa4ffeb3716c8240efe08d?pvs=21) |
| K5 | Kandidat — Alur Revisi (Tinjau Diff) | Approver Kandidat / Staf Input | ✅ Disetujui · final 2026-07-10 | [K5 — Alur Revisi Kandidat](https://app.notion.com/p/K5-Alur-Revisi-Kandidat-384832f409c042fb90dc04a7c5207500?pvs=21) |
| K6 | Kandidat — Anonimisasi PII (step-up) | Super Admin | ✅ Disetujui · final 2026-07-10 | [K6 — Anonimisasi PII Kandidat](https://app.notion.com/p/K6-Anonimisasi-PII-Kandidat-5f4e0c33e52242338e1ebea567ddeb65?pvs=21) |
| Auth | Login, 2FA enroll/challenge, lockout, modal step-up | Semua | ✅ A1 (Login) & A2 (Paksa Ganti Password) & A3 (Enroll TOTP) & A4 (Challenge TOTP) & A5 (Lockout) & A6 (Modal Step-up Re-auth) final 2026-07-12 — modul Auth lengkap | [A1 — Login (Auth)](https://app.notion.com/p/A1-Login-Auth-99e683767cd04b87a5ab44921f5a9adb?pvs=21) · [A2 — Paksa Ganti Password (Auth)](https://app.notion.com/p/A2-Paksa-Ganti-Password-Auth-4351637555434b0aa790f339869538e4?pvs=21) · [A3 — Enroll TOTP (Auth)](https://app.notion.com/p/A3-Enroll-TOTP-Auth-3af6445739b34f83b5b4c02671e21741?pvs=21) · [A4 — Challenge TOTP (Auth)](https://app.notion.com/p/A4-Challenge-TOTP-Auth-550f8cf43d2a40019e80c9d1100f0329?pvs=21) · [A5 — Lockout (Auth)](https://app.notion.com/p/A5-Lockout-Auth-ce46639fc20443728aa60d77c0eab5f1?pvs=21) · [A6 — Modal Step-up Re-auth (Auth)](https://app.notion.com/p/A6-Modal-Step-up-Re-auth-Auth-fa7d5c40604141c0b3ed0fae329a1569?pvs=21) |
| W1–W9 | Kontainer Wawancara (list/detail/wizard/approve/tutup/tarik/status/expel/link tamu) | Asisten Manajer / Manajer Job | ✅ W1–W9 final — modul Wawancara lengkap (approve/keluarkan kandidat & link tamu sudah tercakup di W5/W6/W7) | [W1 — Kontainer Wawancara (List)](https://app.notion.com/p/W1-Kontainer-Wawancara-List-f21b86440e3447a9a1bbb24ffd3d6f6b?pvs=21) · [W2 — Detail Kontainer Wawancara](https://app.notion.com/p/W2-Detail-Kontainer-Wawancara-ab7fd17382aa4701a7f80d0f11205b23?pvs=21) · [W3 — Form Buat/Edit Kontainer Wawancara](https://app.notion.com/p/W3-Form-Buat-Edit-Kontainer-Wawancara-bec127cd27ad496096ba60915abcbb1b?pvs=21) · [W4 — Tarik Kandidat (Pemilih)](https://app.notion.com/p/W4-Tarik-Kandidat-Pemilih-0baa161004b84154b44baa0ab0ca7619?pvs=21) · [W5 — Kelola Link Tamu (Kontainer Wawancara)](https://app.notion.com/p/W5-Kelola-Link-Tamu-Kontainer-Wawancara-98f1c830a90849f49b974e3e7b31042f?pvs=21) · [W6 — Antrian Approval (Manajer Job)](https://app.notion.com/p/W6-Antrian-Approval-Manajer-Job-0dc1429e7959412ea01d07695814b8b2?pvs=21) · [W7 — Detail Kandidat dalam Kontainer (Lapisan 2)](https://app.notion.com/p/W7-Detail-Kandidat-dalam-Kontainer-Lapisan-2-7edf20792d0e4848bb5642e656396eaf?pvs=21) |
| P1–P7 | Kontainer Penempatan (list/detail/create/batch/force-majeur/status/approval) | Asisten Manajer / Manajer Job | ✅ P1 (List) final · ✅ P2 (Detail) final · ✅ P3 (Form) final · ✅ P4 (Batch Kirim) final · ✅ P5 (Force Majeur) final · ✅ P6 (Update Status) final · ✅ P7 (Approval Penempatan) final | [P1 — Kontainer Penempatan (List)](https://app.notion.com/p/P1-Kontainer-Penempatan-List-b3ce6965dd8b4560a0e48bdf31dee641?pvs=21) · [P2 — Detail Kontainer Penempatan](https://app.notion.com/p/P2-Detail-Kontainer-Penempatan-afa50495dc4842b99389b9e84e63327e?pvs=21) · [P3 — Form Buat/Edit Kontainer Penempatan](https://app.notion.com/p/P3-Form-Buat-Edit-Kontainer-Penempatan-0cfdf0d63488455caf3f0b07b27ce8a8?pvs=21) · [P4 — Kirim Kandidat ke Penempatan (Batch Normal)](https://app.notion.com/p/P4-Kirim-Kandidat-ke-Penempatan-Batch-Normal-3f582b6f56ee4faaac8d5524f3d0db98?pvs=21) · [P5 — Kirim Kandidat Force Majeur (Tambah Langsung)](https://app.notion.com/p/P5-Kirim-Kandidat-Force-Majeur-Tambah-Langsung-eb8659cfc8ad4dae93147fe9acdc853f?pvs=21) · [P6 — Perbarui Status Penempatan (Selesai / Mengundurkan Diri / Dikeluarkan)](https://app.notion.com/p/P6-Perbarui-Status-Penempatan-Selesai-Mengundurkan-Diri-Dikeluarkan-c2db19ca673b432db4835ec0ec613f0c?pvs=21) · [P7 — Antrian Approval Penempatan (Manajer Job)](https://app.notion.com/p/P7-Antrian-Approval-Penempatan-Manajer-Job-4c9e16b319484c7fbb35ad2a1f2a8c77?pvs=21) |
| S1–S5 | Super Admin (lookup CRUD, request, master perusahaan, akun, audit log) | Super Admin | ✅ S1–S4 final · ✅ S5 final 2026-07-11 — modul Super Admin lengkap | [S1 — CRUD Lookup Bilingual](https://app.notion.com/p/S1-CRUD-Lookup-Bilingual-d58b790b656b4179ba4991ed7af0b64b?pvs=21) · [S2 — Antrian Request Lookup & Perusahaan](https://app.notion.com/p/S2-Antrian-Request-Lookup-Perusahaan-fe12b4b9d7c543d4a716cc4ddf586e74?pvs=21) · [S3 — Master Perusahaan](https://app.notion.com/p/S3-Master-Perusahaan-5a972b2440ec475698d4b1c935379998?pvs=21) · [S4 — Kelola Akun User](https://app.notion.com/p/S4-Kelola-Akun-User-34c2832c630a4bab8537731b66a8a3e5?pvs=21) · [S5 — Audit Log Viewer](https://app.notion.com/p/S5-Audit-Log-Viewer-5e0e7301c9da4d4a8059c363aa5b1023?pvs=21) |
| G1–G3 | Tamu (gerbang token, kode tambahan, GuestCandidateView) — JP-only | Tamu | ✅ G1 & G2 & G3 final 2026-07-12 — modul Tamu lengkap | [G1 — Gerbang Token Tamu (Guest Token Gate)](https://app.notion.com/p/G1-Gerbang-Token-Tamu-Guest-Token-Gate-9b7e27fcf7674f4a882dfda8fc8b8b17?pvs=21) · [G2 — GuestCandidateView (Daftar Kandidat Tamu)](https://app.notion.com/p/G2-GuestCandidateView-Daftar-Kandidat-Tamu-3b4a1a660253495aad267879e592ec24?pvs=21) · [G3 — Detail Kandidat Tamu (read-only)](https://app.notion.com/p/G3-Detail-Kandidat-Tamu-read-only-be2497a17f2145ad8a4b7315d43c1c87?pvs=21) |

---

*Status: ✅ FINAL / DIBEKUKAN; Batch A semantic references diselaraskan 2026-07-14 — semua modul UI lengkap & disetujui: K1–K6 (Kandidat, + anchor K3), Auth A1–A6, W1–W9 (Wawancara), P1–P7 (Penempatan), S1–S5 (Super Admin), G1–G3 (Tamu). Seluruh HTML sudah lolos cek devil's advocate & tercatat sebagai referensi visual disetujui. Non-authoritative visual reference — authority sementara Batch A: PRD v0.3.13 > dokumen domain/schema/modul > Approved HTML > NOTES lama. Sebelum implementasi: hapus switcher state pratinjau + devbar + fallback CSS offline (non-produksi) di tiap HTML. Perubahan lebih lanjut butuh buka-freeze eksplisit dari user.*

[Kandidat — Form Create/Edit (ANCHOR)](https://app.notion.com/p/Kandidat-Form-Create-Edit-ANCHOR-575456e1346741f5b131439c476fc1b8?pvs=21)

[K2 — Kandidat Detail (read-only)](https://app.notion.com/p/K2-Kandidat-Detail-read-only-b333b020d08e42b8a3d9a63ceb57639c?pvs=21)

[K1 — Kandidat List](https://app.notion.com/p/K1-Kandidat-List-fd6cc66166a84631a26dec08bb728c7a?pvs=21)

[W1 — Kontainer Wawancara (List)](https://app.notion.com/p/W1-Kontainer-Wawancara-List-f21b86440e3447a9a1bbb24ffd3d6f6b?pvs=21)

[W2 — Detail Kontainer Wawancara](https://app.notion.com/p/W2-Detail-Kontainer-Wawancara-ab7fd17382aa4701a7f80d0f11205b23?pvs=21)

[W3 — Form Buat/Edit Kontainer Wawancara](https://app.notion.com/p/W3-Form-Buat-Edit-Kontainer-Wawancara-bec127cd27ad496096ba60915abcbb1b?pvs=21)

[W4 — Tarik Kandidat (Pemilih)](https://app.notion.com/p/W4-Tarik-Kandidat-Pemilih-0baa161004b84154b44baa0ab0ca7619?pvs=21)

[W5 — Kelola Link Tamu (Kontainer Wawancara)](https://app.notion.com/p/W5-Kelola-Link-Tamu-Kontainer-Wawancara-98f1c830a90849f49b974e3e7b31042f?pvs=21)

[W6 — Antrian Approval (Manajer Job)](https://app.notion.com/p/W6-Antrian-Approval-Manajer-Job-0dc1429e7959412ea01d07695814b8b2?pvs=21)

[W7 — Detail Kandidat dalam Kontainer (Lapisan 2)](https://app.notion.com/p/W7-Detail-Kandidat-dalam-Kontainer-Lapisan-2-7edf20792d0e4848bb5642e656396eaf?pvs=21)

[HANDOFF — Kakehashi UI Loop (untuk agent lanjutan)](https://app.notion.com/p/HANDOFF-Kakehashi-UI-Loop-untuk-agent-lanjutan-ef2bdcf9cf8041cead8bbad82480160a?pvs=21)

[P1 — Kontainer Penempatan (List)](https://app.notion.com/p/P1-Kontainer-Penempatan-List-b3ce6965dd8b4560a0e48bdf31dee641?pvs=21)

[P2 — Detail Kontainer Penempatan](https://app.notion.com/p/P2-Detail-Kontainer-Penempatan-afa50495dc4842b99389b9e84e63327e?pvs=21)

[P3 — Form Buat/Edit Kontainer Penempatan](https://app.notion.com/p/P3-Form-Buat-Edit-Kontainer-Penempatan-0cfdf0d63488455caf3f0b07b27ce8a8?pvs=21)

[P4 — Kirim Kandidat ke Penempatan (Batch Normal)](https://app.notion.com/p/P4-Kirim-Kandidat-ke-Penempatan-Batch-Normal-3f582b6f56ee4faaac8d5524f3d0db98?pvs=21)

[P5 — Kirim Kandidat Force Majeur (Tambah Langsung)](https://app.notion.com/p/P5-Kirim-Kandidat-Force-Majeur-Tambah-Langsung-eb8659cfc8ad4dae93147fe9acdc853f?pvs=21)

[P6 — Perbarui Status Penempatan (Selesai / Mengundurkan Diri / Dikeluarkan)](https://app.notion.com/p/P6-Perbarui-Status-Penempatan-Selesai-Mengundurkan-Diri-Dikeluarkan-c2db19ca673b432db4835ec0ec613f0c?pvs=21)

[P7 — Antrian Approval Penempatan (Manajer Job)](https://app.notion.com/p/P7-Antrian-Approval-Penempatan-Manajer-Job-4c9e16b319484c7fbb35ad2a1f2a8c77?pvs=21)

[K4 — Antrian Tinjauan Kandidat](https://app.notion.com/p/K4-Antrian-Tinjauan-Kandidat-0d68f6ef9aaa4ffeb3716c8240efe08d?pvs=21)

[K5 — Alur Revisi Kandidat](https://app.notion.com/p/K5-Alur-Revisi-Kandidat-384832f409c042fb90dc04a7c5207500?pvs=21)

[K6 — Anonimisasi PII Kandidat](https://app.notion.com/p/K6-Anonimisasi-PII-Kandidat-5f4e0c33e52242338e1ebea567ddeb65?pvs=21)

[S1 — CRUD Lookup Bilingual](https://app.notion.com/p/S1-CRUD-Lookup-Bilingual-d58b790b656b4179ba4991ed7af0b64b?pvs=21)

[S2 — Antrian Request Lookup & Perusahaan](https://app.notion.com/p/S2-Antrian-Request-Lookup-Perusahaan-fe12b4b9d7c543d4a716cc4ddf586e74?pvs=21)

[S3 — Master Perusahaan](https://app.notion.com/p/S3-Master-Perusahaan-5a972b2440ec475698d4b1c935379998?pvs=21)

[S4 — Kelola Akun User](https://app.notion.com/p/S4-Kelola-Akun-User-34c2832c630a4bab8537731b66a8a3e5?pvs=21)

[S5 — Audit Log Viewer](https://app.notion.com/p/S5-Audit-Log-Viewer-5e0e7301c9da4d4a8059c363aa5b1023?pvs=21)

[G1 — Gerbang Token Tamu (Guest Token Gate)](https://app.notion.com/p/G1-Gerbang-Token-Tamu-Guest-Token-Gate-9b7e27fcf7674f4a882dfda8fc8b8b17?pvs=21)

[G2 — GuestCandidateView (Daftar Kandidat Tamu)](https://app.notion.com/p/G2-GuestCandidateView-Daftar-Kandidat-Tamu-3b4a1a660253495aad267879e592ec24?pvs=21)

[G3 — Detail Kandidat Tamu (read-only)](https://app.notion.com/p/G3-Detail-Kandidat-Tamu-read-only-be2497a17f2145ad8a4b7315d43c1c87?pvs=21)

[A1 — Login (Auth)](https://app.notion.com/p/A1-Login-Auth-99e683767cd04b87a5ab44921f5a9adb?pvs=21)

[A2 — Paksa Ganti Password (Auth)](https://app.notion.com/p/A2-Paksa-Ganti-Password-Auth-4351637555434b0aa790f339869538e4?pvs=21)

[A3 — Enroll TOTP (Auth)](https://app.notion.com/p/A3-Enroll-TOTP-Auth-3af6445739b34f83b5b4c02671e21741?pvs=21)

[A4 — Challenge TOTP (Auth)](https://app.notion.com/p/A4-Challenge-TOTP-Auth-550f8cf43d2a40019e80c9d1100f0329?pvs=21)

[A5 — Lockout (Auth)](https://app.notion.com/p/A5-Lockout-Auth-ce46639fc20443728aa60d77c0eab5f1?pvs=21)

[A6 — Modal Step-up Re-auth (Auth)](https://app.notion.com/p/A6-Modal-Step-up-Re-auth-Auth-fa7d5c40604141c0b3ed0fae329a1569?pvs=21)