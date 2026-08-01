<?php

namespace App\Livewire\Candidate;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\LookupData\Public\LookupService;

/**
 * K5 — revision diff (read-only) between the approved main candidate and its
 * revision row. The maker edits/submits the revision through the K3 form;
 * the approver reaches this screen from the K4 queue.
 */
final class RevisionDiff extends Component
{
    public int $revisionId;

    public bool $notFound = false;

    public ?object $revision = null;

    public ?object $main = null;

    public bool $activePending = false;

    /**
     * @var list<array{label: string, main: string, revision: string, changed: bool}>
     */
    public array $diffRows = [];

    /**
     * @var list<array{title: string, added: int, removed: int, changed: int}>
     */
    public array $childSummaries = [];

    public function mount(int $revisionId): void
    {
        $this->revisionId = $revisionId;

        $payload = app(CandidateQueryService::class)->revisionDiff(Auth::user(), $revisionId);

        if ($payload === null) {
            $this->notFound = true;

            return;
        }

        $this->revision = $payload['revision'];
        $this->main = $payload['main'];
        $this->activePending = $payload['activePending'];
        $this->diffRows = $this->buildMainDiff($payload['revision'], $payload['main']);
        $this->childSummaries = $this->buildChildSummaries($payload['children_revision'], $payload['children_main']);
    }

    public function render()
    {
        Gate::authorize('candidate.view');

        return view('livewire.candidate.revision-diff');
    }

    /**
     * @return list<array{label: string, main: string, revision: string, changed: bool}>
     */
    private function buildMainDiff(object $revision, object $main): array
    {
        $fields = [
            'nama_alphabet' => 'ui.candidate.field.nama_katakana',
            'nama_katakana' => 'ui.candidate.field.nama_katakana',
            'tanggal_lahir' => 'ui.candidate.field.tanggal_lahir',
            'tempat_lahir_kota_id' => 'ui.candidate.field.tempat_lahir_kota_id',
            'alamat_detail' => 'ui.candidate.field.alamat_detail',
            'email' => 'ui.candidate.field.email',
            'phone' => 'ui.candidate.field.phone',
            'line_id' => 'ui.candidate.field.line_id',
            'kewarganegaraan_id' => 'ui.candidate.field.kewarganegaraan_id',
            'asal_rekrutmen_id' => 'ui.candidate.field.asal_rekrutmen_id',
            'agama_id' => 'ui.candidate.field.agama_id',
            'alamat_provinsi_id' => 'ui.candidate.field.alamat_provinsi_id',
            'alamat_kota_kabupaten_id' => 'ui.candidate.field.alamat_kota_kabupaten_id',
            'alamat_kecamatan_id' => 'ui.candidate.field.alamat_kecamatan_id',
            'jenis_kelamin' => 'ui.candidate.field.jenis_kelamin',
            'status_pernikahan' => 'ui.candidate.field.status_pernikahan',
            'catatan_tambahan' => 'ui.candidate.field.catatan_tambahan',
        ];

        $rows = [];

        foreach ($fields as $column => $labelKey) {
            $mainValue = $this->displayValue($column, $main->{$column} ?? null);
            $revisionValue = $this->displayValue($column, $revision->{$column} ?? null);

            if ($mainValue === $revisionValue) {
                continue;
            }

            $rows[] = [
                'label' => __($labelKey),
                'main' => $mainValue,
                'revision' => $revisionValue,
                'changed' => true,
            ];
        }

        return $rows;
    }

    private function displayValue(string $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if ($column === 'jenis_kelamin') {
            return $value === 'M' ? __('ui.candidate.glyphs.male') : __('ui.candidate.glyphs.female');
        }

        if ($column === 'status_pernikahan') {
            return $value === 'MARRIED' ? __('ui.candidate.glyphs.married') : __('ui.candidate.glyphs.single');
        }

        if (str_ends_with($column, '_id')) {
            $table = match ($column) {
                'tempat_lahir_kota_id', 'alamat_kota_kabupaten_id' => 'kota_kabupaten',
                'kewarganegaraan_id' => 'negara',
                'asal_rekrutmen_id' => 'asal_rekrutmen',
                'agama_id' => 'agama',
                'alamat_provinsi_id' => 'provinsi',
                'alamat_kecamatan_id' => 'kecamatan',
                default => null,
            };

            if ($table !== null) {
                return app(LookupService::class)->labelById($table, (int) $value, app()->getLocale());
            }
        }

        if ($column === 'tanggal_lahir') {
            return Carbon::parse($value)->format(__('ui.date_time_format'));
        }

        return (string) $value;
    }

    /**
     * @param  array<string, Collection<int, object>>  $revisionChildren
     * @param  array<string, Collection<int, object>>  $mainChildren
     * @return list<array{title: string, added: int, removed: int, changed: int}>
     */
    private function buildChildSummaries(array $revisionChildren, array $mainChildren): array
    {
        $titles = [
            'candidate_education' => 'ui.candidate.section.education',
            'candidate_work' => 'ui.candidate.section.work',
            'candidate_qual_english' => 'ui.candidate.section.qual_english',
            'candidate_qual_japanese' => 'ui.candidate.section.qual_japanese',
            'candidate_qual_ssw' => 'ui.candidate.section.qual_ssw',
            'candidate_qual_driving' => 'ui.candidate.section.qual_driving',
            'candidate_qual_other' => 'ui.candidate.section.qual_other',
            'candidate_family' => 'ui.candidate.section.family',
            'candidate_family_contact' => 'ui.candidate.section.family_contact',
            'candidate_immigration' => 'ui.candidate.section.immigration',
            'candidate_physical' => 'ui.candidate.section.physical',
            'candidate_self_promo' => 'ui.candidate.section.self_promo',
            'candidate_document' => 'ui.candidate.section.document',
        ];

        $summaries = [];

        foreach ($titles as $table => $titleKey) {
            $mainRows = $mainChildren[$table] ?? collect();
            $revisionRows = $revisionChildren[$table] ?? collect();

            $mainKeyed = $mainRows->keyBy(fn (object $row): string => (string) ($row->sort_order ?? $row->id));
            $revisionKeyed = $revisionRows->keyBy(fn (object $row): string => (string) ($row->sort_order ?? $row->id));

            $added = 0;
            $removed = 0;
            $changed = 0;

            foreach ($revisionKeyed as $key => $row) {
                if (! $mainKeyed->has($key)) {
                    $added++;

                    continue;
                }

                if (json_encode((array) $mainKeyed->get($key), JSON_THROW_ON_ERROR) !== json_encode((array) $row, JSON_THROW_ON_ERROR)) {
                    $changed++;
                }
            }

            foreach ($mainKeyed as $key => $row) {
                if (! $revisionKeyed->has($key)) {
                    $removed++;
                }
            }

            if ($added + $removed + $changed > 0) {
                $summaries[] = [
                    'title' => __($titleKey),
                    'added' => $added,
                    'removed' => $removed,
                    'changed' => $changed,
                ];
            }
        }

        return $summaries;
    }
}
