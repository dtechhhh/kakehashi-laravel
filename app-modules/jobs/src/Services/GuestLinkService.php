<?php

namespace Modules\Jobs\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Notifications\NotificationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * W4-T7 — internal Guest link request/approval.
 *
 * The public token surface and GuestCandidateView belong to Wave 6. This
 * service only owns the Maker–Checker decision and token issuance boundary.
 */
final class GuestLinkService
{
    private const TARGET_TYPE = 'interview_container';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function requestGuestLink(User $actor, int $containerId, array $payload): object
    {
        $this->authorizeExecute($actor);
        $version = $this->requireVersion($payload);
        $data = $this->validated($payload);

        return DB::transaction(function () use ($actor, $containerId, $data, $version): object {
            $container = $this->container($containerId);
            $this->assertActive($container);

            if ((int) $container->version !== $version) {
                throw new ConflictHttpException('CONFLICT');
            }

            $request = $this->pending->submit(
                type: PendingType::GUEST_LINK,
                targetType: self::TARGET_TYPE,
                targetId: $containerId,
                requestedBy: $actor->getKey(),
                auditAction: ActionType::GUEST_LINK_REQUESTED,
                payload: [
                    'snapshot' => [
                        'interview_container_id' => $containerId,
                        'version' => $version,
                        'label' => $data['label'],
                        'tanggal_kadaluarsa' => $data['expires_at']->toIso8601String(),
                        'kode_tambahan_hash' => $data['code_hash'],
                    ],
                ],
                auditDetail: [
                    'interview_container_id' => $containerId,
                    'version' => $version,
                    'tanggal_kadaluarsa' => $data['expires_at']->toIso8601String(),
                ],
            );

            $this->notifyJobManagers(ActionType::GUEST_LINK_REQUESTED, [
                'interview_container_id' => $containerId,
                'pending_request_id' => (int) $request->getKey(),
            ]);

            return $request;
        });
    }

    /**
     * Approve a pending link. The raw token is returned once and is never
     * persisted; only its SHA-256 hash is stored for the future Guest surface.
     *
     * @param  string|array{note_checker?: string, version?: int|string}|null  $noteChecker
     * @param  array{version?: int|string}  $options
     */
    public function approveGuestLink(
        User $actor,
        int $pendingRequestId,
        string|array|null $noteChecker = null,
        array $options = [],
    ): object {
        [$noteChecker, $options] = $this->decisionArguments($noteChecker, $options);
        $this->authorizeReview($actor);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker, $options): object {
            $request = $this->pendingGuestLink($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$container, $snapshot] = $this->approvalContext($request, $options);

            $this->pending->approve(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                auditAction: ActionType::GUEST_LINK_APPROVED,
                note: $noteChecker === null ? null : trim($noteChecker),
                auditDetail: [
                    'interview_container_id' => (int) $container->id,
                    'status_after' => 'Aktif',
                    'version' => (int) $snapshot['version'],
                ],
            );

            $token = bin2hex(random_bytes(32));
            $linkId = DB::table('guest_link')->insertGetId([
                'label' => $snapshot['label'],
                'interview_container_id' => (int) $container->id,
                'token_hash' => hash('sha256', $token),
                'kode_tambahan_hash' => $snapshot['kode_tambahan_hash'] ?? null,
                'tanggal_kadaluarsa' => Carbon::parse($snapshot['tanggal_kadaluarsa']),
                'status_link' => 'Aktif',
                'dibuat_oleh' => (int) $request->requested_by,
                'disetujui_oleh' => $actor->getKey(),
                'created_at' => now(),
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            $this->notifyRequester((int) $request->requested_by, ActionType::GUEST_LINK_APPROVED, $pendingRequestId, (int) $linkId, (int) $container->id);

            $link = DB::table('guest_link')->where('id', $linkId)->first();
            $link->token = $token;
            $link->pending_request_id = $pendingRequestId;

            return $link;
        });
    }

    /**
     * Rejection is decided from the immutable pending snapshot. A container
     * that was closed after request creation must not strand the pending row.
     *
     * @param  array<string, mixed>  $options  compatibility-only; ignored
     */
    public function rejectGuestLink(
        User $actor,
        int $pendingRequestId,
        string $noteChecker,
        array $options = [],
    ): object {
        $this->authorizeReview($actor);
        $noteChecker = $this->requiredCheckerNote($noteChecker);

        return DB::transaction(function () use ($actor, $pendingRequestId, $noteChecker): object {
            $request = $this->pendingGuestLink($pendingRequestId);

            if ((int) $request->requested_by === (int) $actor->getKey()) {
                throw new AccessDeniedHttpException('APV_SELF');
            }

            [$snapshot] = $this->snapshot($request);
            $this->pending->reject(
                requestId: $pendingRequestId,
                checkerId: $actor->getKey(),
                note: $noteChecker,
                auditAction: ActionType::GUEST_LINK_REJECTED,
                auditDetail: [
                    'interview_container_id' => (int) $snapshot['interview_container_id'],
                    'version' => (int) $snapshot['version'],
                ],
            );

            $this->notifyRequester(
                (int) $request->requested_by,
                ActionType::GUEST_LINK_REJECTED,
                $pendingRequestId,
                null,
                (int) $snapshot['interview_container_id'],
            );

            return $request->fresh();
        });
    }

    public function __construct(
        private readonly PendingRequestService $pending,
        private readonly NotificationService $notifications,
    ) {}

    /** @param array<string, mixed> $payload */
    private function validated(array $payload): array
    {
        if (! array_key_exists('tanggal_kadaluarsa', $payload) && array_key_exists('expires_at', $payload)) {
            $payload['tanggal_kadaluarsa'] = $payload['expires_at'];
        }
        if (! array_key_exists('kode_tambahan', $payload) && array_key_exists('additional_code', $payload)) {
            $payload['kode_tambahan'] = $payload['additional_code'];
        }

        $values = Validator::make($payload, [
            'label' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'tanggal_kadaluarsa' => ['required', 'date'],
            'kode_tambahan' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        $expiresAt = Carbon::parse($values['tanggal_kadaluarsa']);
        if ($expiresAt->lessThanOrEqualTo(now())) {
            $this->fail('tanggal_kadaluarsa', 'GUEST_EXPIRY_PAST');
        }

        $code = $values['kode_tambahan'] ?? null;
        $code = is_string($code) ? trim($code) : null;

        return [
            'label' => trim($values['label']),
            'expires_at' => $expiresAt,
            'code_hash' => $code === null || $code === '' ? null : hash('sha256', $code),
        ];
    }

    private function approvalContext(PendingRequest $request, array $options): array
    {
        [$snapshot, $snapshotVersion] = $this->snapshot($request);
        $container = $this->container((int) $request->target_id);

        if ($container->status !== InterviewContainerStatus::ACTIVE->value
            || (int) $container->version !== $snapshotVersion
            || (int) $snapshot['interview_container_id'] !== (int) $container->id
        ) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (array_key_exists('version', $options) && $this->requireVersion($options) !== $snapshotVersion) {
            throw new ConflictHttpException('CONFLICT');
        }

        if (Carbon::parse($snapshot['tanggal_kadaluarsa'])->lessThanOrEqualTo(now())) {
            throw new ConflictHttpException('CONFLICT');
        }

        return [$container, $snapshot];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private function snapshot(PendingRequest $request): array
    {
        $snapshot = $request->payload['snapshot'] ?? null;
        if (! is_array($snapshot)
            || (int) ($snapshot['interview_container_id'] ?? 0) !== (int) $request->target_id
            || ! is_string($snapshot['label'] ?? null)
            || ! array_key_exists('tanggal_kadaluarsa', $snapshot)
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

    private function pendingGuestLink(int $pendingRequestId): PendingRequest
    {
        $request = PendingRequest::query()->find($pendingRequestId);
        if ($request === null
            || $request->type !== PendingType::GUEST_LINK
            || $request->target_type !== self::TARGET_TYPE
        ) {
            throw new ConflictHttpException('GUEST_LINK_PENDING_INVALID');
        }
        if ($request->status !== PendingStatus::PENDING) {
            throw new ConflictHttpException('APV_DONE');
        }

        return $request;
    }

    private function container(int $containerId): object
    {
        $container = DB::table(self::TARGET_TYPE)->where('id', $containerId)->first();
        if ($container === null) {
            throw new NotFoundHttpException('IC_NOT_FOUND');
        }

        return $container;
    }

    private function assertActive(object $container): void
    {
        if ($container->status !== InterviewContainerStatus::ACTIVE->value) {
            $this->fail('container', 'GUEST_CONTAINER_NOT_ACTIVE');
        }
    }

    private function authorizeExecute(User $actor): void
    {
        $this->assertActor($actor);
        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }
        Gate::forUser($actor)->authorize('jobs.execute');
    }

    private function authorizeReview(User $actor): void
    {
        $this->assertActor($actor);
        if ($actor->status_akun !== 'Aktif') {
            throw new AuthorizationException('JOBS_INACTIVE');
        }
        Gate::forUser($actor)->authorize('jobs.review');
    }

    private function assertActor(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('JOBS_ACTOR_MISMATCH');
        }
    }

    private function requireVersion(array $payload): int
    {
        $version = $payload['version'] ?? null;
        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            $this->fail('version', 'GUEST_VERSION_REQUIRED');
        }

        return (int) $version;
    }

    private function requiredCheckerNote(string $note): string
    {
        $note = trim($note);
        if ($note === '') {
            $this->fail('note_checker', 'APV_NOTE');
        }

        return $note;
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
            $noteChecker = is_string($noteChecker) ? $noteChecker : null;
        }

        return [$noteChecker, $options];
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

    private function notifyRequester(
        int $requesterId,
        ActionType $action,
        int $pendingRequestId,
        ?int $linkId,
        int $containerId,
    ): void {
        $payload = [
            'interview_container_id' => $containerId,
            'pending_request_id' => $pendingRequestId,
        ];
        if ($linkId !== null) {
            $payload['guest_link_id'] = $linkId;
        }

        $ids = [$requesterId];
        $this->notifications->notifyInApp($ids, $action->value, $payload);
        $this->notifications->queueEmailAfterCommit($ids, $action->value, $payload);
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
