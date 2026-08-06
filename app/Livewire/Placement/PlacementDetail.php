<?php

namespace App\Livewire\Placement;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementContainerService;
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
