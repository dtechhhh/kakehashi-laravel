<?php

namespace App\Livewire\Placement;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Placement\Enums\PlacementContainerStatus;
use Modules\Placement\Public\PlacementQueryService;

/**
 * UI-W5-T0 — P1 placement-container list (read-only).
 *
 * Server-side pagination (25), whitelisted sort/filter, status badges.
 * No mutations here. Asisten Manajer, Manajer Job, and Super Admin read via
 * `placement.view`; mutation permissions stay in the domain services.
 */
final class PlacementIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $sort = 'updated_at';

    public string $direction = 'desc';

    public function render()
    {
        Gate::authorize('placement.view');

        $containers = app(PlacementQueryService::class)->paginate(Auth::user(), [
            'search' => $this->search,
            'status' => $this->status,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);

        return view('livewire.placement.placement-index', [
            'containers' => $containers,
            'statuses' => PlacementContainerStatus::cases(),
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
