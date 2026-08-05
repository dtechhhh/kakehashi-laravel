<?php

namespace Modules\Candidates\Public;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Shared\Approval\PendingType;

/**
 * Read-only Candidate views contract (K1 list / K2 detail / Jobs pull picker).
 *
 * Authorization: `candidate.view` Gate on every call, except the Jobs pull
 * picker (UI-W4-T0) which authorizes `jobs.execute` because the Maker pulls
 * candidates from the Jobs module. Anonymized, soft-deleted, and revision rows
 * are excluded from the list; detail refuses anonymized or deleted rows. No
 * domain mutation happens here.
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
     * UI-W4-T0 — eligible pull picker for the Jobs module (W6).
     *
     * Read-only list of Disetujui + Tersedia main candidates for the Maker to
     * pull into an active interview container. The pull mutation itself stays
     * in InterviewParticipationService; this facade only lists.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function eligibleForInterviewPull(User $actor, string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('jobs.execute');

        return DB::table('candidate')
            ->whereNull('deleted_at')
            ->whereNull('pii_anonymized_at')
            ->whereNull('parent_candidate_id')
            ->whereNotNull('nomor_induk')
            ->where('status_approval', CandidateApprovalStatus::Disetujui->value)
            ->where('status_ketersediaan', CandidateAvailability::Tersedia->value)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('nama_alphabet', 'ilike', '%'.$search.'%')
                        ->orWhere('nama_katakana', 'ilike', '%'.$search.'%')
                        ->orWhere('nomor_induk', 'ilike', '%'.$search.'%');
                });
            })
            ->select('id', 'nomor_induk', 'nama_alphabet', 'nama_katakana', 'version')
            ->orderBy('nama_alphabet')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * UI-W4-T4 — Jobs pull picker display (W6).
     *
     * Disetujui main candidates with Tersedia (pullable) or Sedang Dipakai
     * (rendered disabled with a clear label). The pull service remains the
     * authority; this list is display-only.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function interviewPullPicker(User $actor, string $search = '', int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('jobs.execute');

        return DB::table('candidate')
            ->whereNull('deleted_at')
            ->whereNull('pii_anonymized_at')
            ->whereNull('parent_candidate_id')
            ->whereNotNull('nomor_induk')
            ->where('status_approval', CandidateApprovalStatus::Disetujui->value)
            ->whereIn('status_ketersediaan', [
                CandidateAvailability::Tersedia->value,
                CandidateAvailability::SedangDipakai->value,
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('nama_alphabet', 'ilike', '%'.$search.'%')
                        ->orWhere('nama_katakana', 'ilike', '%'.$search.'%')
                        ->orWhere('nomor_induk', 'ilike', '%'.$search.'%');
                });
            })
            ->select('id', 'nomor_induk', 'nama_alphabet', 'nama_katakana', 'status_ketersediaan', 'version')
            ->orderBy('nama_alphabet')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * K4 — pending review queue (Approver Kandidat). Decision source is
     * `pending_request.status`; candidate status is informational.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function reviewQueue(User $actor, string $status = 'pending', int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('candidate.review');

        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        return DB::table('pending_request')
            ->whereIn('type', [PendingType::CANDIDATE_NEW->value, PendingType::CANDIDATE_REVISION->value])
            ->where('status', $status)
            ->join('candidate', 'candidate.id', '=', 'pending_request.target_id')
            ->leftJoin('candidate as main', 'main.id', '=', 'candidate.parent_candidate_id')
            ->leftJoin('users as requester', 'requester.id', '=', 'pending_request.requested_by')
            ->orderByDesc('pending_request.created_at')
            ->orderByDesc('pending_request.id')
            ->select(
                'pending_request.id as pending_id',
                'pending_request.type as pending_type',
                'pending_request.requested_by',
                'pending_request.created_at as requested_at',
                'candidate.id as candidate_id',
                'candidate.nama_alphabet',
                'candidate.status_approval',
                'candidate.version as candidate_version',
                'candidate.parent_candidate_id',
                DB::raw('COALESCE(candidate.nomor_induk, main.nomor_induk) as nomor_induk_display'),
                'requester.name as requested_by_name',
            )
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * K5 — revision diff payload (main vs revision row + children).
     *
     * @return array{
     *     revision: object,
     *     main: object|null,
     *     children_revision: array<string, Collection<int, object>>,
     *     children_main: array<string, Collection<int, object>>,
     *     activePending: bool,
     * }|null
     */
    public function revisionDiff(User $actor, int $revisionId): ?array
    {
        Gate::forUser($actor)->authorize('candidate.view');

        $revision = DB::table('candidate')->where('id', $revisionId)->whereNull('deleted_at')->first();

        if ($revision === null || $revision->parent_candidate_id === null || $revision->pii_anonymized_at !== null) {
            return null;
        }

        $main = DB::table('candidate')->where('id', $revision->parent_candidate_id)->whereNull('deleted_at')->first();

        if ($main === null) {
            return null;
        }

        $activePending = DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $revisionId)
            ->where('status', 'pending')
            ->exists();

        return [
            'revision' => $revision,
            'main' => $main,
            'children_revision' => $this->children((int) $revisionId),
            'children_main' => $this->children((int) $main->id),
            'activePending' => $activePending,
        ];
    }

    /**
     * @return array<string, Collection<int, object>>
     */
    private function children(int $candidateId): array
    {
        $children = [];

        foreach (self::CHILD_TABLES as $table) {
            $query = DB::table($table)->where('candidate_id', $candidateId);

            if (! in_array($table, self::CHILD_TABLES_WITHOUT_SORT, true)) {
                $query->orderBy('sort_order');
            }

            $children[$table] = $query->orderBy('id')->get();
        }

        return $children;
    }

    /**
     * Full read-only payload for K2.
     *
     * @return array{
     *     candidate: object,
     *     children: array<string, Collection<int, object>>,
     *     photo: object|null,
     *     activePending: bool,
     *     isRevision: bool,
     *     openRevisionId: int|null,
     *     nomorIndukDisplay: string|null,
     * }|null
     */
    public function detail(User $actor, int $candidateId): ?array
    {
        Gate::forUser($actor)->authorize('candidate.view');

        $row = DB::table('candidate')->where('id', $candidateId)->whereNull('deleted_at')->first();

        if ($row === null || $row->pii_anonymized_at !== null) {
            return null;
        }

        $children = $this->children($candidateId);

        $photo = DB::table('candidate_photo')->where('candidate_id', $candidateId)->first();

        $activePending = DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $candidateId)
            ->where('status', 'pending')
            ->exists();

        $openRevisionId = DB::table('candidate')
            ->where('parent_candidate_id', $candidateId)
            ->whereIn('status_approval', [
                CandidateApprovalStatus::Draft->value,
                CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                CandidateApprovalStatus::Ditolak->value,
            ])
            ->whereNull('deleted_at')
            ->value('id');

        $nomorIndukDisplay = $row->nomor_induk;
        if ($row->parent_candidate_id !== null) {
            $nomorIndukDisplay = DB::table('candidate')
                ->where('id', $row->parent_candidate_id)
                ->value('nomor_induk');
        }

        return [
            'candidate' => $row,
            'children' => $children,
            'photo' => $photo,
            'activePending' => $activePending,
            'isRevision' => $row->parent_candidate_id !== null,
            'openRevisionId' => $openRevisionId !== null ? (int) $openRevisionId : null,
            'nomorIndukDisplay' => $nomorIndukDisplay !== null ? (string) $nomorIndukDisplay : null,
        ];
    }
}
