<?php

namespace Modules\GuestAccess\Public;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\GuestSession;

/**
 * W6-T4/T5 — server-side Guest read model (PRD Lampiran C).
 *
 * Only whitelisted fields leave this class; the full Candidate object is never
 * serialized. Scope always comes from the validated GuestSession container id,
 * never from client parameters. Anonymized / soft-deleted candidates are
 * excluded everywhere; direct detail of such a candidate is denied generically.
 */
final class GuestCandidateReadModel
{
    /**
     * Safe sort columns for G2. Name, photo, education institution, work
     * company and every HIDE column are deliberately NOT allowed.
     */
    private const SORTABLE = [
        'nomor_induk' => ['c.nomor_induk', 'asc'],
        'umur' => ['c.tanggal_lahir', 'asc'],
        'kewarganegaraan' => ['n.label_ja', 'asc'],
        'bidang_diminati' => ['bd.label_ja', 'asc'],
    ];

    /**
     * @param  array{sort?: string, direction?: string, page?: int}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function listForContainer(GuestSession $session, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        [$column, $defaultDirection] = self::SORTABLE[$filters['sort'] ?? ''] ?? ['c.nomor_induk', 'asc'];
        $direction = ($filters['direction'] ?? $defaultDirection) === 'desc' ? 'desc' : 'asc';
        $page = isset($filters['page']) && is_numeric($filters['page'])
            ? max(1, (int) $filters['page'])
            : null;

        $query = DB::table('participation as p')
            ->join('candidate as c', 'c.id', '=', 'p.candidate_id')
            ->leftJoin('negara as n', 'n.id', '=', 'c.kewarganegaraan_id')
            ->leftJoin('candidate_self_promo as sp', 'sp.candidate_id', '=', 'c.id')
            ->leftJoin('bidang_diminati as bd', 'bd.id', '=', 'sp.bidang_diminati_id')
            ->where('p.interview_container_id', $session->containerId)
            // Latest participation row per candidate (a candidate can re-enter a container).
            ->whereRaw(
                'p.id = (select max(p2.id) from participation p2 '
                .'where p2.interview_container_id = p.interview_container_id '
                .'and p2.candidate_id = p.candidate_id)'
            )
            ->whereNull('c.deleted_at')
            ->whereNull('c.pii_anonymized_at')
            ->whereNull('c.parent_candidate_id')
            ->whereNotNull('c.nomor_induk')
            ->select([
                'c.id',
                'c.nomor_induk',
                'c.jenis_kelamin',
                DB::raw('EXTRACT(YEAR FROM AGE(c.tanggal_lahir))::int as umur'),
                'n.label_ja as kewarganegaraan',
                'bd.label_ja as bidang_diminati',
            ])
            ->orderBy($column, $direction)
            ->orderByDesc('p.id');

        $paginator = $query->paginate(max(1, min(100, $perPage)), ['*'], 'page', $page);
        $this->attachJapaneseLevels($paginator);
        $this->attachSswQualifications($paginator);

        return $paginator;
    }

    private function attachJapaneseLevels(LengthAwarePaginator $paginator): void
    {
        $ids = $paginator->getCollection()->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $rows = DB::table('candidate_qual_japanese as qj')
            ->join('jenis_kualifikasi_bahasa_jepang as jl', 'jl.id', '=', 'qj.jenis_id')
            ->whereIn('qj.candidate_id', $ids)
            ->orderBy('qj.candidate_id')
            ->orderBy('qj.id')
            ->select('qj.candidate_id', 'jl.label_ja as jenis', 'qj.skor')
            ->get()
            ->groupBy('candidate_id');

        foreach ($paginator->items() as $item) {
            $item->japanese_levels = $rows
                ->get((int) $item->id, collect())
                ->map(fn (object $row): array => [
                    'jenis' => $row->jenis,
                    'skor' => $row->skor,
                ])
                ->values()
                ->all();
        }
    }

    private function attachSswQualifications(LengthAwarePaginator $paginator): void
    {
        $ids = $paginator->getCollection()->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $rows = DB::table('candidate_qual_ssw as qs')
            ->join('skill_ssw as ss', 'ss.id', '=', 'qs.skill_ssw_id')
            ->whereIn('qs.candidate_id', $ids)
            ->orderBy('qs.candidate_id')
            ->orderBy('qs.id')
            ->select('qs.candidate_id', 'ss.label_ja as jenis')
            ->get()
            ->groupBy('candidate_id');

        foreach ($paginator->items() as $item) {
            $item->ssw_qualifications = $rows
                ->get((int) $item->id, collect())
                ->map(fn (object $row): string => $row->jenis)
                ->values()
                ->all();
        }
    }
}
