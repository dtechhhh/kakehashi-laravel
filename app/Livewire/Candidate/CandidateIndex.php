<?php

namespace App\Livewire\Candidate;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Public\CandidateQueryService;

/**
 * K1 — candidate list (read-only, Staf Input / Approver / Super Admin).
 *
 * Server-side pagination (25) and whitelisted sort/filter only
 * (status_approval, status_ketersediaan, nama, umur). Status badges always
 * render glyph + text + color. No mutations here.
 */
final class CandidateIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusApproval = '';

    public string $statusKetersediaan = '';

    public string $ageFrom = '';

    public string $ageTo = '';

    public string $sort = 'updated_at';

    public string $direction = 'desc';

    public function render()
    {
        Gate::authorize('candidate.view');

        $candidates = app(CandidateQueryService::class)->paginate(Auth::user(), [
            'search' => $this->search,
            'status_approval' => $this->statusApproval,
            'status_ketersediaan' => $this->statusKetersediaan,
            'age_from' => $this->ageFrom !== '' ? (int) $this->ageFrom : null,
            'age_to' => $this->ageTo !== '' ? (int) $this->ageTo : null,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ]);

        return view('livewire.candidate.candidate-index', [
            'candidates' => $candidates,
            'approvalStatuses' => CandidateApprovalStatus::cases(),
            'availabilityStatuses' => CandidateAvailability::cases(),
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
        $this->reset('search', 'statusApproval', 'statusKetersediaan', 'ageFrom', 'ageTo');
        $this->resetPage();
    }

    public function ageOf(object $candidate): int
    {
        return Carbon::parse($candidate->tanggal_lahir)->age;
    }
}
