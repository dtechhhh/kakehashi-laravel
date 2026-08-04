<?php

namespace Modules\Jobs\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W4-T1/T6 — interview-container lifecycle and approved close.
 *
 * Participation, expel, and Guest links remain in their own Wave 4 tasks.
 */
final class InterviewContainerService
{
    private const TARGET_TYPE = 'interview_container';

    /** @var list<string> */
    private const FIELDS = [
        'judul',
        'perusahaan_id',
        'posisi_pekerjaan_id',
        'jenis_wawancara',
        'jenis_visa_id',
        'tanggal_wawancara',
        'target_peserta_diterima',
        'deskripsi',
        'syarat',
    ];

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly CandidateAvailabilityService $availability,
        private readonly StepUpService $stepUp,
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
                'jumlah_peserta' => 0,
                'status' => InterviewContainerStatus::DRAFT->value,
                'dibuat_oleh' => $actor->getKey(),
                'disetujui_oleh' => null,
                'version' => 0,
                'created_at' => now(),
                'approved_at' => null,
                'closed_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                actionType: ActionType::IC_CREATED,
                entityType: self::TARGET_TYPE,
                entityId: (int) $id,
                detail: ['status' => InterviewContainerStatus::DRAFT->value, 'version' => 0],
                actorId: $actor->getKey(),
            );

            return $this->findOrFail((int) $id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload  must include `version`
     */
    public function updateDraft(User $actor, int $containerId, array $payload): object
    {
        $this->authorizeExecute($actor);
        $row = $this->findOrFail($containerId);
        $this->assertMaker($row, $actor);
        $this->assertStatus($row, InterviewContainerStatus::DRAFT);
        $expectedVersion = $this->requireVersion($payload);

        if ((int) $row->version !== $expectedVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        $data = $this->validated($payload, creating: false);
        if ($data === []) {
            return $row;
        }

        return DB::transaction(function () use ($containerId, $expectedVersion, $data): object {
            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', InterviewContainerStatus::DRAFT->value)
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
     * Submit a Draft or a changed rejected Draft for Checker approval.
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
            $this->assertStatus($row, InterviewContainerStatus::DRAFT);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->assertReferencesActive($row);
            $snapshot = $this->snapshot($row);
            $lastRejected = PendingRequest::query()
                ->where('type', PendingType::IC_CREATE->value)
                ->where('target_type', self::TARGET_TYPE)
                ->where('target_id', $containerId)
                ->where('status', PendingStatus::REJECTED->value)
                ->latest('id')
                ->first();

            if ($lastRejected !== null && ($lastRejected->payload['snapshot'] ?? null) == $snapshot) {
                $this->fail('container', 'IC_NO_CHANGE');
            }

            $code = $row->kode_kontainer ?? $this->nextCode();
            $request = $this->pending->submit(
                type: PendingType::IC_CREATE,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::IC_SUBMITTED,
                payload: ['snapshot' => $snapshot],
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', InterviewContainerStatus::DRAFT->value)
                ->where('version', $expectedVersion)
                ->update([
                    'kode_kontainer' => $code,
                    'status' => InterviewContainerStatus::PENDING_APPROVAL->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyJobManagers(ActionType::IC_SUBMITTED, [
                'interview_container_id' => $containerId,
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
            $this->assertStatus($row, InterviewContainerStatus::PENDING_APPROVAL);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::IC_APPROVED,
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $row->id)
                ->where('status', InterviewContainerStatus::PENDING_APPROVAL->value)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => InterviewContainerStatus::ACTIVE->value,
                    'disetujui_oleh' => $actor->getKey(),
                    'approved_at' => now(),
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyMaker($row, ActionType::IC_APPROVED, $pendingRequestId);

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
            $this->assertStatus($row, InterviewContainerStatus::PENDING_APPROVAL);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $note,
                auditAction: ActionType::IC_REJECTED,
                auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $row->id)
                ->where('status', InterviewContainerStatus::PENDING_APPROVAL->value)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => InterviewContainerStatus::DRAFT->value,
                    'disetujui_oleh' => null,
                    'approved_at' => null,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->notifyMaker($row, ActionType::IC_REJECTED, $pendingRequestId);

            return $this->findOrFail((int) $row->id);
        });
    }

    /**
     * Request irreversible close. The container remains Active while the
     * pending request is reviewed.
     *
     * @param  array{version?: int|string}  $options
     */
    public function requestClose(
        User $actor,
        int $containerId,
        string $reasonMaker,
        array $options = [],
    ): object {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);
        $reasonMaker = $this->requiredReason($reasonMaker);

        return DB::transaction(function () use ($actor, $containerId, $expectedVersion, $reasonMaker): object {
            $row = $this->findOrFail($containerId);
            $this->assertStatus($row, InterviewContainerStatus::ACTIVE);

            if ((int) $row->version !== $expectedVersion) {
                throw new ConflictHttpException('CONFLICT');
            }

            $request = $this->pending->submit(
                type: PendingType::IC_CLOSE,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::IC_CLOSE_REQUESTED,
                reasonMaker: $reasonMaker,
                payload: [
                    'snapshot' => [
                        'interview_container_id' => $containerId,
                        'status' => InterviewContainerStatus::ACTIVE->value,
                        'version' => $expectedVersion,
                    ],
                ],
                auditDetail: [
                    'status_before' => $row->status,
                    'version' => $expectedVersion,
                ],
            );

            $this->notifyJobManagers(ActionType::IC_CLOSE_REQUESTED, [
                'interview_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return $this->findOrFail($containerId);
        });
    }

    /**
     * Approve close with scoped password+TOTP step-up. Active participation
     * rows remain in their current status; the closed container freezes them.
     *
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     */
    public function approveClose(
        User $actor,
        int $pendingRequestId,
        string|array|null $noteChecker = null,
        array $options = [],
    ): object {
        [$noteChecker, $options] = $this->decisionArguments($noteChecker, $options);
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->closePendingRequest($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$row, $expectedVersion] = $this->closeContext($request, $options);

            $this->stepUp->require(
                StepUpAction::APPROVE_INTERVIEW_CLOSE,
                self::TARGET_TYPE,
                (int) $row->id,
            );

            // The pending decision is serialized first; any close/release
            // failure below rolls the decision and its audit back.
            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::IC_CLOSED,
                note: $noteChecker,
                auditDetail: [
                    'status_before' => $row->status,
                    'status_after' => InterviewContainerStatus::CLOSED->value,
                    'version' => $expectedVersion,
                ],
            );

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $row->id)
                ->where('status', InterviewContainerStatus::ACTIVE->value)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => InterviewContainerStatus::CLOSED->value,
                    'closed_at' => now(),
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            $participations = DB::table('participation')
                ->where('interview_container_id', $row->id)
                ->whereIn('status_wawancara', InterviewParticipationStatus::activeValues())
                ->get(['candidate_id']);

            foreach ($participations as $participation) {
                $candidateVersion = $this->availability->currentVersion((int) $participation->candidate_id);
                $this->availability->assertInUse((int) $participation->candidate_id, $candidateVersion);
                $this->availability->markAvailable((int) $participation->candidate_id, $candidateVersion);
            }

            $this->notifyMaker($row, ActionType::IC_CLOSED, $pendingRequestId);

            return $this->findOrFail((int) $row->id);
        });
    }

    /**
     * Reject close using the immutable request snapshot. A stale/closed
     * command must still be decidable so it cannot strand an active pending.
     *
     * @param  array<string, mixed>  $options  compatibility-only; ignored
     */
    public function rejectClose(
        User $actor,
        int $pendingRequestId,
        string $noteChecker,
        array $options = [],
    ): object {
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker): object {
            $request = $this->closePendingRequest($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$snapshot, $expectedVersion] = $this->closeSnapshot($request);

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $noteChecker,
                auditAction: ActionType::IC_REJECTED,
                auditDetail: [
                    'status_before' => InterviewContainerStatus::ACTIVE->value,
                    'version' => $expectedVersion,
                ],
            );

            $row = DB::table(self::TARGET_TYPE)->where('id', $snapshot['interview_container_id'])->first();
            if ($row !== null) {
                $this->notifyMaker($row, ActionType::IC_REJECTED, $pendingRequestId);

                return $row;
            }

            return $request;
        });
    }

    /**
     * Cancel a Draft directly or cancel its pending approval before Checker
     * decision. Active and terminal containers cannot be cancelled.
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
            if ($row->status === InterviewContainerStatus::PENDING_APPROVAL->value) {
                $pending = PendingRequest::query()
                    ->where('type', PendingType::IC_CREATE->value)
                    ->where('target_type', self::TARGET_TYPE)
                    ->where('target_id', $containerId)
                    ->where('status', PendingStatus::PENDING->value)
                    ->first();

                if ($pending === null) {
                    $this->fail('container', 'IC_PENDING_NOT_FOUND');
                }

                $this->pending->cancelByMaker(
                    requestId: (int) $pending->getKey(),
                    makerId: $actor->getKey(),
                    auditAction: ActionType::IC_CANCELLED,
                    auditDetail: ['status_before' => $row->status, 'version' => $expectedVersion],
                );
            } elseif ($row->status !== InterviewContainerStatus::DRAFT->value) {
                $this->fail('status', 'IC_NOT_CANCELLABLE');
            }

            $affected = DB::table(self::TARGET_TYPE)
                ->where('id', $containerId)
                ->where('status', $row->status)
                ->where('version', $expectedVersion)
                ->update([
                    'status' => InterviewContainerStatus::CANCELLED->value,
                    'version' => $expectedVersion + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ConflictHttpException('CONFLICT');
            }

            if ($pending === null) {
                $this->audit->record(
                    actionType: ActionType::IC_CANCELLED,
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
            throw new NotFoundHttpException('IC_NOT_FOUND');
        }

        return $row;
    }

    private function authorizeExecute(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }

        Gate::forUser($actor)->authorize('jobs.execute');
    }

    private function authorizeReview(User $actor): void
    {
        $this->assertAuthenticatedActor($actor);

        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }

        Gate::forUser($actor)->authorize('jobs.review');
    }

    private function assertAuthenticatedActor(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('JOBS_ACTOR_MISMATCH');
        }
    }

    private function assertMaker(object $row, User $actor): void
    {
        if ((int) $row->dibuat_oleh !== (int) $actor->getKey()) {
            throw new AuthorizationException('JOBS_NOT_MAKER');
        }
    }

    private function assertStatus(object $row, InterviewContainerStatus $expected): void
    {
        if ($row->status !== $expected->value) {
            $this->fail('status', 'IC_INVALID_TRANSITION');
        }
    }

    private function pendingRequest(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== PendingType::IC_CREATE
            || $request->target_type !== self::TARGET_TYPE
            || $request->status !== PendingStatus::PENDING
        ) {
            throw new ConflictHttpException('IC_PENDING_INVALID');
        }

        return $request;
    }

    /** @param array{version?: int|string} $options */
    private function closeContext(PendingRequest $request, array $options): array
    {
        [$snapshot, $snapshotVersion] = $this->closeSnapshot($request);
        $row = $this->findOrFail((int) $request->target_id);

        if ($row->status !== InterviewContainerStatus::ACTIVE->value
            || (int) $row->version !== $snapshotVersion
            || (int) $snapshot['interview_container_id'] !== (int) $row->id
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (array_key_exists('version', $options)
            && $this->requireVersion($options) !== $snapshotVersion
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        return [$row, $snapshotVersion];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function closeSnapshot(PendingRequest $request): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || (int) ($snapshot['interview_container_id'] ?? 0) !== (int) $request->target_id
            || ($snapshot['status'] ?? null) !== InterviewContainerStatus::ACTIVE->value
            || ! array_key_exists('version', $snapshot)
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        $version = $snapshot['version'];
        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            throw new ConflictHttpException('CONFLICT');
        }

        return [$snapshot, (int) $version];
    }

    private function closePendingRequest(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== PendingType::IC_CLOSE
            || $request->target_type !== self::TARGET_TYPE
        ) {
            throw new ConflictHttpException('IC_CLOSE_PENDING_INVALID');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    /**
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function decisionArguments(string|array|null $noteChecker, array $options): array
    {
        if (is_array($noteChecker)) {
            $options = array_merge($noteChecker, $options);
            $noteChecker = $options['note_checker'] ?? null;

            if (! is_string($noteChecker)) {
                $noteChecker = null;
            }
        }

        return [$noteChecker, $options];
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('reason_maker', 'IC_CLOSE_REASON_REQUIRED');
        }

        return $reason;
    }

    private function requiredCheckerNote(?string $note): string
    {
        $note = is_string($note) ? trim($note) : '';

        if ($note === '') {
            $this->fail('note_checker', 'APV_NOTE');
        }

        return $note;
    }

    /** @return array<string, mixed> */
    private function validated(array $payload, bool $creating): array
    {
        $rules = [
            'judul' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'perusahaan_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('perusahaan', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'posisi_pekerjaan_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('posisi_pekerjaan', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'jenis_wawancara' => [$creating ? 'required' : 'sometimes', Rule::in(['OFFLINE', 'ONLINE'])],
            'jenis_visa_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('jenis_visa', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'tanggal_wawancara' => [$creating ? 'required' : 'sometimes', 'date'],
            'target_peserta_diterima' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'deskripsi' => ['sometimes', 'nullable', 'string'],
            'syarat' => ['sometimes', 'nullable', 'string'],
        ];

        $validated = Validator::make($payload, $rules)->validate();

        return array_intersect_key($validated, array_flip(self::FIELDS));
    }

    private function assertReferencesActive(object $row): void
    {
        foreach ([
            'perusahaan_id' => 'perusahaan',
            'posisi_pekerjaan_id' => 'posisi_pekerjaan',
            'jenis_visa_id' => 'jenis_visa',
        ] as $field => $table) {
            if (! DB::table($table)->where('id', $row->{$field})->where('is_active', true)->exists()) {
                $this->fail($field, 'IC_REFERENCE_INACTIVE');
            }
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
            "INSERT INTO container_counter (prefix, year, last_value, updated_at) VALUES ('W', ?, 1, now())
             ON CONFLICT (prefix, year) DO UPDATE SET last_value = container_counter.last_value + 1, updated_at = now()",
            [$year],
        );

        $counter = DB::table('container_counter')
            ->where('prefix', 'W')
            ->where('year', $year)
            ->lockForUpdate()
            ->value('last_value');

        if ((int) $counter > 99999) {
            $this->fail('kode_kontainer', 'IC_CODE_OVERFLOW');
        }

        return sprintf('W-%04d-%05d', $year, (int) $counter);
    }

    private function requireVersion(array $payload): int
    {
        $version = $payload['version'] ?? null;

        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            $this->fail('version', 'IC_VERSION_REQUIRED');
        }

        return (int) $version;
    }

    private function notifyJobManagers(ActionType $action, array $payload): void
    {
        $ids = User::query()
            ->where('status_akun', 'Aktif')
            ->get()
            ->filter(fn (User $user): bool => $user->checkPermissionTo('jobs.review'))
            ->modelKeys();

        if ($ids === []) {
            return;
        }

        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    private function notifyMaker(object $row, ActionType $action, int $pendingRequestId): void
    {
        $ids = [(int) $row->dibuat_oleh];
        $payload = [
            'interview_container_id' => (int) $row->id,
            'pending_request_id' => $pendingRequestId,
        ];

        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
