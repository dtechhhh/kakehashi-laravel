<?php

namespace Shared\Approval;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * API_CONTRACTS §6.0 — sumber keputusan Checker untuk SELURUH approval domain.
 *
 * Domain tidak boleh membuat pola approval sendiri (BR-APV-08). Foundation ini
 * tidak mengarang `action_type`: pemanggil menyuplai ActionType kanonik
 * (PRD Lampiran A / DATABASE_SCHEMA §7) karena tidak setiap tipe pending punya
 * triplet submit/approve/reject di Lampiran A.
 *
 * Tidak ada email/queue di sini — after-commit adalah W1-T7.
 */
class PendingRequestService
{
    public const MAKER_CANCELLED_NOTE = 'IC_CANCELLED_BY_MAKER';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MakerCheckerGate $gate,
    ) {}

    /**
     * Buat tepat satu pending aktif per (type, target_type, target_id).
     *
     * Pemanggil menjalankan ini di dalam transaksi domain bila status submission
     * (`Menunggu*`) harus lahir bersama pending (BR-APV-08); nested transaction
     * memakai savepoint sehingga tetap satu commit.
     *
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $auditDetail
     *
     * @throws ValidationException APV_PAYLOAD (422)
     * @throws ConflictHttpException APV_DUPLICATE (409)
     */
    public function submit(
        PendingType $type,
        string $targetType,
        int $targetId,
        int $requestedBy,
        ActionType $auditAction,
        ?string $reasonMaker = null,
        ?array $payload = null,
        ?array $auditDetail = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PendingRequest {
        if ($type->requiresPayload() && ($payload === null || $payload === [])) {
            $this->fail('payload', 'APV_PAYLOAD');
        }

        return DB::transaction(function () use (
            $type,
            $targetType,
            $targetId,
            $requestedBy,
            $auditAction,
            $reasonMaker,
            $payload,
            $auditDetail,
            $ip,
            $userAgent,
        ): PendingRequest {
            try {
                $request = PendingRequest::query()->forceCreate([
                    'type' => $type,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'requested_by' => $requestedBy,
                    'reason_maker' => $reasonMaker,
                    'payload' => $payload,
                    'status' => PendingStatus::PENDING,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // uq_pending_active: pending aktif lain sudah ada untuk target ini.
                throw new ConflictHttpException('APV_DUPLICATE', $exception);
            }

            $this->recordAudit($auditAction, $request, [
                'requested_by' => $requestedBy,
            ], $auditDetail, $ip, $userAgent);

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>|null  $auditDetail
     */
    public function approve(
        int $requestId,
        int $checkerId,
        ActionType $auditAction,
        ?string $note = null,
        ?array $auditDetail = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PendingRequest {
        return $this->decide(
            $requestId,
            $checkerId,
            PendingStatus::APPROVED,
            $note,
            $auditAction,
            $auditDetail,
            $ip,
            $userAgent,
        );
    }

    /**
     * BR-APV-04 — catatan penolakan wajib.
     *
     * @param  array<string, mixed>|null  $auditDetail
     */
    public function reject(
        int $requestId,
        int $checkerId,
        string $note,
        ActionType $auditAction,
        ?array $auditDetail = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PendingRequest {
        return $this->decide(
            $requestId,
            $checkerId,
            PendingStatus::REJECTED,
            $note,
            $auditAction,
            $auditDetail,
            $ip,
            $userAgent,
        );
    }

    /**
     * Cancel a pending request by its maker before Checker decision.
     *
     * Cancellation is represented by the existing terminal `rejected` status
     * because pending_request has no third decision status. The checker remains
     * null; a reserved note marker distinguishes it from a Checker rejection.
     */
    public function cancelByMaker(
        int $requestId,
        int $makerId,
        ActionType $auditAction,
        ?array $auditDetail = null,
        ?PendingType $type = null,
        ?string $targetType = null,
    ): PendingRequest {
        $type ??= PendingType::IC_CREATE;
        $targetType ??= 'interview_container';

        return DB::transaction(function () use ($requestId, $makerId, $auditAction, $auditDetail, $type, $targetType): PendingRequest {
            $request = PendingRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->requested_by !== $makerId) {
                throw new AccessDeniedHttpException('APV_NOT_MAKER');
            }

            if ($request->type !== $type || $request->target_type !== $targetType) {
                throw new ConflictHttpException('APV_CANCEL_SCOPE');
            }

            if ($request->status !== PendingStatus::PENDING) {
                throw new ConflictHttpException('APV_DONE');
            }

            $decidedAt = now();
            $affected = PendingRequest::query()
                ->whereKey($requestId)
                ->where('status', PendingStatus::PENDING->value)
                ->update([
                    'status' => PendingStatus::REJECTED->value,
                    'checker_id' => null,
                    'note_checker' => self::MAKER_CANCELLED_NOTE,
                    'decided_at' => $decidedAt,
                    'updated_at' => $decidedAt,
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('APV_DONE');
            }

            $request->refresh();
            $this->recordAudit($auditAction, $request, [
                'cancelled_by' => $makerId,
            ], $auditDetail, null, null);

            return $request;
        });
    }

    /**
     * Gate W1-T6: MakerCheckerGate dipanggil DI DALAM transaksi, SEBELUM
     * revalidasi status (BR-APV-01/02). Urutan ini disengaja — aktor yang tidak
     * berwenang ditolak 403 dan tidak pernah mengetahui apakah request sudah
     * diputus aktor lain.
     *
     * Gate W1-T5: status pending direvalidasi DI DALAM transaksi (BR-APV-07).
     *
     * FOR UPDATE menyerialkan dua Checker; conditional UPDATE
     * `WHERE status = 'pending'` adalah jaring kedua sehingga aksi kedua selalu
     * 409, bukan menimpa keputusan pertama.
     *
     * @param  array<string, mixed>|null  $auditDetail
     */
    private function decide(
        int $requestId,
        int $checkerId,
        PendingStatus $decision,
        ?string $note,
        ActionType $auditAction,
        ?array $auditDetail,
        ?string $ip,
        ?string $userAgent,
    ): PendingRequest {
        return DB::transaction(function () use (
            $requestId,
            $checkerId,
            $decision,
            $note,
            $auditAction,
            $auditDetail,
            $ip,
            $userAgent,
        ): PendingRequest {
            $request = PendingRequest::query()->lockForUpdate()->findOrFail($requestId);

            // BR-APV-01/02 — server-side; tombol tersembunyi bukan authorization.
            $this->gate->assertCanDecide($request, $checkerId);

            if ($request->status !== PendingStatus::PENDING) {
                throw new ConflictHttpException('APV_DONE');
            }

            $note = $note === null ? null : trim($note);

            if ($decision === PendingStatus::REJECTED && ($note === null || $note === '')) {
                $this->fail('note_checker', 'APV_NOTE');
            }

            $decidedAt = now();

            $affected = PendingRequest::query()
                ->whereKey($requestId)
                ->where('status', PendingStatus::PENDING->value)
                ->update([
                    'status' => $decision->value,
                    'checker_id' => $checkerId,
                    'note_checker' => $note,
                    'decided_at' => $decidedAt,
                    'updated_at' => $decidedAt,
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('APV_DONE');
            }

            $request->refresh();

            $this->recordAudit($auditAction, $request, [
                'checker_id' => $checkerId,
                'decision' => $decision->value,
            ], $auditDetail, $ip, $userAgent);

            return $request;
        });
    }

    /**
     * Audit ditulis di dalam transaksi yang sama: gagal audit → rollback keputusan.
     * `reason_maker`/`note_checker` sengaja TIDAK disalin ke detail (PII-minimized).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>|null  $auditDetail
     */
    private function recordAudit(
        ActionType $auditAction,
        PendingRequest $request,
        array $base,
        ?array $auditDetail,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $detail = array_merge($auditDetail ?? [], [
            'pending_request_id' => $request->getKey(),
            'pending_type' => $request->type->value,
            'target_type' => $request->target_type,
            'target_id' => $request->target_id,
        ], $base);

        $this->audit->record(
            actionType: $auditAction,
            entityType: $request->target_type,
            entityId: $request->target_id,
            detail: $detail,
            actorId: $base['checker_id'] ?? $base['requested_by'] ?? $base['cancelled_by'] ?? null,
            ip: $ip,
            userAgent: $userAgent,
        );
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
