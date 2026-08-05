<?php

namespace App\Livewire\Jobs;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Jobs\Services\InterviewParticipationService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * UI-W4-T4 — W6 bulk pull (Maker, no approval).
 *
 * Picker shows Disetujui + Tersedia (pullable) and Sedang Dipakai (disabled
 * with a clear label). Selection is capped at 50; the pull service stays the
 * authority and a single ineligible candidate fails the whole batch.
 */
final class InterviewPull extends Component
{
    use WithPagination;

    public int $containerId;

    public string $search = '';

    /** @var array<int, int> candidate id => candidate id */
    public array $selected = [];

    public ?string $actionError = null;

    public bool $conflict = false;

    public function mount(int $containerId): void
    {
        $this->containerId = $containerId;
    }

    public function render()
    {
        Gate::authorize('jobs.execute');

        return view('livewire.jobs.interview-pull', [
            'candidates' => app(CandidateQueryService::class)
                ->interviewPullPicker(Auth::user(), $this->search),
        ]);
    }

    public function toggle(int $candidateId): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if (isset($this->selected[$candidateId])) {
            unset($this->selected[$candidateId]);

            return;
        }

        if (count($this->selected) >= 50) {
            $this->actionError = __('ui.jobs.pull.max_reached');

            return;
        }

        $this->selected[$candidateId] = $candidateId;
    }

    public function pullCandidates(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        if ($this->selected === []) {
            $this->actionError = __('ui.jobs.pull.none_selected');

            return;
        }

        if (count($this->selected) > 50) {
            $this->actionError = __('ui.jobs.pull.max_reached');

            return;
        }

        try {
            $pulled = app(InterviewParticipationService::class)
                ->pull(Auth::user(), $this->containerId, array_values($this->selected));

            $this->selected = [];
            session()->flash('status', __('ui.jobs.pull.success', ['count' => count($pulled)]));
            $this->redirect(route('jobs.show', $this->containerId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = $this->firstError($exception);
        } catch (NotFoundHttpException $exception) {
            $this->actionError = $this->translateCode($exception->getMessage());
        } catch (AuthorizationException $exception) {
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
}
