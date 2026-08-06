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
 * W5-T5 — Force-Majeur (PRD §6.4 Sub-flow 2b): kandidat Tersedia+Disetujui
 * masuk tanpa source participation. Approval rutin TANPA step-up; approve
 * memakai markInUse; tolak mencatat FM_REJECTED (kanonik).
 */
final class PlacementForceMajeurService
{
    private const TARGET_TYPE = 'placement_container';

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly CandidateAvailabilityService $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{version?: int|string}  $options
     */
    public function requestForceMajeur(User $actor, int $containerId, array $payload, array $options = []): object
    {
        $this->authorizeExecute($actor);
        $expectedVersion = $this->requireVersion($options);
        $reason = $this->requiredReason((string) ($payload['alasan_force_majeur'] ?? ''));
        $data = $this->validated($payload);
        $data['alasan_force_majeur'] = $reason;

        return DB::transaction(function () use ($actor, $containerId, $expectedVersion, $data, $reason): object {
            $container = $this->activeContainer($containerId, $expectedVersion);
            $candidateId = (int) $data['candidate_id'];

            if (! $this->availability->isAvailableAndApproved($candidateId)) {
                $this->fail('candidate', 'FM_STATE');
            }

            $candidateVersion = $this->availability->currentVersion($candidateId);
            $this->assertNoWorkingPlacement($candidateId);

            $endDate = ContractDates::endDate(
                $data['tanggal_mulai_kerja'],
                (int) $data['durasi_kontrak_bulan'],
                $data['tanggal_berakhir_kontrak'] ?? null,
            );

            $request = $this->pending->submit(
                type: PendingType::FORCE_MAJEUR,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: null, // no canonical FM submit event; pending row + FORCE_MAJEUR_ADDED/FM_REJECTED are the trail
                payload: [
                    'snapshot' => [
                        'placement_container_id' => $containerId,
                        'container_version' => $expectedVersion,
                        'candidate_id' => $candidateId,
                        'candidate_version' => $candidateVersion,
                        'kategori_force_majeur_id' => (int) $data['kategori_force_majeur_id'],
                        'alasan_force_majeur' => $reason,
                        'jenis_visa_id' => (int) $data['jenis_visa_id'],
                        'tanggal_mulai_kerja' => $data['tanggal_mulai_kerja'],
                        'durasi_kontrak_bulan' => (int) $data['durasi_kontrak_bulan'],
                        'tanggal_berakhir_kontrak' => $endDate,
                    ],
                ],
                auditDetail: [
                    'candidate_id' => $candidateId,
                    'placement_container_id' => $containerId,
                ],
            );

            $this->notifyReviewers(ActionType::FORCE_MAJEUR_ADDED, [
                'placement_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return (object) [
                'placement_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
                'container' => $container,
            ];
        });
    }

    /**
     * Approval rutin — TANPA step-up. markInUse flips Tersedia → Sedang Dipakai
     * and revalidates Tersedia+Disetujui inside its own conditional update.
     *
     * @param  array{version?: int|string}  $options
     */
    public function approveForceMajeur(User $actor, int $pendingRequestId, array $options = []): object
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $expectedVersion): object {
            $request = $this->pendingRequest($pendingRequestId);
            $snapshot = $this->snapshot($request, $expectedVersion);
            $this->activeContainer((int) $snapshot['placement_container_id'], $expectedVersion);

            // Lock/guard candidate first: markInUse asserts Tersedia+Disetujui
            // with the snapshot version; a stale or already-used candidate 409/422s
            // and rolls the whole approval back.
            $this->availability->markInUse(
                (int) $snapshot['candidate_id'],
                (int) $snapshot['candidate_version'],
            );

            $participantId = DB::table('placement_participants')->insertGetId([
                'placement_container_id' => (int) $snapshot['placement_container_id'],
                'candidate_id' => (int) $snapshot['candidate_id'],
                'source_participation_id' => null,
                'kategori_force_majeur_id' => (int) $snapshot['kategori_force_majeur_id'],
                'alasan_force_majeur' => $snapshot['alasan_force_majeur'],
                'jenis_visa_id' => (int) $snapshot['jenis_visa_id'],
                'tanggal_mulai_kerja' => $snapshot['tanggal_mulai_kerja'],
                'durasi_kontrak_bulan' => (int) $snapshot['durasi_kontrak_bulan'],
                'tanggal_berakhir_kontrak' => $snapshot['tanggal_berakhir_kontrak'],
                'status_penempatan' => PlacementParticipantStatus::WORKING->value,
                'tanggal_status_final' => null,
                'catatan_alasan' => null,
                'disetujui_oleh' => $actor->getKey(),
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::FORCE_MAJEUR_ADDED,
                auditDetail: [
                    'placement_container_id' => (int) $snapshot['placement_container_id'],
                    'candidate_id' => (int) $snapshot['candidate_id'],
                    'kategori_force_majeur_id' => (int) $snapshot['kategori_force_majeur_id'],
                    'fm_alasan_recorded' => $snapshot['alasan_force_majeur'],
                ],
            );

            $this->notifyMaker((int) $request->requested_by, ActionType::FORCE_MAJEUR_ADDED, [
                'placement_container_id' => (int) $snapshot['placement_container_id'],
                'pending_request_id' => $pendingRequestId,
                'candidate_id' => (int) $snapshot['candidate_id'],
            ]);

            return DB::table('placement_participants')->where('id', $participantId)->first();
        });
    }

    /**
     * @param  array{version?: int|string}  $options
     */
    public function rejectForceMajeur(User $actor, int $pendingRequestId, string $note, array $options = []): object
    {
        $this->authorizeReview($actor);
        $expectedVersion = $this->requireVersion($options);

        return DB::transaction(function () use ($actor, $pendingRequestId, $note, $expectedVersion): object {
            $request = $this->pendingRequest($pendingRequestId);
            $snapshot = $this->snapshot($request, $expectedVersion);

            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $note,
                auditAction: ActionType::FM_REJECTED,
                auditDetail: [
                    'placement_container_id' => (int) $snapshot['placement_container_id'],
                    'candidate_id' => (int) $snapshot['candidate_id'],
                ],
            );

            $this->notifyMaker((int) $request->requested_by, ActionType::FM_REJECTED, [
                'placement_container_id' => (int) $snapshot['placement_container_id'],
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
            || $request->type !== PendingType::FORCE_MAJEUR
            || $request->target_type !== self::TARGET_TYPE
        ) {
            throw new ConflictHttpException('FM_PENDING_INVALID');
        }

        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PendingRequest $request, int $expectedVersion): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || (int) ($snapshot['placement_container_id'] ?? 0) !== (int) $request->target_id
            || (int) ($snapshot['container_version'] ?? -1) !== $expectedVersion
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        foreach ([
            'candidate_id',
            'candidate_version',
            'kategori_force_majeur_id',
            'alasan_force_majeur',
            'jenis_visa_id',
            'tanggal_mulai_kerja',
            'durasi_kontrak_bulan',
            'tanggal_berakhir_kontrak',
        ] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                throw new ConflictHttpException('CONFLICT');
            }
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function validated(array $payload): array
    {
        return Validator::make($payload, [
            'candidate_id' => ['required', 'integer', 'min:1'],
            'kategori_force_majeur_id' => ['required', 'integer', Rule::exists('kategori_force_majeur', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'alasan_force_majeur' => ['string'],
            'jenis_visa_id' => ['required', 'integer', Rule::exists('jenis_visa', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'tanggal_mulai_kerja' => ['required', 'date'],
            'durasi_kontrak_bulan' => ['required', 'integer', 'min:1'],
            'tanggal_berakhir_kontrak' => ['nullable', 'date'],
        ])->validate();
    }

    private function requiredReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            $this->fail('alasan_force_majeur', 'FM_REASON');
        }

        return $reason;
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
