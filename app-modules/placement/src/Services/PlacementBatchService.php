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
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Jobs\Public\InterviewPlacementTransferService;
use Modules\Placement\Enums\PlacementContainerStatus;
use Modules\Placement\Enums\PlacementParticipantStatus;
use Modules\Placement\Support\ContractDates;
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
 * W5-T3/T4 — batch normal Placement: submit payload snapshot (max 50, source
 * untouched) and all-or-nothing approval with ownership transfer.
 */
final class PlacementBatchService
{
    private const TARGET_TYPE = 'placement_container';

    private const MAX_BATCH = 50;

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly CandidateAvailabilityService $availability,
        private readonly InterviewPlacementTransferService $transfer,
    ) {}

    /**
     * Create the PLACEMENT_BATCH pending with a per-candidate snapshot. The
     * source participation and Candidate availability are read-only here;
     * full revalidation happens inside the approve transaction.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{version?: int|string}  $options
     */
    public function submitBatch(User $actor, int $containerId, array $rows, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);
        $entries = $this->entries($rows);

        return DB::transaction(function () use ($actor, $containerId, $expectedVersion, $entries): object {
            $container = $this->activeContainer($containerId, $expectedVersion);

            $snapshot = [];
            foreach ($entries as $entry) {
                $source = $this->transfer->assertReadyForPlacement(
                    (int) $entry['source_participation_id'],
                    (int) $entry['candidate_id'],
                );
                $candidateVersion = $this->availability->currentVersion((int) $entry['candidate_id']);
                $this->availability->assertInUse((int) $entry['candidate_id'], $candidateVersion);
                $this->assertNoWorkingPlacement((int) $entry['candidate_id']);

                $snapshot[] = [
                    'candidate_id' => (int) $entry['candidate_id'],
                    'source_participation_id' => (int) $entry['source_participation_id'],
                    'source_version' => (int) $source->version,
                    'source_container_id' => (int) $source->interview_container_id,
                    'candidate_version' => $candidateVersion,
                    'jenis_visa_id' => (int) $entry['jenis_visa_id'],
                    'tanggal_mulai_kerja' => $entry['tanggal_mulai_kerja'],
                    'durasi_kontrak_bulan' => (int) $entry['durasi_kontrak_bulan'],
                    'tanggal_berakhir_kontrak' => ContractDates::endDate(
                        $entry['tanggal_mulai_kerja'],
                        (int) $entry['durasi_kontrak_bulan'],
                        $entry['tanggal_berakhir_kontrak'] ?? null,
                    ),
                ];
            }

            $request = $this->pending->submit(
                type: PendingType::PLACEMENT_BATCH,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: null, // no canonical submit event for batch; pending row + BATCH_SENT are the trail
                payload: [
                    'snapshot' => $snapshot,
                    'container_version' => $expectedVersion,
                ],
                auditDetail: ['candidate_count' => count($snapshot)],
            );

            $this->notifyReviewers(ActionType::BATCH_SENT, [
                'placement_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return (object) [
                'placement_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
                'candidate_count' => count($snapshot),
                'snapshot' => $snapshot,
                'container' => $container,
            ];
        });
    }

    /**
     * All-or-nothing approval: lock the container and every source row, then
     * revalidate pending/source/candidate before inserting `Bekerja` rows.
     * Availability stays `Sedang Dipakai` — assertInUse, never markInUse.
     *
     * @param  array{version?: int|string}  $options
     */
    public function approveBatch(User $actor, int $pendingRequestId, array $options = []): array
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $expectedVersion): array {
            $request = $this->pendingRequest($pendingRequestId);
            $container = $this->activeContainer((int) $request->target_id, $expectedVersion);
            $snapshot = $this->batchSnapshot($request, $expectedVersion);

            $participants = [];
            foreach ($snapshot as $row) {
                // Lock source participation first (sorted by source id at submit
                // time) so two containers cannot pull the same candidate.
                $source = $this->transfer->assertReadyForPlacement(
                    (int) $row['source_participation_id'],
                    (int) $row['candidate_id'],
                    lock: true,
                );

                if ((int) $source->version !== (int) $row['source_version']) {
                    throw new ConflictHttpException('CONFLICT');
                }

                $candidateVersion = $this->availability->currentVersion((int) $row['candidate_id']);
                $this->availability->assertInUse((int) $row['candidate_id'], $candidateVersion);
                $this->assertNoWorkingPlacement((int) $row['candidate_id']);

                $participantId = DB::table('placement_participants')->insertGetId([
                    'placement_container_id' => $container->id,
                    'candidate_id' => (int) $row['candidate_id'],
                    'source_participation_id' => (int) $row['source_participation_id'],
                    'kategori_force_majeur_id' => null,
                    'alasan_force_majeur' => null,
                    'jenis_visa_id' => (int) $row['jenis_visa_id'],
                    'tanggal_mulai_kerja' => $row['tanggal_mulai_kerja'],
                    'durasi_kontrak_bulan' => (int) $row['durasi_kontrak_bulan'],
                    'tanggal_berakhir_kontrak' => $row['tanggal_berakhir_kontrak'],
                    'status_penempatan' => PlacementParticipantStatus::WORKING->value,
                    'tanggal_status_final' => null,
                    'catatan_alasan' => null,
                    'disetujui_oleh' => $actor->getKey(),
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->transfer->markSentForPlacement(
                    (int) $row['source_participation_id'],
                    (int) $row['candidate_id'],
                    (int) $row['source_version'],
                );

                $participants[] = DB::table('placement_participants')->where('id', $participantId)->first();
            }

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::BATCH_SENT,
                auditDetail: [
                    'placement_container_id' => (int) $container->id,
                    'candidate_count' => count($snapshot),
                    'candidates' => array_map(
                        static fn (array $row): int => (int) $row['candidate_id'],
                        $snapshot,
                    ),
                ],
            );

            $this->notifyMaker((int) $request->requested_by, ActionType::BATCH_SENT, [
                'placement_container_id' => (int) $container->id,
                'pending_request_id' => $pendingRequestId,
                'candidate_count' => count($snapshot),
            ]);

            return $participants;
        });
    }

    /**
     * PRD §6.4 Sub-flow 2 — the whole batch is either approved or rejected.
     * Rejection leaves source and availability untouched.
     *
     * @param  array{version?: int|string}  $options
     */
    public function rejectBatch(User $actor, int $pendingRequestId, string $note, array $options = []): object
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $note, $expectedVersion): object {
            $request = $this->pendingRequest($pendingRequestId);
            $container = $this->activeContainer((int) $request->target_id, $expectedVersion);
            $snapshot = $this->batchSnapshot($request, $expectedVersion);

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $note,
                auditAction: ActionType::BATCH_REJECTED,
                auditDetail: [
                    'placement_container_id' => (int) $container->id,
                    'candidate_count' => count($snapshot),
                ],
            );

            $this->notifyMaker((int) $request->requested_by, ActionType::BATCH_REJECTED, [
                'placement_container_id' => (int) $container->id,
                'pending_request_id' => $pendingRequestId,
            ]);

            return $request->refresh();
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    private function activeContainer(int $containerId, int $expectedVersion): object
    {
        $container = DB::table('placement_container')
            ->where('id', $containerId)
            ->lockForUpdate()
            ->first();

        if ($container === null) {
            throw new NotFoundHttpException('PC_NOT_FOUND');
        }

        if ($container->status !== PlacementContainerStatus::ACTIVE->value) {
            $this->fail('container', 'PC_NOT_ACTIVE');
        }

        if ((int) $container->version !== $expectedVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        return $container;
    }

    private function pendingRequest(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);

        if ($request === null
            || $request->type !== PendingType::PLACEMENT_BATCH
            || $request->target_type !== self::TARGET_TYPE
        ) {
            throw new ConflictHttpException('PC_BATCH_PENDING_INVALID');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batchSnapshot(PendingRequest $request, int $expectedVersion): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || $snapshot === []
            || count($snapshot) > self::MAX_BATCH
            || (int) ($request->payload['container_version'] ?? -1) !== $expectedVersion
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        foreach ($snapshot as $row) {
            foreach ([
                'candidate_id',
                'source_participation_id',
                'source_version',
                'source_container_id',
                'candidate_version',
                'jenis_visa_id',
                'tanggal_mulai_kerja',
                'durasi_kontrak_bulan',
                'tanggal_berakhir_kontrak',
            ] as $field) {
                if (! array_key_exists($field, $row)) {
                    throw new ConflictHttpException('CONFLICT');
                }
            }
        }

        usort($snapshot, static fn (array $a, array $b): int => (int) $a['source_participation_id'] <=> (int) $b['source_participation_id']);

        return $snapshot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function entries(array $rows): array
    {
        if ($rows === []) {
            $this->fail('rows', 'PC_BATCH_EMPTY');
        }

        if (count($rows) > self::MAX_BATCH) {
            $this->fail('rows', 'PC_BATCH_TOO_LARGE');
        }

        $entries = [];
        $candidates = [];
        $sources = [];

        foreach ($rows as $index => $row) {
            $validated = Validator::make((array) $row, [
                'candidate_id' => ['required', 'integer', 'min:1'],
                'source_participation_id' => ['required', 'integer', 'min:1'],
                'jenis_visa_id' => ['required', 'integer', Rule::exists('jenis_visa', 'id')->where(fn ($q) => $q->where('is_active', true))],
                'tanggal_mulai_kerja' => ['required', 'date'],
                'durasi_kontrak_bulan' => ['required', 'integer', 'min:1'],
                'tanggal_berakhir_kontrak' => ['nullable', 'date'],
            ])->validate();

            $candidateId = (int) $validated['candidate_id'];
            $sourceId = (int) $validated['source_participation_id'];

            if (isset($candidates[$candidateId])) {
                $this->fail('candidate_id', 'PC_BATCH_DUPLICATE_CANDIDATE');
            }
            if (isset($sources[$sourceId])) {
                $this->fail('source_participation_id', 'PC_BATCH_DUPLICATE_SOURCE');
            }

            $candidates[$candidateId] = true;
            $sources[$sourceId] = true;
            $entries[] = $validated;
        }

        // Consistent lock order: ascending source participation id (BR-CON-03).
        usort($entries, static fn (array $a, array $b): int => (int) $a['source_participation_id'] <=> (int) $b['source_participation_id']);

        return $entries;
    }

    private function assertNoWorkingPlacement(int $candidateId): void
    {
        if (DB::table('placement_participants')
            ->where('candidate_id', $candidateId)
            ->where('status_penempatan', PlacementParticipantStatus::WORKING->value)
            ->exists()
        ) {
            $this->fail('candidate_id', 'PLACEMENT_ALREADY_WORKING');
        }
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
    private function notifyMaker(int $makerId, ActionType $action, array $payload): void
    {
        $this->notifications->notifyInApp([$makerId], $action->value, $payload);
        $this->notifications->queueEmailAfterCommit([$makerId], $action->value, $payload);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
