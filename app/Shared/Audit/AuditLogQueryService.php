<?php

namespace Shared\Audit;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Modules\Auth\Rbac;

/**
 * Read-only audit log viewer contract (S5).
 *
 * Authorized: authenticated active Super Admin only. Rendering must never
 * expose secrets — AuditLogger already forbids secret/PII keys at write time.
 */
final class AuditLogQueryService
{
    /**
     * @param  array{
     *     action_type?: string,
     *     entity_type?: string,
     *     actor_id?: int,
     *     date_from?: string,
     *     date_to?: string,
     * }  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor);

        $filters = $this->validateFilters($filters);

        return AuditLog::query()
            ->with('actor')
            ->when(($filters['action_type'] ?? null) !== null, function ($query) use ($filters): void {
                $query->where('action_type', $filters['action_type']);
            })
            ->when(($filters['entity_type'] ?? null) !== null, function ($query) use ($filters): void {
                $query->where('entity_type', $filters['entity_type']);
            })
            ->when(($filters['actor_id'] ?? null) !== null, function ($query) use ($filters): void {
                $query->where('actor_id', $filters['actor_id']);
            })
            ->when(($filters['date_from'] ?? null) !== null, function ($query) use ($filters): void {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            })
            ->when(($filters['date_to'] ?? null) !== null, function ($query) use ($filters): void {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Authorized actor options for the S5 filter dropdown.
     *
     * Authenticated active Super Admin only; at most 100 rows with only the
     * fields the view needs (id, name).
     *
     * @return Collection<int, User>
     */
    public function actorOptions(User $actor): Collection
    {
        $this->authorize($actor);

        return User::query()
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);
    }

    private function authorize(User $actor): void
    {
        if ($actor->status_akun !== 'Aktif' || ! $actor->hasRole(Rbac::SUPER_ADMIN)) {
            throw new AuthorizationException('USR_ADMIN_ONLY');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function validateFilters(array $filters): array
    {
        $actionTypes = array_map(
            static fn (ActionType $type): string => $type->value,
            ActionType::cases(),
        );

        Validator::make($filters, [
            'action_type' => ['nullable', 'string', 'in:'.implode(',', $actionTypes)],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ])->validate();

        return $filters;
    }
}
