<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogQueryService;

/**
 * S5 — read-only audit log viewer (Super Admin).
 *
 * Filters are whitelisted; rows are immutable and AuditLogger already
 * forbids secret/PII keys, so rendering detail JSON never leaks secrets.
 */
final class AuditLogViewer extends Component
{
    use WithPagination;

    public string $actionType = '';

    public string $entityType = '';

    public string $actorId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function render()
    {
        Gate::authorize('viewAny', User::class);

        $filters = [
            'action_type' => $this->actionType !== '' ? $this->actionType : null,
            'entity_type' => $this->entityType !== '' ? $this->entityType : null,
            'actor_id' => $this->actorId !== '' ? (int) $this->actorId : null,
            'date_from' => $this->dateFrom !== '' ? $this->dateFrom : null,
            'date_to' => $this->dateTo !== '' ? $this->dateTo : null,
        ];

        $logs = app(AuditLogQueryService::class)->paginate(auth()->user(), $filters);

        return view('livewire.admin.audit-log-viewer', [
            'logs' => $logs,
            'actionTypes' => ActionType::cases(),
            'actors' => User::query()->orderBy('name')->limit(100)->get(),
        ]);
    }

    public function resetFilters(): void
    {
        $this->reset('actionType', 'entityType', 'actorId', 'dateFrom', 'dateTo');
    }
}
