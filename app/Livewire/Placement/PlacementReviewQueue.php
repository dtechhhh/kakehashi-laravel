<?php

namespace App\Livewire\Placement;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementBatchService;
use Modules\Placement\Services\PlacementContainerService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W5-T3 — placement Checker approval queue (Manajer Job, no step-up).
 *
 * PC_CREATE approve → Aktif, reject + note → Draft (code retained);
 * PC_CANCEL_ACTIVE approve → Dibatalkan, reject + note → tetap Aktif.
 * Double decision and stale version surface as a 409 conflict banner.
 */
final class PlacementReviewQueue extends Component
{
    use WithPagination;

    public ?int $rejectingId = null;

    public string $rejectNote = '';

    public ?string $actionError = null;

    public bool $conflict = false;

    public function render()
    {
        Gate::authorize('placement.review');

        return view('livewire.placement.placement-review-queue', [
            'queue' => app(PlacementQueryService::class)->reviewQueue(Auth::user()),
        ]);
    }

    public function approve(int $pendingRequestId, string $type, int $containerVersion): void
    {
        $this->resetActionState();

        try {
            $this->decide($pendingRequestId, $type, $containerVersion, approve: true);

            session()->flash('status', __('ui.placement.queue.approved'));
            $this->redirect(route('placements.review'));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startReject(int $pendingRequestId): void
    {
        $this->rejectingId = $pendingRequestId;
        $this->rejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectNote = '';
    }

    public function reject(int $pendingRequestId, string $type, int $containerVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->rejectNote) === '') {
            $this->actionError = __('ui.placement.queue.note_required');

            return;
        }

        try {
            $this->decide($pendingRequestId, $type, $containerVersion, approve: false);

            session()->flash('status', __('ui.placement.queue.rejected'));
            $this->redirect(route('placements.review'));
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

    private function resetActionState(): void
    {
        $this->actionError = null;
        $this->conflict = false;
    }

    private function decide(int $pendingRequestId, string $type, int $containerVersion, bool $approve): void
    {
        $container = app(PlacementContainerService::class);
        $note = $approve ? null : trim($this->rejectNote);

        match ($type) {
            'PC_CREATE' => $approve
                ? $container->approve(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : $container->reject(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'PC_CANCEL_ACTIVE' => $approve
                ? $container->approveCancelActive(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : $container->rejectCancelActive(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            'PLACEMENT_BATCH' => $approve
                ? app(PlacementBatchService::class)->approveBatch(Auth::user(), $pendingRequestId, ['version' => $containerVersion])
                : app(PlacementBatchService::class)->rejectBatch(Auth::user(), $pendingRequestId, $note, ['version' => $containerVersion]),
            default => throw new \InvalidArgumentException('Unsupported pending type.'),
        };
    }
}
