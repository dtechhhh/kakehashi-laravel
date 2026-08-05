<?php

namespace App\Livewire\Jobs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Public\InterviewQueryService;

/**
 * UI-W4-T0 — W1 interview-container list (read-only).
 *
 * Server-side pagination (25), whitelisted sort/filter, status badges.
 * No mutations here. Asisten Manajer, Manajer Job, and Super Admin read via
 * `jobs.view`; mutation permissions stay in the domain services.
 */
final class InterviewIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $sort = 'updated_at';

    public string $direction = 'desc';

    public function render()
    {
        Gate::authorize('jobs.view');

        $containers = app(InterviewQueryService::class)->paginate(Auth::user(), [
            'search' => $this->search,
            'status' => $this->status,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);

        return view('livewire.jobs.interview-index', [
            'containers' => $containers,
            'statuses' => InterviewContainerStatus::cases(),
        ]);
    }

    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }
}
