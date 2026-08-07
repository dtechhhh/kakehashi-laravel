<?php

namespace App\Livewire\Candidate;

use App\Livewire\StepUpModal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Candidates\Services\CandidateAnonymizationService;
use Modules\Candidates\Services\CandidatePhotoService;
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\LookupData\Public\LookupService;
use Shared\Files\DocumentLinkAuditService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * K2 — read-only candidate detail.
 *
 * No edit/approve actions. Private photo is rendered only through the
 * authorized signed-URL contract; participant documents are revealed only via
 * DocumentLinkAuditService (audits IDENTITY_DOC_VIEWED). FK ids are rendered
 * as bilingual lookup labels (inactive values still resolve).
 */
final class CandidateDetail extends Component
{
    public int $candidateId;

    public bool $notFound = false;

    public ?string $photoUrl = null;

    public bool $photoMissing = false;

    public ?string $actionError = null;

    /**
     * @var array<int, array{title: string, rows: list<array{label: string, value: string}>}>
     */
    public array $sections = [];

    public ?object $candidate = null;

    public ?string $nomorIndukDisplay = null;

    public ?object $photo = null;

    public bool $activePending = false;

    public bool $isRevision = false;

    public ?int $openRevisionId = null;

    public bool $conflict = false;

    public bool $canAnonymize = false;

    public bool $pendingAnonymize = false;

    /**
     * Safe document metadata only — the private Drive URL is never exposed
     * here; it is disclosed only via DocumentLinkAuditService::revealLink.
     *
     * @var list<array{id: int, jenis_dokumen_id: int, nama_file: string|null, catatan: string|null}>
     */
    public array $documents = [];

    public function mount(int $candidateId): void
    {
        $this->candidateId = $candidateId;

        $payload = app(CandidateQueryService::class)->detail(Auth::user(), $candidateId);

        if ($payload === null) {
            $this->notFound = true;

            return;
        }

        $this->candidate = $payload['candidate'];
        $this->nomorIndukDisplay = $payload['nomorIndukDisplay'];
        $this->photo = $payload['photo'];
        $this->activePending = $payload['activePending'];
        $this->isRevision = $payload['isRevision'];
        $this->openRevisionId = $payload['openRevisionId'];
        $this->canAnonymize = $this->canAnonymize();
        $this->documents = $payload['children']['candidate_document']
            ->map(fn (object $document): array => [
                'id' => (int) $document->id,
                'jenis_dokumen_id' => (int) $document->jenis_dokumen_id,
                'nama_file' => $document->nama_file !== null ? (string) $document->nama_file : null,
                'catatan' => $document->catatan !== null ? (string) $document->catatan : null,
            ])
            ->all();
        $this->sections = $this->buildSections($payload['candidate'], $payload['children']);
    }

    public function loadPhoto(): void
    {
        if ($this->photo === null) {
            $this->photoMissing = true;

            return;
        }

        try {
            $this->photoUrl = app(CandidatePhotoService::class)
                ->temporaryUrl(Auth::user(), $this->candidateId);
        } catch (\Throwable) {
            $this->photoMissing = true;
        }
    }

    public function revealDocument(int $documentId): void
    {
        try {
            $url = app(DocumentLinkAuditService::class)->revealLink(
                $this->candidateId,
                $documentId,
                (int) Auth::id(),
            );
        } catch (\Throwable) {
            $this->actionError = __('ui.candidate.errors.DOCUMENT_REVEAL_FAILED');

            return;
        }

        $this->actionError = null;
        $this->dispatch('kakehashi-open-url', url: $url);
    }

    public function startRevision(): void
    {
        $this->actionError = null;
        $this->conflict = false;

        try {
            $revision = app(CandidateRevisionService::class)
                ->createRevision(Auth::user(), $this->candidateId, ['version' => (int) $this->candidate->version]);

            $this->redirect(route('candidate.edit', (int) $revision->id));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->actionError = collect($exception->errors())->flatten()->first() ?? __('ui.candidate.errors.DOCUMENT_REVEAL_FAILED');
        } catch (\Throwable) {
            $this->actionError = __('ui.candidate.errors.DOCUMENT_REVEAL_FAILED');
        }
    }

    /**
     * W7-T2 — anonymization is Super Admin only; step-up is demanded before
     * the tombstone service runs (StepUpService::require inside the service).
     */
    public function anonymizeCandidate(): void
    {
        $this->actionError = null;
        $this->conflict = false;
        $this->pendingAnonymize = true;

        if (app(StepUpService::class)->hasValidElevation(
            StepUpAction::ANONYMIZE_PII,
            'candidate',
            $this->candidateId,
        )) {
            $this->executeAnonymize();

            return;
        }

        $this->dispatch('stepup.open',
            action: StepUpAction::ANONYMIZE_PII,
            entityType: 'candidate',
            entityId: $this->candidateId,
        )->to(StepUpModal::class);
    }

    #[On('stepup.success')]
    public function handleStepUpSuccess(string $action, string $entityType, int $entityId): void
    {
        if (! $this->pendingAnonymize
            || $action !== StepUpAction::ANONYMIZE_PII
            || $entityType !== 'candidate'
            || $entityId !== $this->candidateId) {
            return;
        }

        $this->executeAnonymize();
    }

    private function executeAnonymize(): void
    {
        $this->pendingAnonymize = false;

        try {
            app(CandidateAnonymizationService::class)->anonymize(Auth::user(), $this->candidateId);

            $this->redirect(route('candidate.index'));
        } catch (HttpResponseException) {
            $this->actionError = __('ui.candidate.errors.STEPUP_REQUIRED');
        } catch (AuthorizationException) {
            $this->actionError = __('ui.candidate.errors.ANONYMIZE_FORBIDDEN');
        } catch (ValidationException $exception) {
            $code = collect($exception->errors())->flatten()->first();
            $this->actionError = is_string($code)
                ? (__('ui.candidate.errors.'.$code, [], app()->getLocale()) ?: $code)
                : __('ui.candidate.errors.ANONYMIZE_FAILED');
        } catch (\Throwable) {
            $this->actionError = __('ui.candidate.errors.ANONYMIZE_FAILED');
        }
    }

    private function canAnonymize(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->status_akun === 'Aktif'
            && $user->hasRole(Rbac::SUPER_ADMIN)
            && $user->hasPermissionTo('candidate.anonymize');
    }

    public function age(): int
    {
        return Carbon::parse($this->candidate->tanggal_lahir)->age;
    }

    public function glyph(string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field) {
            'jenis_kelamin' => $value === 'M' ? __('ui.candidate.glyphs.male') : __('ui.candidate.glyphs.female'),
            'status_pernikahan' => $value === 'MARRIED' ? __('ui.candidate.glyphs.married') : __('ui.candidate.glyphs.single'),
            'dominan_tangan' => $value === 'RIGHT' ? __('ui.candidate.glyphs.right') : __('ui.candidate.glyphs.left'),
            'yes_no' => $value === 'YES' ? __('ui.candidate.glyphs.yes') : __('ui.candidate.glyphs.no'),
            default => $value,
        };
    }

    public function label(string $table, ?int $id): string
    {
        return app(LookupService::class)->labelById($table, $id, app()->getLocale());
    }

    public function render()
    {
        Gate::authorize('candidate.view');

        return view('livewire.candidate.candidate-detail');
    }

    /**
     * @param  array<string, Collection<int, object>>  $children
     * @return list<array{title: string, rows: list<array{label: string, value: string}>}>
     */
    private function buildSections(object $candidate, array $children): array
    {
        $lookup = app(LookupService::class);
        $lang = app()->getLocale();
        $date = fn (?string $value): string => $value ? Carbon::parse($value)->format(__('ui.date_time_format')) : '';

        $sections = [];

        $personal = [
            ['label' => __('ui.candidate.field.nama_katakana'), 'value' => (string) ($candidate->nama_katakana ?? '')],
            ['label' => __('ui.candidate.field.tanggal_lahir'), 'value' => ($date($candidate->tanggal_lahir) ?: '').($candidate->tanggal_lahir ? ' ('.$this->age().' '.__('ui.candidate.years_old').')' : '')],
            ['label' => __('ui.candidate.field.tempat_lahir_kota_id'), 'value' => $lookup->labelById('kota_kabupaten', $candidate->tempat_lahir_kota_id, $lang)],
            ['label' => __('ui.candidate.field.kewarganegaraan_id'), 'value' => $lookup->labelById('negara', $candidate->kewarganegaraan_id, $lang)],
            ['label' => __('ui.candidate.field.asal_rekrutmen_id'), 'value' => $lookup->labelById('asal_rekrutmen', $candidate->asal_rekrutmen_id, $lang)],
            ['label' => __('ui.candidate.field.agama_id'), 'value' => $lookup->labelById('agama', $candidate->agama_id, $lang)],
            ['label' => __('ui.candidate.field.jenis_kelamin'), 'value' => $this->glyph('jenis_kelamin', $candidate->jenis_kelamin)],
            ['label' => __('ui.candidate.field.status_pernikahan'), 'value' => $this->glyph('status_pernikahan', $candidate->status_pernikahan)],
            ['label' => __('ui.candidate.field.alamat_detail'), 'value' => (string) ($candidate->alamat_detail ?? '')],
            ['label' => __('ui.candidate.field.alamat_provinsi_id'), 'value' => $lookup->labelById('provinsi', $candidate->alamat_provinsi_id, $lang)],
            ['label' => __('ui.candidate.field.alamat_kota_kabupaten_id'), 'value' => $lookup->labelById('kota_kabupaten', $candidate->alamat_kota_kabupaten_id, $lang)],
            ['label' => __('ui.candidate.field.alamat_kecamatan_id'), 'value' => $lookup->labelById('kecamatan', $candidate->alamat_kecamatan_id, $lang)],
            ['label' => __('ui.candidate.field.email'), 'value' => (string) ($candidate->email ?? '')],
            ['label' => __('ui.candidate.field.phone'), 'value' => (string) ($candidate->phone ?? '')],
            ['label' => __('ui.candidate.field.line_id'), 'value' => (string) ($candidate->line_id ?? '')],
            ['label' => __('ui.candidate.field.catatan_tambahan'), 'value' => (string) ($candidate->catatan_tambahan ?? '')],
        ];
        $sections[] = ['title' => __('ui.candidate.section.personal'), 'rows' => $this->nonEmpty($personal)];

        $physicalRows = [];
        foreach ($children['candidate_physical'] as $row) {
            foreach ([
                'tinggi_cm' => 'ui.candidate.field.tinggi_cm',
                'berat_kg' => 'ui.candidate.field.berat_kg',
                'lingkar_perut_cm' => 'ui.candidate.field.lingkar_perut_cm',
                'golongan_darah_id' => 'ui.candidate.field.golongan_darah_id',
                'ukuran_sepatu_id' => 'ui.candidate.field.ukuran_sepatu_id',
                'penglihatan_kiri_id' => 'ui.candidate.field.penglihatan_kiri_id',
                'penglihatan_kanan_id' => 'ui.candidate.field.penglihatan_kanan_id',
                'dominan_tangan' => 'ui.candidate.field.dominan_tangan',
                'buta_warna' => 'ui.candidate.field.buta_warna',
                'merokok' => 'ui.candidate.field.merokok',
                'minum_sake' => 'ui.candidate.field.minum_sake',
                'pembatasan_makanan' => 'ui.candidate.field.pembatasan_makanan',
                'riwayat_penyakit' => 'ui.candidate.field.riwayat_penyakit',
                'riwayat_operasi' => 'ui.candidate.field.riwayat_operasi',
                'catatan_kesehatan' => 'ui.candidate.field.catatan_kesehatan',
            ] as $column => $labelKey) {
                $value = $row->{$column} ?? null;
                if (in_array($column, ['buta_warna', 'merokok', 'minum_sake', 'pembatasan_makanan'], true) && $value !== null) {
                    $value = $this->glyph('yes_no', (string) $value);
                } elseif (in_array($column, ['golongan_darah_id', 'ukuran_sepatu_id', 'penglihatan_kiri_id', 'penglihatan_kanan_id'], true) && $value !== null) {
                    $value = $lookup->labelById(match ($column) {
                        'golongan_darah_id' => 'golongan_darah',
                        'ukuran_sepatu_id' => 'ukuran_sepatu',
                        default => 'tingkat_penglihatan',
                    }, (int) $value, $lang);
                } elseif ($column === 'dominan_tangan' && $value !== null) {
                    $value = $this->glyph('dominan_tangan', (string) $value);
                } elseif ($value !== null && in_array($column, ['tinggi_cm', 'berat_kg', 'lingkar_perut_cm'], true)) {
                    $value = rtrim(rtrim((string) $value, '0'), '.').' cm';
                }
                $physicalRows[] = ['label' => __($labelKey), 'value' => (string) ($value ?? '')];
            }
        }
        $sections[] = ['title' => __('ui.candidate.section.physical'), 'rows' => $this->nonEmpty($physicalRows)];

        $sections[] = ['title' => __('ui.candidate.section.education'), 'rows' => $this->childRows($children['candidate_education'], $lookup, $lang, [
            'tingkat_pendidikan_id' => ['tingkat_pendidikan', 'lookup'],
            'jurusan_id' => ['jurusan', 'lookup'],
            'nama_institusi' => [null, 'raw'],
            'tanggal_masuk' => [null, 'date'],
            'tanggal_keluar' => [null, 'date'],
        ])];

        $sections[] = ['title' => __('ui.candidate.section.work'), 'rows' => $this->childRows($children['candidate_work'], $lookup, $lang, [
            'nama_perusahaan' => [null, 'raw'],
            'perusahaan_penanggung' => [null, 'raw'],
            'bidang_pekerjaan_id' => ['bidang_pekerjaan', 'lookup'],
            'tanggal_masuk' => [null, 'date'],
            'tanggal_keluar' => [null, 'date'],
        ])];

        $qualSections = [
            ['table' => 'candidate_qual_english', 'title' => 'ui.candidate.section.qual_english', 'lookup' => 'jenis_kualifikasi_bahasa_inggris'],
            ['table' => 'candidate_qual_japanese', 'title' => 'ui.candidate.section.qual_japanese', 'lookup' => 'jenis_kualifikasi_bahasa_jepang'],
            ['table' => 'candidate_qual_ssw', 'title' => 'ui.candidate.section.qual_ssw', 'lookup' => 'skill_ssw'],
            ['table' => 'candidate_qual_driving', 'title' => 'ui.candidate.section.qual_driving', 'lookup' => 'kualifikasi_mengemudi'],
            ['table' => 'candidate_qual_other', 'title' => 'ui.candidate.section.qual_other', 'lookup' => 'kualifikasi_keahlian_lainnya'],
        ];
        foreach ($qualSections as $qual) {
            $sections[] = ['title' => __($qual['title']), 'rows' => $this->childRows($children[$qual['table']], $lookup, $lang, [
                'jenis_id' => [$qual['lookup'], 'lookup'],
                'tanggal_akuisisi' => [null, 'date'],
                'skor' => [null, 'raw'],
                'url_file' => [null, 'raw'],
            ])];
        }

        $sections[] = ['title' => __('ui.candidate.section.self_promo'), 'rows' => $this->childRows($children['candidate_self_promo'], $lookup, $lang, [
            'skor_iq' => [null, 'raw'],
            'skor_matematika' => [null, 'raw'],
            'bidang_diminati_id' => ['bidang_diminati', 'lookup'],
            'video_jikoshokai_url' => [null, 'raw'],
            'video_keahlian_url' => [null, 'raw'],
            'final_laporan_psikotes' => [null, 'raw'],
        ])];

        $sections[] = ['title' => __('ui.candidate.section.family'), 'rows' => $this->childRows($children['candidate_family'], $lookup, $lang, [
            'status_keluarga_id' => ['status_keluarga', 'lookup'],
            'nama' => [null, 'raw'],
            'tanggal_lahir' => [null, 'date'],
        ])];

        $sections[] = ['title' => __('ui.candidate.section.family_contact'), 'rows' => $this->childRows($children['candidate_family_contact'], $lookup, $lang, [
            'status_keluarga_id' => ['status_keluarga', 'lookup'],
            'nama' => [null, 'raw'],
            'phone' => [null, 'raw'],
            'alamat' => [null, 'raw'],
        ])];

        $sections[] = ['title' => __('ui.candidate.section.immigration'), 'rows' => $this->childRows($children['candidate_immigration'], $lookup, $lang, [
            'nomor_paspor' => [null, 'raw'],
            'masa_berlaku_paspor' => [null, 'date'],
            'nomor_zairyu' => [null, 'raw'],
            'alamat_zairyu' => [null, 'raw'],
            'jenis_visa_id' => ['jenis_visa', 'lookup'],
            'pernah_ke_jepang' => [null, 'yesno'],
            'catatan' => [null, 'raw'],
        ])];

        return array_values(array_filter($sections, fn (array $section): bool => $section['rows'] !== []));
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, array{0: string|null, 1: string}>  $fields
     * @return list<array{label: string, value: string}>
     */
    private function childRows($rows, LookupService $lookup, string $lang, array $fields): array
    {
        $date = fn (?string $value): string => $value ? Carbon::parse($value)->format(__('ui.date_time_format')) : '';
        $out = [];

        foreach ($rows as $row) {
            foreach ($fields as $column => [$lookupTable, $kind]) {
                $value = $row->{$column} ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $rendered = match ($kind) {
                    'lookup' => $lookup->labelById($lookupTable, (int) $value, $lang),
                    'date' => $date((string) $value),
                    'yesno' => $this->glyph('yes_no', (string) $value),
                    default => (string) $value,
                };

                $out[] = ['label' => __('ui.candidate.field.'.$column), 'value' => $rendered];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{label: string, value: string}>  $rows
     * @return list<array{label: string, value: string}>
     */
    private function nonEmpty(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $row['value'] !== ''));
    }
}
