<?php

namespace Modules\GuestAccess\Public;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\GuestSession;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;

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

    public function __construct(private readonly AuditLogger $audit) {}

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

    /**
     * G3 detail whitelist (PRD Lampiran C). The returned array is the only
     * representation of the candidate that may leave the module; the full
     * Candidate row is never serialized. Videos stay hidden (default OFF —
     * no per-link activation exists yet).
     *
     * @return array<string, mixed>
     *
     * @throws GuestAccessDeniedException generic denial for out-of-scope,
     *                                    anonymized, or soft-deleted candidates
     */
    public function detailForGuest(GuestSession $session, int $candidateId): array
    {
        $row = DB::table('participation as p')
            ->join('candidate as c', 'c.id', '=', 'p.candidate_id')
            ->leftJoin('negara as n', 'n.id', '=', 'c.kewarganegaraan_id')
            ->leftJoin('candidate_self_promo as sp', 'sp.candidate_id', '=', 'c.id')
            ->leftJoin('bidang_diminati as bd', 'bd.id', '=', 'sp.bidang_diminati_id')
            ->where('p.interview_container_id', $session->containerId)
            ->where('c.id', $candidateId)
            ->whereNull('c.deleted_at')
            ->whereNull('c.pii_anonymized_at')
            ->whereNull('c.parent_candidate_id')
            ->whereNotNull('c.nomor_induk')
            ->select([
                'c.id',
                'c.nomor_induk',
                'c.nama_alphabet',
                'c.nama_katakana',
                'c.jenis_kelamin',
                DB::raw('EXTRACT(YEAR FROM AGE(c.tanggal_lahir))::int as umur'),
                'n.label_ja as kewarganegaraan',
                'bd.label_ja as bidang_diminati',
            ])
            ->first();

        if ($row === null) {
            throw new GuestAccessDeniedException;
        }

        $candidateId = (int) $row->id;
        $detail = [
            'id' => $candidateId,
            'nomor_induk' => $row->nomor_induk,
            'umur' => (int) $row->umur,
            'jenis_kelamin' => $row->jenis_kelamin,
            'kewarganegaraan' => $row->kewarganegaraan,
            'bidang_diminati' => $row->bidang_diminati,
            'japanese_levels' => $this->japaneseLevels($candidateId),
            'ssw_qualifications' => $this->sswQualifications($candidateId),
            'nama_alphabet' => $row->nama_alphabet,
            'nama_katakana' => $row->nama_katakana,
            'photo_available' => DB::table('candidate_photo')->where('candidate_id', $candidateId)->exists(),
            'english_levels' => $this->englishLevels($candidateId),
            'driving_qualifications' => $this->drivingQualifications($candidateId),
            'work_history' => $this->workHistory($candidateId),
            'education_history' => $this->educationHistory($candidateId),
            'shareable_documents' => $this->shareableDocuments($candidateId),
        ];

        $ip = request()->ip();
        $this->audit->record(
            actionType: ActionType::GUEST_DETAIL_VIEWED,
            entityType: 'candidate',
            entityId: $candidateId,
            detail: [
                'token_id' => $session->linkId,
                'candidate_id' => $candidateId,
                'container_id' => $session->containerId,
                'ip' => is_string($ip) ? $ip : '',
            ],
            ip: is_string($ip) ? $ip : null,
        );

        return $detail;
    }

    /** @return list<array{jenis: string, skor: string|null}> */
    private function japaneseLevels(int $candidateId): array
    {
        return DB::table('candidate_qual_japanese as qj')
            ->join('jenis_kualifikasi_bahasa_jepang as jl', 'jl.id', '=', 'qj.jenis_id')
            ->where('qj.candidate_id', $candidateId)
            ->orderBy('qj.id')
            ->select('jl.label_ja as jenis', 'qj.skor')
            ->get()
            ->map(fn (object $row): array => ['jenis' => $row->jenis, 'skor' => $row->skor])
            ->all();
    }

    /** @return list<string> */
    private function sswQualifications(int $candidateId): array
    {
        return DB::table('candidate_qual_ssw as qs')
            ->join('skill_ssw as ss', 'ss.id', '=', 'qs.skill_ssw_id')
            ->where('qs.candidate_id', $candidateId)
            ->orderBy('qs.id')
            ->pluck('ss.label_ja')
            ->all();
    }

    /** @return list<array{jenis: string, skor: string|null}> */
    private function englishLevels(int $candidateId): array
    {
        return DB::table('candidate_qual_english as qe')
            ->join('jenis_kualifikasi_bahasa_inggris as jl', 'jl.id', '=', 'qe.jenis_id')
            ->where('qe.candidate_id', $candidateId)
            ->orderBy('qe.id')
            ->select('jl.label_ja as jenis', 'qe.skor')
            ->get()
            ->map(fn (object $row): array => ['jenis' => $row->jenis, 'skor' => $row->skor])
            ->all();
    }

    /** @return list<string> */
    private function drivingQualifications(int $candidateId): array
    {
        return DB::table('candidate_qual_driving as qd')
            ->join('kualifikasi_mengemudi as km', 'km.id', '=', 'qd.kualifikasi_mengemudi_id')
            ->where('qd.candidate_id', $candidateId)
            ->orderBy('qd.id')
            ->pluck('km.label_ja')
            ->all();
    }

    /**
     * @return list<array{
     *     nama_perusahaan: string|null,
     *     perusahaan_penanggung: string|null,
     *     bidang_pekerjaan: string|null,
     *     tanggal_masuk: string|null,
     *     tanggal_keluar: string|null,
     * }>
     */
    private function workHistory(int $candidateId): array
    {
        return DB::table('candidate_work as w')
            ->leftJoin('bidang_pekerjaan as bp', 'bp.id', '=', 'w.bidang_pekerjaan_id')
            ->where('w.candidate_id', $candidateId)
            ->orderBy('w.sort_order')
            ->orderBy('w.id')
            ->select(
                'w.nama_perusahaan',
                'w.perusahaan_penanggung',
                'bp.label_ja as bidang_pekerjaan',
                'w.tanggal_masuk',
                'w.tanggal_keluar',
            )
            ->get()
            ->map(static fn (object $row): array => [
                'nama_perusahaan' => $row->nama_perusahaan,
                'perusahaan_penanggung' => $row->perusahaan_penanggung,
                'bidang_pekerjaan' => $row->bidang_pekerjaan,
                'tanggal_masuk' => $row->tanggal_masuk,
                'tanggal_keluar' => $row->tanggal_keluar,
            ])
            ->all();
    }

    /**
     * @return list<array{
     *     jenis_pendidikan: string|null,
     *     jurusan: string|null,
     *     nama_institusi: string|null,
     *     tanggal_masuk: string|null,
     *     tanggal_keluar: string|null,
     * }>
     */
    private function educationHistory(int $candidateId): array
    {
        return DB::table('candidate_education as e')
            ->leftJoin('tingkat_pendidikan as tp', 'tp.id', '=', 'e.tingkat_pendidikan_id')
            ->leftJoin('jurusan as j', 'j.id', '=', 'e.jurusan_id')
            ->where('e.candidate_id', $candidateId)
            ->orderBy('e.sort_order')
            ->orderBy('e.id')
            ->select(
                'tp.label_ja as jenis_pendidikan',
                'j.label_ja as jurusan',
                'e.nama_institusi',
                'e.tanggal_masuk',
                'e.tanggal_keluar',
            )
            ->get()
            ->map(static fn (object $row): array => [
                'jenis_pendidikan' => $row->jenis_pendidikan,
                'jurusan' => $row->jurusan,
                'nama_institusi' => $row->nama_institusi,
                'tanggal_masuk' => $row->tanggal_masuk,
                'tanggal_keluar' => $row->tanggal_keluar,
            ])
            ->all();
    }

    /**
     * Google Drive links only for lookups flagged shareable (PRD §9.8).
     *
     * @return list<array{jenis: string, url: string}>
     */
    private function shareableDocuments(int $candidateId): array
    {
        $ssw = DB::table('candidate_qual_ssw as qs')
            ->join('skill_ssw as ss', 'ss.id', '=', 'qs.skill_ssw_id')
            ->where('qs.candidate_id', $candidateId)
            ->where('ss.is_shareable', true)
            ->whereNotNull('qs.url_file')
            ->select('ss.label_ja as jenis', 'qs.url_file as url')
            ->get();

        $other = DB::table('candidate_qual_other as qo')
            ->join('kualifikasi_keahlian_lainnya as kk', 'kk.id', '=', 'qo.kualifikasi_keahlian_lainnya_id')
            ->where('qo.candidate_id', $candidateId)
            ->where('kk.is_shareable', true)
            ->whereNotNull('qo.url_file')
            ->select('kk.label_ja as jenis', 'qo.url_file as url')
            ->get();

        return $ssw
            ->concat($other)
            ->map(static fn (object $row): array => ['jenis' => $row->jenis, 'url' => $row->url])
            ->values()
            ->all();
    }
}
