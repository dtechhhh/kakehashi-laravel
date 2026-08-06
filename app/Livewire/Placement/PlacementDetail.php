<?php

namespace App\Livewire\Placement;

use App\Livewire\StepUpModal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementContainerService;
use Modules\Placement\Services\PlacementParticipationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T1 — P2 placement-container detail (read-only).
 *
 * Header fields, participant list with status badges, and pending overlays.
 * Arsip / Dibatalkan containers render read-only; no mutation here.
 */
final class PlacementDetail extends Component
{
    public int $containerId;

    public bool $notFound = false;

    public int $version = 0;

    public bool $cancelRequesting = false;

    public string $cancelReason = '';

    public ?int $cancelRejectingId = null;

    public string $cancelRejectNote = '';

    public ?int $statusUpdatingId = null;

    public ?int $resignRequestingId = null;

    public string $resignReason = '';

    public ?int $resignApprovingId = null;

    public string $resignApproveNote = '';

    public ?int $resignRejectingId = null;

    public string $resignRejectNote = '';

    public ?int $expelRequestingId = null;

    public string $expelReason = '';

    public ?int $expelApprovingId = null;

    public string $expelApproveNote = '';

    public ?int $expelRejectingId = null;

    public string $expelRejectNote = '';

    /**
     * @var array{pendingId: int, participantId: int, participantVersion: int, note: string}|null
     */
    public ?array $expelPending = null;

    public ?string $actionError = null;

    public bool $conflict = false;

    public function mount(int $containerId): void
    {
        $this->containerId = $containerId;
    }

    public function render()
    {
        Gate::authorize('placement.view');

        $payload = app(PlacementQueryService::class)->detail(Auth::user(), $this->containerId);

        if ($payload === null) {
            $this->notFound = true;

            return view('livewire.placement.placement-detail');
        }

        $this->version = (int) $payload['container']->version;

        return view('livewire.placement.placement-detail', $payload + [
            'participantCount' => $payload['participants']->count(),
            'isMaker' => (int) $payload['container']->dibuat_oleh === (int) Auth::id(),
            'canUpdate' => Auth::user()->can('placement.execute') && $payload['container']->status === 'Aktif',
        ]);
    }

    // ----- GAP-4: cancel active empty container (Maker request, Checker decide) -----

    public function startCancelRequest(): void
    {
        $this->cancelRequesting = true;
        $this->cancelReason = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelCancelRequest(): void
    {
        $this->cancelRequesting = false;
        $this->cancelReason = '';
    }

    public function requestCancelActive(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(PlacementContainerService::class)
                ->requestCancelActive(Auth::user(), $this->containerId, trim($this->cancelReason), ['version' => $this->version]);

            session()->flash('status', __('ui.placement.cancel_active.requested'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function approveCancelActive(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(PlacementContainerService::class)
                ->approveCancelActive(Auth::user(), $pendingRequestId, ['version' => $this->version]);

            session()->flash('status', __('ui.placement.cancel_active.approved'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startCancelReject(int $pendingRequestId): void
    {
        $this->cancelRejectingId = $pendingRequestId;
        $this->cancelRejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelCancelReject(): void
    {
        $this->cancelRejectingId = null;
        $this->cancelRejectNote = '';
    }

    public function rejectCancelActive(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->cancelRejectNote) === '') {
            $this->actionError = __('ui.placement.cancel_active.note_required');

            return;
        }

        try {
            app(PlacementContainerService::class)
                ->rejectCancelActive(Auth::user(), $pendingRequestId, trim($this->cancelRejectNote), ['version' => $this->version]);

            session()->flash('status', __('ui.placement.cancel_active.rejected'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    // ----- P6 status penempatan -----

    public function completeContract(int $participantId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(PlacementParticipationService::class)
                ->completeContract(Auth::user(), $participantId, ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.completed'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startResignRequest(int $participantId): void
    {
        $this->resignRequestingId = $participantId;
        $this->resignReason = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelResignRequest(): void
    {
        $this->resignRequestingId = null;
        $this->resignReason = '';
    }

    public function requestResign(int $participantId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->resignReason) === '') {
            $this->actionError = __('ui.placement.status.reason_required');

            return;
        }

        try {
            app(PlacementParticipationService::class)
                ->requestResign(Auth::user(), $participantId, trim($this->resignReason), ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.resign_requested'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startResignApprove(int $pendingRequestId): void
    {
        $this->resignApprovingId = $pendingRequestId;
        $this->resignApproveNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelResignApprove(): void
    {
        $this->resignApprovingId = null;
        $this->resignApproveNote = '';
    }

    public function approveResign(int $pendingRequestId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(PlacementParticipationService::class)
                ->approveResign(Auth::user(), $pendingRequestId, trim($this->resignApproveNote) !== '' ? trim($this->resignApproveNote) : null, ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.resign_approved'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startResignReject(int $pendingRequestId): void
    {
        $this->resignRejectingId = $pendingRequestId;
        $this->resignRejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelResignReject(): void
    {
        $this->resignRejectingId = null;
        $this->resignRejectNote = '';
    }

    public function rejectResign(int $pendingRequestId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->resignRejectNote) === '') {
            $this->actionError = __('ui.placement.status.note_required');

            return;
        }

        try {
            app(PlacementParticipationService::class)
                ->rejectResign(Auth::user(), $pendingRequestId, trim($this->resignRejectNote), ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.resign_rejected'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startExpelRequest(int $participantId): void
    {
        $this->expelRequestingId = $participantId;
        $this->expelReason = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelExpelRequest(): void
    {
        $this->expelRequestingId = null;
        $this->expelReason = '';
    }

    public function requestExpel(int $participantId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelReason) === '') {
            $this->actionError = __('ui.placement.status.reason_required');

            return;
        }

        try {
            app(PlacementParticipationService::class)
                ->requestExpel(Auth::user(), $participantId, trim($this->expelReason), ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.expel_requested'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startExpelApprove(int $pendingRequestId): void
    {
        $this->expelApprovingId = $pendingRequestId;
        $this->expelApproveNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelExpelApprove(): void
    {
        $this->expelApprovingId = null;
        $this->expelApproveNote = '';
    }

    public function approveExpel(int $pendingRequestId, int $participantId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelApproveNote) === '') {
            $this->actionError = __('ui.placement.status.note_required');

            return;
        }

        $this->expelPending = [
            'pendingId' => $pendingRequestId,
            'participantId' => $participantId,
            'participantVersion' => $participantVersion,
            'note' => trim($this->expelApproveNote),
        ];

        $this->requireExpelStepUpOrExecute();
    }

    public function startExpelReject(int $pendingRequestId): void
    {
        $this->expelRejectingId = $pendingRequestId;
        $this->expelRejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelExpelReject(): void
    {
        $this->expelRejectingId = null;
        $this->expelRejectNote = '';
    }

    public function rejectExpel(int $pendingRequestId, int $participantVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelRejectNote) === '') {
            $this->actionError = __('ui.placement.status.note_required');

            return;
        }

        try {
            app(PlacementParticipationService::class)
                ->rejectExpel(Auth::user(), $pendingRequestId, trim($this->expelRejectNote), ['version' => $participantVersion]);

            session()->flash('status', __('ui.placement.status.expel_rejected'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if ($this->expelPending !== null
            && $action === StepUpAction::APPROVE_CANDIDATE_EXPEL
            && $entityType === 'placement_participants'
            && $entityId === $this->expelPending['participantId']
        ) {
            $this->executeExpelPending();
        }
    }

    private function requireExpelStepUpOrExecute(): void
    {
        if (app(StepUpService::class)->hasValidElevation(
            StepUpAction::APPROVE_CANDIDATE_EXPEL,
            'placement_participants',
            $this->expelPending['participantId'],
        )) {
            $this->executeExpelPending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::APPROVE_CANDIDATE_EXPEL,
            entityType: 'placement_participants',
            entityId: $this->expelPending['participantId'],
        )->to(StepUpModal::class);
    }

    private function executeExpelPending(): void
    {
        $pending = $this->expelPending;

        try {
            app(PlacementParticipationService::class)
                ->approveExpel(Auth::user(), $pending['pendingId'], $pending['note'], ['version' => $pending['participantVersion']]);

            session()->flash('status', __('ui.placement.status.expel_approved'));
            $this->redirect(route('placements.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        } finally {
            $this->expelPending = null;
        }
    }

    private function firstError(ValidationException $exception): string
    {
        $message = (string) collect($exception->errors())->flatten()->first();
        $key = 'ui.placement.errors.'.$message;
        $translated = __($key);

        return $translated === $key ? $message : $translated;
    }

    private function translateCode(string $code): string
    {
        $key = 'ui.placement.errors.'.$code;
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }
}
