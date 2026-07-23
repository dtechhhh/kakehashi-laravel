# Kakehashi Build Invariants

> Navigation aid only. PRD and final domain documents remain authoritative.

## Identity and approval

- Email is the only login identifier.
- Maker cannot approve their own request.
- `pending_request` is the Checker decision source.
- One active pending exists per type and target.
- Approve or reject revalidates pending inside the transaction.
- Double approval produces one success and one conflict.
- Business, audit, and in-app notification commit first.
- Email and Redis queue dispatch occur after commit.
- Enqueue failure does not roll back business data.

Sources: PRD, ROLES, MODULE_AUTH, BUSINESS_RULES, API_CONTRACTS.

## Candidate

- Candidate starts as Draft with no NIK and no pending.
- NIK `K-YYYY-NNNNN` is generated on first submit using JST year.
- Similarity uses explicit `similarity() >= 0.4` and is a soft warning.
- Only one active Candidate revision exists.
- Revision merge is atomic and preserves NIK, availability, and operational history.
- Availability changes only through Candidates public service.
- Soft-delete and restore are not exposed.
- Anonymization guards are revalidated inside a transaction.

## Jobs and Placement

- Only one active interview participation exists per Candidate.
- Bulk pull uses row locking and partial unique protection.
- `Terkirim` is not a manual Jobs action.
- Normal Placement starts from `Siap Dikirim + Sedang Dipakai`.
- Normal transfer never creates a `Tersedia` window and uses `assertInUse`.
- Placement batch is all-or-nothing.
- Force-Majeur requires category and reason but no step-up.
- `FM_REJECTED` is canonical.
- Contract end date is start date plus duration months minus one day.
- Placement archive is automatic.

## Guest and files

- G2 uses Candidate NIK and excludes name and photo.
- G3 contains only the final whitelist.
- Anonymized Candidates are excluded from G2 and G3.
- Guest never receives a full Candidate object.
- Photos use private R2 and short signed URLs.
- Documents are private Google Drive URLs.
- Guest pages use `Cache-Control: no-store`.
- Raw Guest tokens are not stored or logged.

## Infrastructure and recovery

- Production remains a stable single VPS with 4 vCPU and 8 GB RAM.
- Redis binds localhost and uses `noeviction`.
- Production uses two Redis queue workers.
- Restore to a temporary database is a hard go-live gate.
- Redis is not backed up as business data.

## Three-environment model

- Local + GitHub remain source of truth.
- Ephemeral daily VPS instances are test and rehearsal only.
- Test VPS uses reviewed commits, synthetic data, test secrets, test R2, and test Drive locations.
- Nothing exists only on an ephemeral VPS.
- Production remains separate and stable.
- VPS rental is not a Wave 0 prerequisite.
- Wave 7 requires at least one production-like rehearsal plus restore gate.
