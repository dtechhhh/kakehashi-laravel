<?php

namespace Modules\Jobs\Public;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Jobs\Enums\InterviewContainerStatus;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Shared\Approval\PendingType;

/**
 * UI-W4-T0 — read-only Jobs views contract (W1 list / W2 detail).
 *
 * Authorization: `jobs.view` on every call (Asisten Manajer, Manajer Job, and
 * Super Admin read-only). No mutation happens here; domain services remain
 * the only writers. Candidate columns are read-only display snapshots and are
 * hidden when the candidate is anonymized or deleted.
 */
final class InterviewQueryService
{
    private const TARGET_TYPE = 'interview_container';

    private const SORTABLE = [
        'kode_kontainer' => 'ic.kode_kontainer',
        'judul' => 'ic.judul',
        'status' => 'ic.status',
        'tanggal_wawancara' => 'ic.tanggal_wawancara',
        'created_at' => 'ic.created_at',
        'updated_at' => 'ic.updated_at',
    ];

    private const CONTAINER_PENDING_TYPES = [
        PendingType::IC_CREATE->value,
        PendingType::IC_CLOSE->value,
        PendingType::GUEST_LINK->value,
    ];

    /** Natural accepted path: everything from Lulus onward. */
    private const ACCEPTED_STATUSES = [
        InterviewParticipationStatus::PASSED->value,
        InterviewParticipationStatus::DOCUMENT_PROCESS->value,
        InterviewParticipationStatus::READY_FOR_PLACEMENT->value,
        InterviewParticipationStatus::SENT->value,
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
        Gate::forUser($actor)->authorize('jobs.view');

        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? 'ic.updated_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $statuses = array_map(
            static fn (InterviewContainerStatus $status): string => $status->value,
            InterviewContainerStatus::cases(),
        );
        $activeParticipationSql = implode(',', array_map(
            static fn (string $status): string => "'".$status."'",
            InterviewParticipationStatus::activeValues(),
        ));

        return DB::table('interview_container as ic')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'ic.perusahaan_id')
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('ic.judul', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('ic.kode_kontainer', 'ilike', '%'.$filters['search'].'%');
                });
            })
            ->when(
                isset($filters['status']) && in_array($filters['status'], $statuses, true),
                fn ($query) => $query->where('ic.status', $filters['status']),
            )
            ->select(
                'ic.id',
                'ic.kode_kontainer',
                'ic.judul',
                'ic.status',
                'ic.tanggal_wawancara',
                DB::raw('(SELECT COUNT(*) FROM participation p WHERE p.interview_container_id = ic.id AND p.frozen_at IS NULL AND p.status_wawancara IN ('.$activeParticipationSql.')) as jumlah_peserta'),
                'ic.version',
                'ic.created_at',
                'ic.updated_at',
                'p.nama_ja as perusahaan_nama_ja',
            )
            ->orderBy($column, $direction)
            ->orderByDesc('ic.id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * W7-T2/T3 — cross-module probe for Candidates anonymization eligibility.
     * Active = non-frozen row in a non-terminal interview status.
     */
    public function hasActiveParticipation(int $candidateId): bool
    {
        return DB::table('participation')
            ->where('candidate_id', $candidateId)
            ->whereNull('frozen_at')
            ->whereIn('status_wawancara', InterviewParticipationStatus::activeValues())
            ->exists();
    }

    /**
     * @return array{
     *     container: object,
     *     participations: Collection<int, object>,
     *     pending: Collection<int, object>,
     *     acceptedCount: int,
     *     targetExceeded: bool,
     *     isMaker: bool,
     * }|null
     */
    public function detail(User $actor, int $containerId): ?array
    {
        Gate::forUser($actor)->authorize('jobs.view');

        $container = DB::table('interview_container as ic')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'ic.perusahaan_id')
            ->leftJoin('posisi_pekerjaan as pos', 'pos.id', '=', 'ic.posisi_pekerjaan_id')
            ->leftJoin('jenis_visa as v', 'v.id', '=', 'ic.jenis_visa_id')
            ->select(
                'ic.*',
                'p.nama_ja as perusahaan_nama_ja',
                'pos.label_id as posisi_label_id',
                'pos.label_ja as posisi_label_ja',
                'v.label_id as visa_label_id',
                'v.label_ja as visa_label_ja',
            )
            ->where('ic.id', $containerId)
            ->first();

        if ($container === null) {
            return null;
        }

        $participations = DB::table('participation as part')
            ->leftJoin('candidate as c', 'c.id', '=', 'part.candidate_id')
            ->select(
                'part.*',
                'c.nomor_induk as candidate_nomor_induk',
                'c.nama_alphabet as candidate_nama_alphabet',
                'c.nama_katakana as candidate_nama_katakana',
                'c.pii_anonymized_at as candidate_anonymized_at',
                'c.deleted_at as candidate_deleted_at',
            )
            ->where('part.interview_container_id', $containerId)
            ->orderBy('part.id')
            ->get();

        $container->jumlah_peserta = $participations
            ->filter(static fn (object $participation): bool => $participation->frozen_at === null
                && in_array($participation->status_wawancara, InterviewParticipationStatus::activeValues(), true))
            ->count();

        $pending = $this->pendingOverlays($containerId, $participations);

        $acceptedCount = $participations
            ->filter(static fn (object $participation): bool => in_array(
                $participation->status_wawancara,
                self::ACCEPTED_STATUSES,
                true,
            ))
            ->count();
        $target = $container->target_peserta_diterima;
        $targetExceeded = $target !== null && $acceptedCount >= (int) $target;
        $isMaker = (int) $container->dibuat_oleh === (int) $actor->getKey();

        return compact('container', 'participations', 'pending', 'acceptedCount', 'targetExceeded', 'isMaker');
    }

    /**
     * W4 — IC_CREATE approval queue for Manajer Job (jobs.review). The
     * container version is included so approve/reject can pass the optimistic
     * lock expected by the domain service.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function createApprovalQueue(User $actor, int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('jobs.review');

        return DB::table('pending_request as pr')
            ->join('interview_container as ic', 'ic.id', '=', 'pr.target_id')
            ->leftJoin('perusahaan as p', 'p.id', '=', 'ic.perusahaan_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'pr.requested_by')
            ->where('pr.type', PendingType::IC_CREATE->value)
            ->where('pr.target_type', self::TARGET_TYPE)
            ->where('pr.status', 'pending')
            ->select(
                'pr.id as pending_id',
                'pr.requested_by',
                'pr.created_at as requested_at',
                'requester.name as requested_by_name',
                'ic.id as container_id',
                'ic.kode_kontainer',
                'ic.judul',
                'ic.status as container_status',
                'ic.version as container_version',
                'p.nama_ja as perusahaan_nama_ja',
            )
            ->orderByDesc('pr.created_at')
            ->orderByDesc('pr.id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Active company options keyed by id (Jobs form dropdown). A soft-disabled
     * company stays selectable when it is the current value of an edited
     * container (MODULE_JOBS edge 9: old containers remain valid).
     *
     * @return array<int, string>
     */
    public function perusahaanOptions(User $actor, ?int $includeId = null): array
    {
        Gate::forUser($actor)->authorize('jobs.execute');

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

    private function perusahaanLabel(object $row): string
    {
        $label = (string) ($row->nama_ja ?? '');
        $romaji = (string) ($row->nama_romaji ?? '');

        return trim($romaji !== '' ? $label.' ('.$romaji.')' : $label);
    }

    /**
     * Pending overlays for a container: IC_CREATE / IC_CLOSE / GUEST_LINK on
     * the container itself and IC_EXPEL on its participations.
     *
     * @param  Collection<int, object>  $participations
     * @return Collection<int, object>
     */
    private function pendingOverlays(int $containerId, Collection $participations): Collection
    {
        $participationIds = $participations
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return DB::table('pending_request')
            ->where('status', 'pending')
            ->where(function ($query) use ($containerId, $participationIds): void {
                $query->where(function ($query) use ($containerId): void {
                    $query->where('target_type', self::TARGET_TYPE)
                        ->where('target_id', $containerId)
                        ->whereIn('type', self::CONTAINER_PENDING_TYPES);
                });

                if ($participationIds !== []) {
                    $query->orWhere(function ($query) use ($participationIds): void {
                        $query->where('target_type', 'participation')
                            ->whereIn('target_id', $participationIds)
                            ->where('type', PendingType::IC_EXPEL->value);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
