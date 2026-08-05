<?php

namespace App\Livewire\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Jobs\Public\InterviewQueryService;
use Modules\Jobs\Services\InterviewContainerService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W4-T3 — W4 IC_CREATE approval queue (Manajer Job, no step-up).
 *
 * Approve → Aktif; reject with required note → Draft (code retained).
 * Double decision and stale version surface as a 409 conflict banner.
 */
final class InterviewReviewQueue extends Component
{
    use WithPagination;

    public ?int $rejectingId = null;

    public string $rejectNote = '';

    public ?string $actionError = null;

    public bool $conflict = false;

    public function render()
    {
        Gate::authorize('jobs.review');

        return view('livewire.jobs.interview-review-queue', [
            'queue' => app(InterviewQueryService::class)->createApprovalQueue(Auth::user()),
        ]);
    }

    public function approve(int $pendingRequestId, int $containerVersion): void
    {
        $this->resetActionState();

        try {
            app(InterviewContainerService::class)
                ->approve(Auth::user(), $pendingRequestId, ['version' => $containerVersion]);

            session()->flash('status', __('ui.jobs.queue.approved'));
            $this->redirect(route('jobs.review'));
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

    public function reject(int $pendingRequestId, int $containerVersion): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->rejectNote) === '') {
            $this->actionError = __('ui.jobs.queue.note_required');

            return;
        }

        try {
            app(InterviewContainerService::class)
                ->reject(Auth::user(), $pendingRequestId, trim($this->rejectNote), ['version' => $containerVersion]);

            session()->flash('status', __('ui.jobs.queue.rejected'));
            $this->redirect(route('jobs.review'));
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
        $key = 'ui.jobs.errors.'.$message;
        $translated = __($key);

        return $translated === $key ? $message : $translated;
    }

    private function translateCode(string $code): string
    {
        $key = 'ui.jobs.errors.'.$code;
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }

    private function resetActionState(): void
    {
        $this->actionError = null;
        $this->conflict = false;
    }
}
