<?php

namespace App\Livewire\Jobs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Jobs\Public\InterviewQueryService;

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

        return view('livewire.jobs.interview-detail', $payload);
    }
}
