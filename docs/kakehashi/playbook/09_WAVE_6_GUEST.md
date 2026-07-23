---
title: "09 — Wave 6: Guest Access"
status: "FINAL v1"
source_notion_title: "09 — Wave 6: Guest Access"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 09 — Wave 6: Guest Access

> [!NOTE]
> **Wave 6 — Guest Access.** Bangun akses perusahaan Jepang yang bertoken, JP-only, read-only, dan hanya mengeluarkan whitelist G2/G3.
>
## Apa Artinya untuk Operator
Pihak eksternal dapat melihat kandidat untuk satu kontainer Wawancara tanpa memperoleh akun internal, data tersembunyi, atau akses ke kandidat/kontainer lain.
## Prasyarat
- [ ] Wave 5 lulus dan kontainer Wawancara/participation tersedia.
- [ ] Candidate read-model, audit, Redis rate limit, dan R2 tersedia.
- [ ] Kebijakan permission Google Drive dipersiapkan sebelum sertifikat shareable dipakai.
## Dokumen Wajib
- PRD §4.3, §6.3, §7.7, §9.8, Lampiran C
- MODULE_GUEST_ACCESS
- API_CONTRACTS GuestCandidateReadModel
- DATA_RETENTION_AND_PRIVACY
- SECURITY_CHECKLIST
- DATABASE_SCHEMA guest_link/access log
## Lingkup Boleh
- Token acak panjang, hash-only at rest, validasi berurutan, kode tambahan opsional.
- Rate limit token invalid, token valid, dan kode tambahan.
- G2 pseudonim dan G3 whitelist.
- Foto R2 signed URL scoped session token.
- Sertifikat shareable Drive, audit access/detail, security headers, no-store.
## Lingkup Dilarang
- User account/RBAC untuk Tamu.
- Upload/feedback/comment Tamu.
- Mengirim object Candidate penuh ke browser.
- Filter/sort nama, foto, perusahaan, lembaga, atau field HIDE.
- Token mentah di DB/log.
- Kandidat anonymized di G2/G3.
## Urutan Task
<table fit-page-width="true" header-row="true">
<tr>
<td>Task</td>
<td>Hasil</td>
<td>Gate</td>
</tr>
<tr>
<td>W6-T1 Token/link</td>
<td>Request/approval/token hash</td>
<td>Token hanya setelah approval</td>
</tr>
<tr>
<td>W6-T2 Gate/code</td>
<td>Validasi berurutan & lockout</td>
<td>Pesan generik</td>
</tr>
<tr>
<td>W6-T3 Rate limit</td>
<td>Dua lapis + kode tambahan</td>
<td>Redis rate limit</td>
</tr>
<tr>
<td>W6-T4 G2</td>
<td>List pseudonim</td>
<td>NIK, tanpa nama/foto</td>
</tr>
<tr>
<td>W6-T5 G3</td>
<td>Detail whitelist</td>
<td>Audit GUEST_DETAIL_VIEWED</td>
</tr>
<tr>
<td>W6-T6 Aset/headers</td>
<td>R2 photo, Drive shareable, no-store</td>
<td>Scope token dan CSP</td>
</tr>
<tr>
<td>W6-T7 PII review</td>
<td>Response leak suite</td>
<td>PASS sebelum Wave 7</td>
</tr>
</table>
## Prompt Builder — Token, Gate, dan Rate Limit
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W6-T1/W6-T2/W6-T3].

Authority: PRD Lampiran C; MODULE_GUEST_ACCESS; DATABASE_SCHEMA; API_CONTRACTS; SECURITY_CHECKLIST.

Wajib:
- token acak panjang, hanya token_hash disimpan;
- satu token untuk satu interview_container;
- validasi urut: token ada → belum kadaluarsa → kontainer Aktif → kode tambahan bila ada;
- pesan gagal generik dan constant-time compare;
- invalid token 10/menit/IP;
- token valid 60/menit/token;
- kode tambahan 5 gagal → lock 15 menit;
- token mentah tidak masuk log;
- audit hanya akses sukses, gagal ke security log non-audit.

Tambahkan test token invalid/expired, kontainer tertutup, kode gagal, rate limit, dan log token mentah.
```
## Prompt Builder — G2/G3 dan PII
```plain text
Anda adalah Builder Agent Kakehashi. Kerjakan [W6-T4/W6-T5/W6-T6].

Wajib:
- G2 identifier = Nomor Induk K-YYYY-NNNNN; tanpa nama/foto/riwayat;
- G3 hanya field PRD Lampiran C; audit GUEST_DETAIL_VIEWED;
- anonymized Candidate tidak dikembalikan dan direct detail ditolak generik;
- object Candidate penuh tidak pernah dikirim;
- nama/foto/perusahaan/lembaga bukan parameter sort/filter;
- foto R2 TTL 15 menit dan scoped token valid;
- sertifikat hanya jika shareable melalui Drive;
- Cache-Control no-store, JP-only, headers guest.

Tambahkan response-level PII leakage tests, scope token tests, signed URL tests, dan audit tests.
```
## Prompt Reviewer — Guest PII
```plain text
Anda adalah Reviewer Agent terpisah. Jangan mengubah kode.

Periksa token hash, validasi urut, rate limits, generic failures, G2/G3 whitelist, anonymized exclusion, serialization, sort/filter allowlist, signed photo scope, Drive shareable rule, headers/no-store, dan audit.

Tolak jika response memuat field HIDE walau UI tidak menampilkannya, token mentah tercatat, atau Guest dapat keluar dari scope container.

Berikan severity dan verdict.
```
## Definition of Done
- [ ] Token mentah tidak tersimpan/log.
- [ ] Link valid hanya untuk satu kontainer aktif.
- [ ] G2 memakai NIK tanpa nama/foto.
- [ ] G3 hanya whitelist dan mencatat `GUEST_DETAIL_VIEWED`.
- [ ] Kandidat anonymized tidak ada pada list/detail.
- [ ] Rate limit dan lockout kode lulus test.
- [ ] Foto scoped/TTL dan dokumen shareable sesuai aturan.
- [ ] No-store dan security headers lulus.
- [ ] PII leakage suite lulus.
- [ ] Reviewer PASS; snapshot `wave-6-guest-complete` tercatat.
## Stop Condition
- Response mengandung field HIDE.
- Token mentah tampil di log.
- G2 memuat nama/foto.
- Tamu bisa sort/filter PII atau mengakses kontainer lain.
## Bukti Sukses Minimum
1. Token invalid/expired memberi respons generik.
2. G2 tidak memuat nama/foto; G3 tercatat di audit.
3. Kandidat anonymized hilang dari G2/G3 dan detail langsung ditolak.
---
**Status:** FINAL v1 — panduan operasional Wave 6 siap digunakan.
