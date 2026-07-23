---
title: "15 — Backlog Pasca-MVP Awal"
status: "FINAL v1"
source_notion_title: "15 — Backlog Pasca-MVP Awal"
exported_at: "2026-07-16"
authority_rank: "playbook"
canonical_source: "Notion"
codex_edit_policy: "read-only"
template_export: "false"
---

> [!IMPORTANT]
> Controlled read-only snapshot from Notion. Use it as an operator and Codex workflow guide; product/domain authority remains PRD v0.3.14 and Batch A/B.

# 15 — Backlog Pasca-MVP Awal

> [!NOTE]
> Tempat menyimpan pekerjaan yang sengaja tidak mengganggu critical path MVP.
>
## Aturan Backlog
- Backlog bukan izin memasukkan fitur baru ke wave aktif.
- Item hanya dikerjakan setelah coding stabil atau pasca-MVP awal.
- Setiap item harus punya alasan, dampak, dan syarat pembukaan.
## Backlog Awal — Hygiene Dokumentasi
- Label versi historis pada callout lama.
- Penanda asumsi lama yang sudah dikunci PRD.
- Deskripsi UI/infra lama yang sudah disupersede.
- Ketidakteraturan format judul/tautan dan hitungan inventaris.
- Sinkronisasi wording Approved HTML bila diperlukan.
## Otomasi VPS Ephemeral — Ditunda
- Script bootstrap VPS test setelah SOP manual terbukti stabil.
- Otomasi DNS/subdomain test jika update manual terbukti berulang dan mengganggu.
- Tool teardown/revoke credential otomatis setelah prosedur manual dan Build Log konsisten.
- Tunnel hanya bila kebutuhan nyata tidak dapat dipenuhi IP terbatas atau subdomain test.
## Kandidat Pasca-MVP
- Penyempurnaan seed lookup setelah validasi Kumiai/legal.
- Monitoring/observability lanjutan.
- Partisi audit log bila volume nyata membutuhkan.
- Otomasi retensi PII lebih matang.
- Infrastruktur lebih tinggi hanya jika metrik production membuktikan kebutuhan.
## Larangan
- Jangan mengerjakan backlog selama Pass 1–4 tanpa keputusan eksplisit.
- Jangan memakai backlog untuk membuka microservice, HA, WebSocket, atau fitur out-of-scope.
## Status
**FINAL v1 — backlog terpisah dan tidak memblokir build MVP.**
## Gate Membuka Item Backlog
Item hanya boleh dibuka bila:
- critical path MVP sudah stabil;
- tidak ada FAIL/BLOCKED pada wave aktif;
- dampak terhadap PRD dan production sudah ditinjau;
- operator memberi persetujuan eksplisit;
- task baru dicatat di master Build Log.
