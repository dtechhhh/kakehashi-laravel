<?php

namespace Modules\Placement\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Placement\Enums\PlacementContainerStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W5-T1 — placement-container lifecycle: Draft → Menunggu Approval → Aktif,
 * P-YYYY-NNNNN on first submit, immutable perusahaan, pre-Aktif cancel only.
 */
final class PlacementContainerService
{
    private const TARGET_TYPE = 'placement_container';

    /** @var list<string> */
    private const FIELDS = ['nama', 'perusahaan_id'];

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(User $actor, array $payload): object
    {
        $this->authorizeExecute($actor);
        $data = $this->validated($payload, creating: true);

        return DB::transaction(function () use ($actor, $data): object {
            $id = DB::table(self::TARGET_TYPE)->insertGetId([
                ...$data,
                'kode_kontainer' => null,
                'status' => PlacementContainerStatus::DRAFT->value,
                'dibuat_oleh' => $actor->getKey(),
                'disetujui_oleh' => null,
                'version' => 0,
                'created_at' => now(),
                'approved_at' => null,
                'archived_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                actionType: ActionType::PC_CREATED,
                entityType: self::TARGET_TYPE,
                entityId: (int) $id,
                detail: ['status' => PlacementContainerStatus::DRAFT->value, 'version' => 0],
                actorId: $actor->getKey(),
            );

            return $this->findOrFail((int) $id);
        });
    }

    /**
     * Edit Draft only. perusahaan_id is immutable after creation; a payload
     * that changes it is rejected instead of silently dropped.
     *
     * @param  array<string, mixed>  $payload  must include `version`
     */
    public function updateDraft(User $actor, int $containerId, array $payload): object
    {
        $this->authorizeExecute($actor);
        $row = $this->findOrFail($containerId);
        $this->assertMaker($row, $actor);
        $this->assertStatus($row, PlacementContainerStatus::DRAFT);
        $expectedVersion = $this->requireVersion($payload);

        if ((int) $row->version !== $expectedVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (array_key_exists('perusahaan_id', $payload)
            && (int) $payload['perusahaan_id'] !== (int) $row->perusahaan_id
        ) {
            $this->fail('perusahaan_id', 'PC_COMPANY_IMMUTABLE');
        }

        $data = $this->validated($payload, creating: false);
        if ($data === []) {
            return $row;
        }

        return DB::transaction(function () use ($containerId, $expectedVersion, $data): object {
            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', PlacementContainerStatus::DRAFT->value)
                ->where('version', $expectedVersion)
                ->update([
                    ...$data,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            return $this->findOrFail($containerId);
        });
    }

    /**
     * Submit a Draft (or a changed rejected Draft) for Checker approval.
     *
     * @param  array{version?: int|string}  $options
     */
    public function submit(User $actor, int $containerId, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $containerId, $expectedVersion): object {
            $row = $this->findOrFail($containerId);
            $this->assertMaker($row, $actor);
            $this->assertStatus($row, PlacementContainerStatus::DRAFT);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->assertReferencesActive($row);
            $snapshot = $this->snapshot($row);
            $lastRejected = PendingRequest::query()
                ->where('type', PendingType::PC_CREATE->value)
                ->where('target_type', self::TARGET_TYPE)
                ->where('target_id', $containerId)
                ->where('status', PendingStatus::REJECTED->value)
                ->latest('id')
                ->first();

            if ($lastRejected !== null && ($lastRejected->payload['snapshot'] ?? null) == $snapshot) {
                $this->fail('container', 'PC_NO_CHANGE');
            }

            $code = $row->kode_kontainer ?? $this->nextCode();
            $request = $this->pending->submit(
                type: PendingType::PC_CREATE,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::PC_SUBMITTED,
                payload: ['snapshot' => $snapshot],
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', PlacementContainerStatus::DRAFT->value)
                ->where('version', $expectedVersion)
                ->update([
                    'kode_kontainer' => $code,
                    'status' => PlacementContainerStatus::PENDING_APPROVAL->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyReviewers(ActionType::PC_SUBMITTED, [
                'placement_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return $this->findOrFail($containerId);
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function approve(User $actor, int $pendingRequestId, array $options = []): object
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $expectedVersion): object {
            $request = $this->pendingRequest($pendingRequestId);
            $row = $this->findOrFail((int) $request->target_id);
            $this->assertStatus($row, PlacementContainerStatus::PENDING_APPROVAL);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::PC_APPROVED,
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $row->id)
                ->where('status', PlacementContainerStatus::PENDING_APPROVAL->value)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => PlacementContainerStatus::ACTIVE->value,
                    'disetujui_oleh' => $actor->getKey(),
                    'approved_at' => now(),
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyMaker($row, ActionType::PC_APPROVED, $pendingRequestId);

            return $this->findOrFail((int) $row->id);
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function reject(User $actor, int $pendingRequestId, string $note, array $options = []): object
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $note, $expectedVersion): object {
            $request = $this->pendingRequest($pendingRequestId);
            $row = $this->findOrFail((int) $request->target_id);
            $this->assertStatus($row, PlacementContainerStatus::PENDING_APPROVAL);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $note,
                auditAction: ActionType::PC_REJECTED,
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $row->id)
                ->where('status', PlacementContainerStatus::PENDING_APPROVAL->value)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => PlacementContainerStatus::DRAFT->value,
                    'disetujui_oleh' => null,
                    'approved_at' => null,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyMaker($row, ActionType::PC_REJECTED, $pendingRequestId);

            return $this->findOrFail((int) $row->id);
        });
    }

    /**
     * Cancel a Draft directly or its pending approval before Checker decision.
     * Active and terminal containers cannot be cancelled (pre-Aktif only).
     *
     * @param  array{version?: int|string}  $options
     */
    public function cancel(User $actor, int $containerId, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $containerId, $expectedVersion): object {
            $row = $this->findOrFail($containerId);
            $this->assertMaker($row, $actor);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $pending = null;
            if ($row->status === PlacementContainerStatus::PENDING_APPROVAL->value) {
                $pending = PendingRequest::query()
                    ->where('type', PendingType::PC_CREATE->value)
                    ->where('target_type', self::TARGET_TYPE)
                    ->where('target_id', $containerId)
                    ->where('status', PendingStatus::PENDING->value)
                    ->first();

                if ($pending === null) {
                    $this->fail('container', 'PC_PENDING_NOT_FOUND');
                }

                $this->pending->cancelByMaker(
                    requestId: (int) $pending->getKey(),
                    makerId: $actor->getKey(),
                    auditAction: ActionType::PC_CANCELLED,
                    auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
                    type: PendingType::PC_CREATE,
                    targetType: self::TARGET_TYPE,
                );
            } elseif ($row->status !== PlacementContainerStatus::DRAFT->value) {
                $this->fail('status', 'PC_NOT_CANCELLABLE');
            }

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', $row->status)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => PlacementContainerStatus::CANCELLED->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            if ($pending === null) {
                $this->audit->record(
                    actionType: ActionType::PC_CANCELLED,
                    entityType: self::TARGET_TYPE,
                    entityId: $containerId,
                    detail: ['status_before' => $row->status, 'version' => $expectedVersion],
                    actorId: $actor->getKey(),
                );
            }

            return $this->findOrFail($containerId);
        });
    }

    public function findOrFail(int $containerId): object
    {
        $row = DB::table(self::TARGET_TYPE)->where('id', $containerId)->first();

        if ($row === null) {
            throw new NotFoundHttpException('PC_NOT_FOUND');
        }

        return $row;
    }

    private function authorizeExecute(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('PLACEMENT_INACTIVE');
        }

        Gate::forUser($actor)->authorize('placement.execute');
    }

    private function authorizeReview(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('PLACEMENT_INACTIVE');
        }

        Gate::forUser($actor)->authorize('placement.review');
    }

    private function assertAuthenticatedActor(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('PLACEMENT_ACTOR_MISMATCH');
        }
    }

    private function assertMaker(object $row, User $actor): void
    {
        if ((int) $row->dibuat_oleh !== (int) $actor->getKey()) {
            throw new AuthorizationException('PLACEMENT_NOT_MAKER');
        }
    }

    private function assertStatus(object $row, PlacementContainerStatus $expected): void
    {
        if ($row->status !== $expected->value) {
            $this->fail('status', 'PC_INVALID_TRANSITION');
        }
    }

    private function pendingRequest(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== PendingType::PC_CREATE
            || $request->target_type !== self::TARGET_TYPE
            || $request->status !== PendingStatus::PENDING
        ) {
            throw new ConflictHttpException('PC_PENDING_INVALID');
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function validated(array $payload, bool $creating): array
    {
        $rules = [
            'nama' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'perusahaan_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('perusahaan', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ];

        $validated = Validator::make($payload, $rules)->validate();

        return array_intersect_key($validated, array_flip(self::FIELDS));
    }

    private function assertReferencesActive(object $row): void
    {
        if (! DB::table('perusahaan')->where('id', $row->perusahaan_id)->where('is_active', true)->exists()) {
            $this->fail('perusahaan_id', 'PC_REFERENCE_INACTIVE');
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(object $row): array
    {
        return collect(self::FIELDS)->mapWithKeys(
            fn (string $field): array => [$field => $row->{$field}],
        )->all();
    }

    private function nextCode(): string
    {
        $year = (int) now('Asia/Tokyo')->format('Y');

        DB::statement(
            "INSERT INTO container_counter (prefix, year, last_value, updated_at) VALUES ('P', ?, 1, now())
             ON CONFLICT (prefix, year) DO UPDATE SET last_value = container_counter.last_value + 1, updated_at = now()",
            [$year],
        );

        $counter = DB::table('container_counter')
            ->where('prefix', 'P')
            ->where('year', $year)
            ->lockForUpdate()
            ->value('last_value');

        if ((int) $counter > 99999) {
            $this->fail('kode_kontainer', 'PC_CODE_OVERFLOW');
        }

        return sprintf('P-%04d-%05d', $year, (int) $counter);
    }

    private function requireVersion(array $payload): int
    {
        $version = $payload['version'] ?? null;

        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            $this->fail('version', 'PC_VERSION_REQUIRED');
        }

        return (int) $version;
    }

    /** @param array<string, mixed> $payload */
    private function notifyReviewers(ActionType $action, array $payload): void
    {
        $ids = User::query()
            ->where('status_akun', 'Aktif')
            ->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo('placement.review'))
            ->modelKeys();

        if ($ids === []) {
            return;
        }

        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function notifyMaker(object $row, ActionType $action, int $pendingRequestId): void
    {
        $this->notifications->notifyInApp([(int) $row->dibuat_oleh], $action->value, [
            'placement_container_id' => (int) $row->id,
            'pending_request_id' => $pendingRequestId,
        ]);
        $this->notifications->queueEmailAfterCommit([(int) $row->dibuat_oleh], $action->value, [
            'placement_container_id' => (int) $row->id,
            'pending_request_id' => $pendingRequestId,
        ]);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
