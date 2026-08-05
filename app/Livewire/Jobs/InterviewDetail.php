<?php

namespace App\Livewire\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
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
