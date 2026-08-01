# Kakehashi Build Log

Status: **REPO-FIRST OFFLINE** — Notion belum pernah terhubung pada repository ini.

Notion adalah mirror opsional untuk sinkronisasi berikutnya. Selama mode ini, file ini adalah master status build lokal. Tidak ada secret, credential, token, atau nilai `.env` yang boleh dicatat.

## Aturan

- Satu baris per task Codex.
- Builder dan Reviewer harus berasal dari percakapan terpisah.
- `PASS` task tidak sama dengan `WAVE PASS`; wave hanya lulus setelah verdict final Reviewer tersedia.
- Commit dan tag adalah bukti status; `docs/tmp/` adalah working material dan bukan sumber kanonik.

## Governance

| Tanggal | Task | Branch/Commit | Builder | Reviewer | Verdict | Bukti | Catatan |
|---|---|---|---|---|---|---|---|
| 2026-08-01 | DOC-SYNC-REPO-FIRST | `w3-t9` / `aba2491` | Codex Builder | Reviewer terpisah | PENDING REVIEW | Repo-first policy + manifest update | Notion tidak tersedia; review tetap wajib |
| 2026-08-01 | DOC-SYNC-UI-APPROVED-REFS | `w3-t9` / `4f5adb0` | Codex Builder | Reviewer terpisah | PENDING REVIEW | Design/index sync + UI W0–W3 plan; lint/diff/link checks passed | Raw HTML per layar tetap dikecualikan; review tetap wajib |

## Wave 3 — Candidates

| Tanggal | Task | Branch/Commit | Builder | Reviewer | Verdict | Bukti | Catatan |
|---|---|---|---|---|---|---|---|
| 2026-07-29 | W3-T1 | `w3-t9` / `3bf1609` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Candidate schema commit + test evidence | — |
| 2026-07-29 | W3-T2 | `w3-t9` / `fc1795d` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Draft core commit + test evidence | — |
| 2026-07-30 | W3-T3 | `w3-t9` / `0835b03` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Submit/NIK/similarity commit + test evidence | — |
| 2026-07-30 | W3-T4 | `w3-t9` / `2261f73` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Approval/pending commit + test evidence | — |
| 2026-07-30 | W3-T5 | `w3-t9` / `40ca279` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Revision merge commit + test evidence | — |
| 2026-07-30 | W3-T6 | `w3-t9` / `f7a3c6e` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Availability service commit + concurrency evidence | — |
| 2026-07-30 | W3-T7 | `w3-t9` / `b6d2b13` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Private photo/Drive split commit + test evidence | — |
| 2026-07-31 | W3-T8 | `w3-t9` / `40d145b` | Builder W3 | Reviewer W3 (terpisah) | TASK PASS | Anonymization guard commit + test evidence | — |
| 2026-07-31 | W3-R1 | `w3-t9` / `59450da` | Builder W3-R1 | Reviewer W3 (terpisah) | TASK PASS | Remediation report and tests | — |
| 2026-08-01 | W3-R2 | `w3-t9` / `e00afd6` | Builder W3-R2 | Reviewer W3 (terpisah) | TASK PASS | Remediation report and tests | — |
| 2026-08-01 | W3-R3 | `w3-t9` / `9bbcd56` | Builder W3-R3 | Reviewer W3 (terpisah) | TASK PASS | Remediation report and tests | — |
| 2026-08-01 | W3-R4 | `w3-t9` / `8587750` | Builder W3-R4 | Reviewer W3 (terpisah) | TASK PASS | Formal lifecycle/concurrency tests | — |
| 2026-08-01 | W3-R5 | `w3-t9` / `8587750` | Builder W3-R5 | Reviewer W3 (terpisah) | TASK PASS | Full suite: 300 tests, 299 passed, 1 skipped; concurrency 15/15 | No new commit |
| 2026-08-01 | W3-R6 | `w3-t9` / `8587750` | Builder W3-R6 | Reviewer W3 (terpisah) | DOC PASS | Corrected gate evidence | No new commit |
| 2026-08-01 | W3-GATE | `w3-t9` / `wave-3-candidates-complete` | Builder W3 | Reviewer W3 (terpisah) | WAVE PASS | Gate evidence + operator confirmation of Reviewer verdict | Reviewer session terputus setelah verdict PASS |

### Wave 3 gate

- Code/test baseline: HEAD `8587750`; full suite `300 tests / 299 passed / 3380 assertions / 1 skipped`; concurrency `15/15 / 131 assertions`; lint passed.
- Final Reviewer verdict: **WAVE PASS** on 2026-08-01; operator confirmed the disconnected Reviewer session had already issued the verdict.
- Annotated tag `wave-3-candidates-complete`: created on commit `8587750` with subject `Wave 3 Candidates complete` and pushed to `origin`.
- Open rulings recorded in `docs/kakehashi/authority/DECISIONS_LOG.md`: BR-CON-03 and PendingType.

## Wave 4 — Jobs/Wawancara handoff

Status: **READY TO START** — Wave 3 final review and tag gate are complete.

- CandidateAvailabilityService exists at `app-modules/candidates/src/Public/CandidateAvailabilityService.php`.
- Pending, audit, step-up, and lookup foundations are available from Waves 0–2.
- W4-T3 must use the BR-CON-03 ruling: transactional row lock for bulk pull, revalidation, participation insert, and `markInUse()`.
- W4 may start from the pushed Wave 3 tag; first implementation task is W4-T1.

## UI Wave 0–3 — Builder (branch `ui-w0-w3-build`)

Status: **REPAIR COMPLETE — PENDING REVIEW** — temuan boundary Reviewer (R1–R3) sudah ditutup pada `e21d4c3`; verdict final tetap hanya dari Reviewer terpisah setelah repair diverifikasi ulang.

| Tanggal | Task | Branch/Commit | Builder | Reviewer | Verdict | Bukti | Catatan |
|---|---|---|---|---|---|---|---|
| 2026-08-01 | UI-W0-T1 | `ui-w0-w3-build` / `8f2b5d6` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | Shell + tokens + komponen + i18n + notif read contract; UI 14/14, suite 65/65, pint/build OK | Lokale default id; nav permission-aware |
| 2026-08-01 | UI-W1-T1 | `ui-w0-w3-build` / `ffd5b56` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | A1–A5; UI 21/21, Auth+Approval+Notifications 90/90; middleware exception + test | Tidak ada simulasi sukses auth |
| 2026-08-01 | UI-W1-T2 | `ui-w0-w3-build` / `824a8fd` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | A6 + S4/S5; 20/20 (Admin), 132/132 suite; createUser deferred (gap contract) | Step-up hanya per contract service |
| 2026-08-01 | UI-W2-T1 | `ui-w0-w3-build` / `4d28b24` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | S1; 18/18 (Lookup), 115/115 suite; code immutable, soft-disable | Last-write-wins (tanpa version) |
| 2026-08-01 | UI-W2-T2 | `ui-w0-w3-build` / `103458d` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | S2/S3; 18/18, 178/178 suite; APV_DONE 409 banner + reload | Tanpa pending_request pada kedua flow |
| 2026-08-01 | UI-W3-T1 | `ui-w0-w3-build` / `6de304e` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | K1/K2; 15/15, 222/222 suite; signed URL + reveal audit | URL Drive tidak bocor sebelum reveal |
| 2026-08-01 | UI-W3-T2 | `ui-w0-w3-build` / `d969b11` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | K3; 12/12, 234/234 suite; NIK server-side, similarity soft warn | Foto privat R2; dokumen hanya URL Drive |
| 2026-08-01 | UI-W3-T3 | `ui-w0-w3-build` / `61a87f8` | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | K4/K5; 8/8, 331/331 suite; self-deny + 409 + revision merge | submitRevision tanpa similarity gate |
| 2026-08-01 | UI-W3-HANDOFF | `ui-w0-w3-build` / (commit handoff) | Codex Builder UI | Reviewer terpisah | PENDING REVIEW | Full suite 417/417 (1 skipped env-gated), pint, build, route smoke, secret/preview/W4 scan bersih | BUILD_LOG per task di atas |
| 2026-08-01 | UI-W0-W3-REPAIR-REVIEW-FINDINGS | `ui-w0-w3-build` / `e21d4c3` | Codex Builder UI (repair) | Reviewer terpisah | REPAIR COMPLETE — PENDING REVIEW | R1–R3: query User & Schema dipindah ke service existing; static scan bersih; focused 124/124, full 423 passed 1 skipped; lint/build/route/diff OK | Verdict final tetap dari Reviewer; plan disetujui Reviewer bagian 11 report |

Handoff notes:
- Full suite: 418 tests / 417 passed / 3728 assertions / 1 skipped (R2 live smoke, env-gated `R2_LIVE_SMOKE`).
- `composer lint` passed; `npm run build` passed; `git diff --check` passed.
- Scan: 0 file pada modul Jobs/Placement/Guest; 0 preview control; 0 secret; URL eksternal hanya `drive.google.com`/`docs.google.com` (kontrak dokumen privat).
- Item deferred: S4 user-creation (contract `createUser` belum ada); K6 anonymization UI (Wave 7); Jobs/Placement/Guest (W4+).
