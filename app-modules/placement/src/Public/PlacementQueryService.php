<?php

namespace Modules\Placement\Public;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Placement\Enums\PlacementContainerStatus;
use Shared\Approval\PendingType;

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

    private const PARTICIPANT_TYPE = 'placement_participants';

    private const SORTABLE = [
        'kode_kontainer' => 'pc.kode_kontainer',
        'nama' => 'pc.nama',
        'status' => 'pc.status',
        'created_at' => 'pc.created_at',
        'updated_at' => 'pc.updated_at',
    ];

    private const CONTAINER_PENDING_TYPES = [
        PendingType::PC_CREATE->value,
        PendingType::PC_CANCEL_ACTIVE->value,
        PendingType::PLACEMENT_BATCH->value,
        PendingType::FORCE_MAJEUR->value,
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

    /**
     * @return array{
     *     container: object,
     *     participants: Collection<int, object>,
     *     pending: Collection<int, object>,
     * }|null
     */
    public function detail(User $actor, int $containerId): ?array
    {
        Gate::forUser($actor)->authorize('placement.view');

        $container = DB::table(self::TARGET_TYPE.' as pc')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'pc.perusahaan_id')
            ->select('pc.*', 'p.nama_ja as perusahaan_nama_ja')
            ->where('pc.id', $containerId)
            ->first();

        if ($container === null) {
            return null;
        }

        $participants = DB::table(self::PARTICIPANT_TYPE.' as pp')
            ->leftJoin('candidate as c', 'c.id', '=', 'pp.candidate_id')
            ->leftJoin('jenis_visa as v', 'v.id', '=', 'pp.jenis_visa_id')
            ->select(
                'pp.*',
                'c.nomor_induk as candidate_nomor_induk',
                'c.nama_alphabet as candidate_nama_alphabet',
                'c.nama_katakana as candidate_nama_katakana',
                'c.pii_anonymized_at as candidate_anonymized_at',
                'c.deleted_at as candidate_deleted_at',
                'v.label_id as visa_label_id',
                'v.label_ja as visa_label_ja',
            )
            ->where('pp.placement_container_id', $containerId)
            ->orderBy('pp.id')
            ->get();

        return [
            'container' => $container,
            'participants' => $participants,
            'pending' => $this->pendingOverlays($containerId, $participants),
        ];
    }

    /**
     * Active company options keyed by id (P3 form dropdown). A soft-disabled
     * company stays selectable when it is the current value of an edited
     * container so old data keeps rendering.
     *
     * @return array<int, string>
     */
    public function perusahaanOptions(User $actor, ?int $includeId = null): array
    {
        Gate::forUser($actor)->authorize('placement.execute');

        $options = DB::table('perusahaan')
            ->where('is_active', true)
            ->orderBy('nama_ja')
            ->orderBy('id')
            ->get(['id', 'nama_ja', 'nama_romaji'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => $this->perusahaanLabel($row),
            ])
            ->all();

        if ($includeId !== null && ! array_key_exists($includeId, $options)) {
            $row = DB::table('perusahaan')->where('id', $includeId)->first(['id', 'nama_ja', 'nama_romaji']);
            if ($row !== null) {
                $options[(int) $row->id] = $this->perusahaanLabel($row);
            }
        }

        return $options;
    }

    /**
     * T3 — Checker approval queue for placement containers (PC_CREATE and
     * PC_CANCEL_ACTIVE; later task types join as their panels wire them).
     * Container version is included for the optimistic lock expected by the
     * domain service.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function reviewQueue(User $actor, int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('placement.review');

        return DB::table('pending_request as pr')
            ->join('placement_container as pc', 'pc.id', '=', 'pr.target_id')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'pc.perusahaan_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'pr.requested_by')
            ->where('pr.target_type', self::TARGET_TYPE)
            ->where('pr.status', 'pending')
            ->whereIn('pr.type', [
                PendingType::PC_CREATE->value,
                PendingType::PC_CANCEL_ACTIVE->value,
            ])
            ->select(
                'pr.id as pending_id',
                'pr.type',
                'pr.requested_by',
                'pr.created_at as requested_at',
                'requester.name as requested_by_name',
                'pc.id as container_id',
                'pc.kode_kontainer',
                'pc.nama',
                'pc.status as container_status',
                'pc.version as container_version',
                'p.nama_ja as perusahaan_nama_ja',
            )
            ->orderByDesc('pr.created_at')
            ->orderByDesc('pr.id')
            ->paginate(max(1, min(100, $perPage)));
    }

    private function perusahaanLabel(object $row): string
    {
        $label = (string) ($row->nama_ja ?? '');
        $romaji = (string) ($row->nama_romaji ?? '');

        return trim($romaji !== '' ? $label.' ('.$romaji.')' : $label);
    }

    /**
     * Pending overlays for a container: container-level placement pendings
     * (PC_CREATE / PC_CANCEL_ACTIVE / PLACEMENT_BATCH / FORCE_MAJEUR) and
     * participant-level resign/expel requests.
     *
     * @param  Collection<int, object>  $participants
     * @return Collection<int, object>
     */
    private function pendingOverlays(int $containerId, Collection $participants): Collection
    {
        $participantIds = $participants
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return DB::table('pending_request')
            ->where('status', 'pending')
            ->where(function ($query) use ($containerId, $participantIds): void {
                $query->where(function ($query) use ($containerId): void {
                    $query->where('target_type', self::TARGET_TYPE)
                        ->where('target_id', $containerId)
                        ->whereIn('type', self::CONTAINER_PENDING_TYPES);
                });

                if ($participantIds !== []) {
                    $query->orWhere(function ($query) use ($participantIds): void {
                        $query->where('target_type', self::PARTICIPANT_TYPE)
                            ->whereIn('target_id', $participantIds)
                            ->whereIn('type', [
                                PendingType::PLACEMENT_RESIGN->value,
                                PendingType::PLACEMENT_EXPEL->value,
                            ]);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
