<?php

namespace App\Livewire\Jobs;

use App\Livewire\StepUpModal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use Modules\Jobs\Public\InterviewQueryService;
use Modules\Jobs\Services\InterviewContainerService;
use Modules\Jobs\Services\InterviewParticipationService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * UI-W4-T0 — W2 interview-container detail (read-only).
 *
 * Header fields, participation list with status badges, and pending overlays
 * (IC_CREATE / IC_CLOSE / IC_EXPEL / GUEST_LINK). No mutation.
 */
final class InterviewDetail extends Component
{
    public int $containerId;

    public bool $notFound = false;

    public int $version = 0;

    public ?int $rejectingId = null;

    public string $rejectNote = '';

    public ?string $actionError = null;

    public bool $conflict = false;

    public ?int $expelRequestingId = null;

    public string $expelReason = '';

    public ?int $expelRejectingId = null;

    public string $expelRejectNote = '';

    public ?int $expelApprovingId = null;

    public string $expelApproveNote = '';

    /**
     * @var array{type: string, pendingId: int, participationId: int, note: string}|null
     */
    public ?array $expelPending = null;

    public bool $closeRequesting = false;

    public string $closeReason = '';

    public ?int $closeApprovingId = null;

    public string $closeApproveNote = '';

    public ?int $closeRejectingId = null;

    public string $closeRejectNote = '';

    /**
     * @var array{type: string, pendingId: int, note: string}|null
     */
    public ?array $closePending = null;

    public function mount(int $containerId): void
    {
        $this->containerId = $containerId;
    }

    public function render()
    {
        Gate::authorize('jobs.view');

        $payload = app(InterviewQueryService::class)->detail(Auth::user(), $this->containerId);

        if ($payload === null) {
            $this->notFound = true;

            return view('livewire.jobs.interview-detail');
        }

        $this->version = (int) $payload['container']->version;

        return view('livewire.jobs.interview-detail', $payload + [
            'canUpdateParticipation' => Auth::user()->can('jobs.execute') && $payload['container']->status === 'Aktif',
        ]);
    }

    public function approveCreate(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(InterviewContainerService::class)
                ->approve(Auth::user(), $pendingRequestId, ['version' => $this->version]);

            session()->flash('status', __('ui.jobs.queue.approved'));
            $this->redirect(route('jobs.show', $this->containerId));
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

    public function rejectCreate(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->rejectNote) === '') {
            $this->actionError = __('ui.jobs.queue.note_required');

            return;
        }

        try {
            app(InterviewContainerService::class)
                ->reject(Auth::user(), $pendingRequestId, trim($this->rejectNote), ['version' => $this->version]);

            session()->flash('status', __('ui.jobs.queue.rejected'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function updateParticipationStatus(int $participationId, string $status, int $version): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            app(InterviewParticipationService::class)
                ->updateStatus(Auth::user(), $participationId, $status, ['version' => $version]);

            session()->flash('status', __('ui.jobs.status_updated'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    /**
     * Legal natural next steps for a participation status. `Terkirim` is
     * deliberately never offered here — it is a Placement batch effect only.
     *
     * @return list<string>
     */
    public function nextSteps(string $status): array
    {
        return match ($status) {
            'Menunggu Wawancara' => ['Lulus', 'Tidak Lolos', 'Mengundurkan Diri'],
            'Lulus' => ['Proses Dokumen', 'Tidak Lolos', 'Mengundurkan Diri'],
            'Proses Dokumen' => ['Siap Dikirim', 'Tidak Lolos', 'Mengundurkan Diri'],
            'Siap Dikirim' => ['Tidak Lolos', 'Mengundurkan Diri'],
            default => [],
        };
    }

    // ----- W8 expel -----

    public function startExpelRequest(int $participationId): void
    {
        $this->expelRequestingId = $participationId;
        $this->expelReason = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelExpelRequest(): void
    {
        $this->expelRequestingId = null;
        $this->expelReason = '';
    }

    public function requestExpel(int $participationId, int $version): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelReason) === '') {
            $this->actionError = __('ui.jobs.expel.reason_required');

            return;
        }

        try {
            app(InterviewParticipationService::class)
                ->requestExpel(Auth::user(), $participationId, trim($this->expelReason), ['version' => $version]);

            session()->flash('status', __('ui.jobs.expel.requested'));
            $this->redirect(route('jobs.show', $this->containerId));
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

    public function approveExpel(int $pendingRequestId, int $participationId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelApproveNote) === '') {
            $this->actionError = __('ui.jobs.expel.note_required');

            return;
        }

        $this->expelPending = [
            'type' => 'approve',
            'pendingId' => $pendingRequestId,
            'participationId' => $participationId,
            'note' => trim($this->expelApproveNote),
        ];

        $this->requireExpelStepUpOrExecute($participationId);
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

    public function rejectExpel(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->expelRejectNote) === '') {
            $this->actionError = __('ui.jobs.expel.note_required');

            return;
        }

        try {
            app(InterviewParticipationService::class)
                ->rejectExpel(Auth::user(), $pendingRequestId, trim($this->expelRejectNote));

            session()->flash('status', __('ui.jobs.expel.rejected'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    private function requireExpelStepUpOrExecute(int $participationId): void
    {
        if (app(StepUpService::class)->hasValidElevation(
            StepUpAction::APPROVE_CANDIDATE_EXPEL,
            'participation',
            $participationId,
        )) {
            $this->executeExpelPending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::APPROVE_CANDIDATE_EXPEL,
            entityType: 'participation',
            entityId: $participationId,
        )->to(StepUpModal::class);
    }

    private function executeExpelPending(): void
    {
        $pending = $this->expelPending;

        try {
            app(InterviewParticipationService::class)
                ->approveExpel(Auth::user(), $pending['pendingId'], $pending['note']);

            session()->flash('status', __('ui.jobs.expel.approved'));
            $this->redirect(route('jobs.show', $this->containerId));
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

    // ----- W5 close -----

    public function startCloseRequest(): void
    {
        $this->closeRequesting = true;
        $this->closeReason = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelCloseRequest(): void
    {
        $this->closeRequesting = false;
        $this->closeReason = '';
    }

    public function requestClose(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->closeReason) === '') {
            $this->actionError = __('ui.jobs.close.reason_required');

            return;
        }

        try {
            app(InterviewContainerService::class)->requestClose(
                Auth::user(),
                $this->containerId,
                trim($this->closeReason),
                ['version' => $this->version],
            );

            session()->flash('status', __('ui.jobs.close.requested'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        }
    }

    public function startCloseApprove(int $pendingRequestId): void
    {
        $this->closeApprovingId = $pendingRequestId;
        $this->closeApproveNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelCloseApprove(): void
    {
        $this->closeApprovingId = null;
        $this->closeApproveNote = '';
    }

    public function approveClose(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->closeApproveNote) === '') {
            $this->actionError = __('ui.jobs.close.note_required');

            return;
        }

        $this->closePending = [
            'type' => 'approve',
            'pendingId' => $pendingRequestId,
            'note' => trim($this->closeApproveNote),
        ];

        $this->requireCloseStepUpOrExecute();
    }

    public function startCloseReject(int $pendingRequestId): void
    {
        $this->closeRejectingId = $pendingRequestId;
        $this->closeRejectNote = '';
        $this->actionError = null;
        $this->conflict = false;
    }

    public function cancelCloseReject(): void
    {
        $this->closeRejectingId = null;
        $this->closeRejectNote = '';
    }

    public function rejectClose(int $pendingRequestId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (trim($this->closeRejectNote) === '') {
            $this->actionError = __('ui.jobs.close.note_required');

            return;
        }

        try {
            app(InterviewContainerService::class)->rejectClose(
                Auth::user(),
                $pendingRequestId,
                trim($this->closeRejectNote),
            );

            session()->flash('status', __('ui.jobs.close.rejected'));
            $this->redirect(route('jobs.show', $this->containerId));
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
            && $entityType === 'participation'
            && $entityId === $this->expelPending['participationId']
        ) {
            $this->executeExpelPending();

            return;
        }

        if ($this->closePending !== null
            && $action === StepUpAction::APPROVE_INTERVIEW_CLOSE
            && $entityType === 'interview_container'
            && $entityId === $this->containerId
        ) {
            $this->executeClosePending();
        }
    }

    private function requireCloseStepUpOrExecute(): void
    {
        if (app(StepUpService::class)->hasValidElevation(
            StepUpAction::APPROVE_INTERVIEW_CLOSE,
            'interview_container',
            $this->containerId,
        )) {
            $this->executeClosePending();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::APPROVE_INTERVIEW_CLOSE,
            entityType: 'interview_container',
            entityId: $this->containerId,
        )->to(StepUpModal::class);
    }

    private function executeClosePending(): void
    {
        $pending = $this->closePending;

        try {
            app(InterviewContainerService::class)
                ->approveClose(Auth::user(), $pending['pendingId'], $pending['note']);

            session()->flash('status', __('ui.jobs.close.approved'));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (AuthorizationException|AccessDeniedHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        } finally {
            $this->closePending = null;
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
}
