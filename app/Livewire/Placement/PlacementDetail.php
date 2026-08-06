<?php

namespace App\Livewire\Placement;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Placement\Public\PlacementQueryService;

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

        return view('livewire.placement.placement-detail', $payload);
    }
}
