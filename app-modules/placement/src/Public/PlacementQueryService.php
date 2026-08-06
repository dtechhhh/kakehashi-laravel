<?php

namespace Modules\Placement\Public;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Placement\Enums\PlacementContainerStatus;

/**
 * UI-W5-T0 — read-only Placement views contract (P1 list).
 *
 * Authorization: `placement.view` on every call (Asisten Manajer, Manajer Job,
 * and Super Admin read-only). No mutation happens here; domain services remain
 * the only writers.
 */
final class PlacementQueryService
{
    private const TARGET_TYPE = 'placement_container';

    private const SORTABLE = [
        'kode_kontainer' => 'pc.kode_kontainer',
        'nama' => 'pc.nama',
        'status' => 'pc.status',
        'created_at' => 'pc.created_at',
        'updated_at' => 'pc.updated_at',
    ];

    /**
     * @param  array{
     *     search?: string,
     *     status?: string,
     *     sort?: string,
     *     direction?: string,
     * }  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('placement.view');

        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? 'pc.updated_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $statuses = array_map(
            static fn (PlacementContainerStatus $status): string => $status->value,
            PlacementContainerStatus::cases(),
        );

        return DB::table(self::TARGET_TYPE.' as pc')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'pc.perusahaan_id')
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('pc.nama', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('pc.kode_kontainer', 'ilike', '%'.$filters['search'].'%');
                });
            })
            ->when(
                isset($filters['status']) && in_array($filters['status'], $statuses, true),
                fn ($query) => $query->where('pc.status', $filters['status']),
            )
            ->select(
                'pc.id',
                'pc.kode_kontainer',
                'pc.nama',
                'pc.status',
                'pc.version',
                'pc.created_at',
                'pc.updated_at',
                'p.nama_ja as perusahaan_nama_ja',
            )
            ->orderBy($column, $direction)
            ->orderByDesc('pc.id')
            ->paginate(max(1, min(100, $perPage)));
    }
}
