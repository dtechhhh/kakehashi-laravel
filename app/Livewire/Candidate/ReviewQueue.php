<?php

namespace App\Livewire\Candidate;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Candidates\Services\CandidateApprovalService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * K4 — review queue (Approver Kandidat, Checker).
 *
 * Approve/Reject are routine (no step-up). Reject note is mandatory
 * (server-enforced APV_NOTE). Maker self-decision is denied by the service
 * (APV_SELF); a double decision or stale version surfaces as a 409 conflict
 * banner with reload. Approver never edits candidate data here.
 */
final class ReviewQueue extends Component
{
    use WithPagination;

    public string $status = 'pending';

    /**
     * @var array<int, string>
     */
    public array $rejectNotes = [];

    public ?string $actionError = null;

    public ?string $actionSuccess = null;

    public bool $conflict = false;

    public function render()
    {
        Gate::authorize('candidate.review');

        $rows = app(CandidateQueryService::class)->reviewQueue(Auth::user(), $this->status);

        return view('livewire.candidate.review-queue', [
            'rows' => $rows,
            'requesterId' => (int) Auth::id(),
        ]);
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function approve(int $pendingId, int $candidateVersion): void
    {
        $this->resetActionState();

        try {
            app(CandidateApprovalService::class)->approve(Auth::user(), $pendingId, [
                'version' => $candidateVersion,
            ]);

            $this->actionSuccess = __('ui.review.success_approved');
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (AccessDeniedHttpException|NotFoundHttpException) {
            $this->actionError = __('ui.review.errors.APV_SELF');
        } catch (ValidationException|AuthorizationException $exception) {
            $this->actionError = $this->mapError($exception);
        }
    }

    public function reject(int $pendingId, int $candidateVersion): void
    {
        $this->resetActionState();

        $note = trim($this->rejectNotes[$pendingId] ?? '');

        if ($note === '') {
            $this->actionError = __('ui.review.errors.APV_NOTE');

            return;
        }

        try {
            app(CandidateApprovalService::class)->reject(Auth::user(), $pendingId, $note, [
                'version' => $candidateVersion,
            ]);

            $this->actionSuccess = __('ui.review.success_rejected');
            unset($this->rejectNotes[$pendingId]);
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (AccessDeniedHttpException|NotFoundHttpException) {
            $this->actionError = __('ui.review.errors.APV_SELF');
        } catch (ValidationException|AuthorizationException $exception) {
            $this->actionError = $this->mapError($exception);
        }
    }

    private function mapError(ValidationException|AuthorizationException $exception): string
    {
        if ($exception instanceof AuthorizationException) {
            return __('ui.review.errors.'.$exception->getMessage(), [], app()->getLocale());
        }

        $code = collect($exception->errors())->flatten()->first();
        $translated = is_string($code) ? __('ui.review.errors.'.$code, [], app()->getLocale()) : null;

        if (is_string($translated) && $translated !== 'ui.review.errors.'.$code) {
            return $translated;
        }

        return is_string($code) ? $code : __('ui.review.errors.GENERIC');
    }

    private function resetActionState(): void
    {
        $this->actionError = null;
        $this->actionSuccess = null;
        $this->conflict = false;
    }
}
