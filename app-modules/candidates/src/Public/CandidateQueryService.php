<?php

namespace Modules\Candidates\Public;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;

/**
 * Read-only Candidate views contract (K1 list / K2 detail).
 *
 * Authorization: `candidate.view` Gate on every call. Anonymized, soft-deleted,
 * and revision rows are excluded from the list; detail refuses anonymized or
 * deleted rows. No domain mutation happens here.
 */
final class CandidateQueryService
{
    private const CHILD_TABLES = [
        'candidate_physical',
        'candidate_education',
        'candidate_work',
        'candidate_qual_english',
        'candidate_qual_japanese',
        'candidate_qual_ssw',
        'candidate_qual_driving',
        'candidate_qual_other',
        'candidate_self_promo',
        'candidate_family',
        'candidate_family_contact',
        'candidate_immigration',
        'candidate_document',
    ];

    /** Child tables without a sort_order column (1:1 or single-qual rows). */
    private const CHILD_TABLES_WITHOUT_SORT = [
        'candidate_physical',
        'candidate_qual_english',
        'candidate_qual_japanese',
        'candidate_qual_ssw',
        'candidate_qual_driving',
        'candidate_qual_other',
        'candidate_self_promo',
        'candidate_family_contact',
        'candidate_immigration',
    ];

    private const SORTABLE = [
        'nomor_induk' => 'nomor_induk',
        'nama' => 'nama_alphabet',
        'umur' => 'tanggal_lahir',
        'status_approval' => 'status_approval',
        'status_ketersediaan' => 'status_ketersediaan',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    /**
     * @param  array{
     *     search?: string,
     *     status_approval?: string,
     *     status_ketersediaan?: string,
     *     age_from?: int,
     *     age_to?: int,
     *     sort?: string,
     *     direction?: string,
     * }  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('candidate.view');

        $column = self::SORTABLE[$filters['sort'] ?? ''] ?? 'updated_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $approvalStatuses = array_map(
            static fn (CandidateApprovalStatus $status): string => $status->value,
            CandidateApprovalStatus::cases(),
        );
        $availabilityStatuses = array_map(
            static fn (CandidateAvailability $status): string => $status->value,
            CandidateAvailability::cases(),
        );

        return DB::table('candidate')
            ->whereNull('deleted_at')
            ->whereNull('pii_anonymized_at')
            ->whereNull('parent_candidate_id')
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('nama_alphabet', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('nama_katakana', 'ilike', '%'.$filters['search'].'%');
                });
            })
            ->when(
                isset($filters['status_approval']) && in_array($filters['status_approval'], $approvalStatuses, true),
                fn ($query) => $query->where('status_approval', $filters['status_approval']),
            )
            ->when(
                isset($filters['status_ketersediaan']) && in_array($filters['status_ketersediaan'], $availabilityStatuses, true),
                fn ($query) => $query->where('status_ketersediaan', $filters['status_ketersediaan']),
            )
            ->when(isset($filters['age_from']), fn ($query) => $query->whereRaw('EXTRACT(YEAR FROM AGE(tanggal_lahir)) >= ?', [(int) $filters['age_from']]))
            ->when(isset($filters['age_to']), fn ($query) => $query->whereRaw('EXTRACT(YEAR FROM AGE(tanggal_lahir)) <= ?', [(int) $filters['age_to']]))
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Full read-only payload for K2.
     *
     * @return array{
     *     candidate: object,
     *     children: array<string, Collection<int, object>>,
     *     photo: object|null,
     *     activePending: bool,
     * }|null
     */
    public function detail(User $actor, int $candidateId): ?array
    {
        Gate::forUser($actor)->authorize('candidate.view');

        $row = DB::table('candidate')->where('id', $candidateId)->whereNull('deleted_at')->first();

        if ($row === null || $row->pii_anonymized_at !== null) {
            return null;
        }

        $children = [];

        foreach (self::CHILD_TABLES as $table) {
            $query = DB::table($table)->where('candidate_id', $candidateId);

            if (! in_array($table, self::CHILD_TABLES_WITHOUT_SORT, true)) {
                $query->orderBy('sort_order');
            }

            $children[$table] = $query->orderBy('id')->get();
        }

        $photo = DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();

        $activePending = DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $candidateId)
            ->where('status', 'pending')
            ->exists();

        return [
            'candidate' => $row,
            'children' => $children,
            'photo' => $photo,
            'activePending' => $activePending,
        ];
    }
}
