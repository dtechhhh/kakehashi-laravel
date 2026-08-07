# UI-W6-T8 Review-at-End Report (Wave 6 Guest)

**Reviewer:** Orkestrator (builder + reviewer in-session — operator-approved
deviation, pola Wave 4/5; dicatat di BUILD_LOG)
**Scope:** W6-T1..T7 domain + checkpoint wajib sebelum tag
**Verdict:** **PASS WITH NON-BLOCKING NOTES** (tidak ada Blocker/Major)

## Checklist Reviewer Playbook 09 — hasil

| # | Item | Status | Bukti (test) |
| --- | --- | --- | --- |
| 1 | Token hash-only at rest; raw token tidak di DB/log/audit | PASS | GuestTokenIssuanceTest (leak-scan substring seluruh kolom guest_link/guest_access_log/audit_log) + GuestPiiLeakTest (security log diff) |
| 2 | Token hanya setelah approval; satu token satu kontainer | PASS | GuestTokenIssuanceTest 4/4 |
| 3 | Validasi urut: token → expiry → kontainer Aktif → kode | PASS | GuestGateTest 10/10 |
| 4 | Pesan gagal generik; constant-time compare | PASS | GuestGateTest (GUEST_DENIED seragam semua alasan; `hash_equals`) |
| 5 | Rate limit invalid 10/menit/IP | PASS | GuestRateLimitTest + GuestRateLimitRedisTest |
| 6 | Rate limit valid 60/menit/token (NAT-safe) | PASS | GuestRateLimitTest (token kedua tetap bisa dari IP sama) |
| 7 | Kode tambahan 5 gagal → lock 15 menit | PASS | GuestRateLimitTest (`availableIn` ≈ 900) |
| 8 | G2 pseudonim: NIK `K-YYYY-NNNNN`, tanpa nama/foto/riwayat | PASS | GuestCandidateListTest (payload whitelist + HIDE scan) |
| 9 | G3 whitelist Lampiran C + audit `GUEST_DETAIL_VIEWED` | PASS | GuestCandidateDetailTest (7 test; detail `{token_id, candidate_id, container_id, ip}`, actor NULL) |
| 10 | Kandidat anonymized dikecualikan G2/G3; direct detail ditolak generik | PASS | GuestCandidateListTest + GuestCandidateDetailTest + GuestSurfaceTest (photo) |
| 11 | Object Candidate penuh tidak pernah dikirim; serialization whitelist | PASS | GuestPiiLeakTest (payload JSON tanpa field internal) |
| 12 | Sort/filter allowlist; PII/HIDE bukan parameter | PASS | GuestCandidateListTest + GuestPiiLeakTest (param nama/filter diabaikan) |
| 13 | Foto R2 signed URL TTL 15 mnt, scoped sesi token | PASS | GuestSurfaceTest (expires ≈ 900 dtk; luar scope/anonymized/tanpa foto → 404 generik) |
| 14 | Dokumen hanya shareable via Drive | PASS | GuestCandidateDetailTest (hanya `is_shareable` + `url_file`; non-shareable tidak pernah keluar) |
| 15 | `Cache-Control: no-store` + security headers + JP-only | PASS | GuestSurfaceTest (7 header + `lang="ja"`) |
| 16 | Audit `GUEST_ACCESS` + `guest_access_log` | PASS | GuestGateTest (audit + baris log, tanpa token mentah) |
| 17 | Gagal akses → log keamanan (bukan audit_log) | PASS | GuestGateTest + GuestPiiLeakTest (security log channel, tanpa token) |
| 18 | Route smoke + full suite | PASS | Full suite 719 tests / 718 passed / 1 skipped (env-gated R2 live) / 5674 assertions; pint OK; `git diff --check` bersih; tanpa perubahan `docs/kakehashi/` |

## Temuan & hasil fix

- **N-1 (Minor — DIFIX):** input kode di `submitCode` tidak di-trim sehingga
  spasi tempel gagal sampai lockout. Fix: `trim()` di controller sebelum
  hash-compare. Test ulang GuestSurfaceTest hijau.
- **N-2 (Info):** video Jikoshokai/Keahlian default OFF — aktivasi per link
  butuh flag skema yang tidak ada di DATABASE_SCHEMA; tidak mengarang kolom.
  Ditunda sampai keputusan skema.
- **N-3 (Info):** CSP `img-src https:` longgar sampai keputusan custom domain
  R2/proxy (DEPLOYMENT) — sesuai catatan MODULE_GUEST_ACCESS §5.
- **N-4 (Info):** `requiresCode()` menampilkan form kode untuk link valid
  berkode (UX desain); seluruh jalur gagal tetap view denied seragam.
- **N-5 (Info/environmental):** satu run full-suite mengalami kontensi fork
  (`migrate:fresh`/TRUNCATE bersamaan antar test concurrency yang sudah ada —
  PendingRequest/TotpRecovery/UserRbac/Candidate/GuestLink). Semua hijau saat
  diisolasi (16/16) dan rerun full suite bersih 718+1. Bukan regresi Wave 6.

## Stop-condition scan

- Field HIDE di response level: **tidak ada** (GuestPiiLeakTest).
- Token mentah di DB/log/response: **tidak ada** (leak-scan).
- G2 memuat nama/foto atau sort/filter PII: **tidak ada**.
- Guest keluar scope container: **tidak ada** (list/detail/photo di-scope sesi).
- Authority conflict: **tidak ditemukan**.

## Keputusan

Tidak ada Blocker/Major → **tag `wave-6-guest-complete` boleh dibuat** di
commit review bersih (HEAD), sebelum fase UI.
