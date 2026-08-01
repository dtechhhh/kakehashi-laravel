<?php

namespace App\Livewire\Candidate;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Candidates\Exceptions\SimilarityConfirmationRequired;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidatePhotoService;
use Modules\Candidates\Services\CandidateSubmitService;
use Modules\LookupData\Public\LookupRequestService;
use Modules\LookupData\Public\LookupService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * K3 — candidate create/edit form (Staf Input, Maker).
 *
 * Draft saves never produce NIK or pending. Submit goes through the existing
 * Candidates service; NIK and the >= 0.4 similarity soft warning are rendered
 * from the server response (never computed in the browser). Optimistic
 * `version` is sent with every write; a stale version surfaces as a 409
 * conflict banner with reload. Photos use the private R2 contract; documents
 * are private Google Drive URLs only.
 */
final class CandidateForm extends Component
{
    use WithFileUploads;

    // Mode / locking
    public int $candidateId = 0;

    public int $version = 0;

    public bool $isEditing = false;

    public bool $readonly = false;

    public bool $conflict = false;

    // Main fields
    public string $formNamaAlphabet = '';

    public string $formNamaKatakana = '';

    public string $formTanggalLahir = '';

    public string $formTempatLahirKotaId = '';

    public string $formAlamatDetail = '';

    public string $formEmail = '';

    public string $formPhone = '';

    public string $formLineId = '';

    public string $formKewarganegaraanId = '';

    public string $formAsalRekrutmenId = '';

    public string $formAgamaId = '';

    public string $formAlamatProvinsiId = '';

    public string $formAlamatKotaKabupatenId = '';

    public string $formAlamatKecamatanId = '';

    public string $formJenisKelamin = '';

    public string $formStatusPernikahan = '';

    public string $formCatatanTambahan = '';

    // Physical (1:1)
    public string $formTinggiCm = '';

    public string $formBeratKg = '';

    public string $formLingkarPerutCm = '';

    public string $formGolonganDarahId = '';

    public string $formUkuranSepatuId = '';

    public string $formPenglihatanKiriId = '';

    public string $formPenglihatanKananId = '';

    public string $formDominanTangan = '';

    public string $formButaWarna = '';

    public string $formMerokok = '';

    public string $formMinumSake = '';

    public string $formPembatasanMakanan = '';

    public string $formRiwayatPenyakit = '';

    public string $formRiwayatOperasi = '';

    public string $formCatatanKesehatan = '';

    // Repeatable children
    public array $education = [];

    public array $work = [];

    public array $qualEnglish = [];

    public array $qualJapanese = [];

    public array $qualSsw = [];

    public array $qualDriving = [];

    public array $qualOther = [];

    public array $family = [];

    public array $documents = [];

    // Self promo (1:1)
    public string $formSkorIq = '';

    public string $formSkorMatematika = '';

    public string $formBidangDiminatiId = '';

    public string $formVideoJikoshokaiUrl = '';

    public string $formVideoKeahlianUrl = '';

    public string $formFinalLaporanPsikotes = '';

    // Family contact (1:1)
    public string $formKontakStatusKeluargaId = '';

    public string $formKontakNama = '';

    public string $formKontakPhone = '';

    public string $formKontakAlamat = '';

    // Immigration (1:1)
    public string $formNomorPaspor = '';

    public string $formMasaBerlakuPaspor = '';

    public string $formNomorZairyu = '';

    public string $formAlamatZairyu = '';

    public string $formJenisVisaId = '';

    public string $formPernahKeJepang = '';

    public string $formCatatanImigrasi = '';

    // Photo
    public $photoFile = null;

    public ?string $photoUrl = null;

    public ?string $photoError = null;

    public ?string $photoStatus = null;

    // Similarity soft warning (server-provided)
    public ?array $similarityMatches = null;

    public bool $confirmSimilarity = false;

    // Inline lookup request
    public ?string $lookupTable = null;

    public string $lookupLabelId = '';

    public string $lookupLabelJa = '';

    public string $lookupReason = '';

    public string $lookupStatus = '';

    public bool $lookupRequested = false;

    // Errors
    public array $serverErrors = [];

    public ?string $actionError = null;

    public function mount(?int $candidate = null): void
    {
        if ($candidate !== null) {
            $this->candidateId = $candidate;
            $this->isEditing = true;

            $payload = app(CandidateQueryService::class)->detail(Auth::user(), $candidate);

            if ($payload === null) {
                $this->readonly = true;
                $this->actionError = __('ui.form.errors.NOT_FOUND');

                return;
            }

            $row = $payload['candidate'];

            if (! in_array($row->status_approval, ['Draft', 'Ditolak'], true)) {
                $this->readonly = true;
            }

            $this->version = (int) $row->version;
            $this->prefill($row, $payload['children']);
        }
    }

    public function render()
    {
        Gate::authorize('candidate.create');

        $lookup = app(LookupService::class);
        $lang = app()->getLocale();

        return view('livewire.candidate.candidate-form', [
            'options' => fn (string $table): array => $lookup->optionsById($table, $lang),
            'lookupRequestTables' => [
                'negara' => __('ui.form.lookup_tables.negara'),
                'asal_rekrutmen' => __('ui.form.lookup_tables.asal_rekrutmen'),
                'agama' => __('ui.form.lookup_tables.agama'),
                'provinsi' => __('ui.form.lookup_tables.provinsi'),
                'kota_kabupaten' => __('ui.form.lookup_tables.kota_kabupaten'),
                'kecamatan' => __('ui.form.lookup_tables.kecamatan'),
            ],
        ]);
    }

    // ----- Repeatable rows -----

    public function addRow(string $key): void
    {
        $empty = match ($key) {
            'education' => ['tingkat_pendidikan_id' => '', 'jurusan_id' => '', 'nama_institusi' => '', 'tanggal_masuk' => '', 'tanggal_keluar' => ''],
            'work' => ['nama_perusahaan' => '', 'perusahaan_penanggung' => '', 'bidang_pekerjaan_id' => '', 'tanggal_masuk' => '', 'tanggal_keluar' => ''],
            'qual_english', 'qual_japanese' => ['jenis_id' => '', 'tanggal_akuisisi' => '', 'skor' => '', 'url_file' => ''],
            'qual_ssw' => ['skill_ssw_id' => '', 'tanggal_akuisisi' => '', 'url_file' => ''],
            'qual_driving' => ['kualifikasi_mengemudi_id' => '', 'tanggal_akuisisi' => ''],
            'qual_other' => ['kualifikasi_keahlian_lainnya_id' => '', 'tanggal_akuisisi' => '', 'url_file' => ''],
            'family' => ['status_keluarga_id' => '', 'nama' => '', 'tanggal_lahir' => ''],
            'documents' => ['jenis_dokumen_id' => '', 'url_dokumen' => '', 'nama_file' => '', 'catatan' => ''],
            default => [],
        };

        $prop = $this->collectionFor($key);
        $this->{$prop}[] = $empty;
        $this->clearActionState();
    }

    public function removeRow(string $key, int $index): void
    {
        $prop = $this->collectionFor($key);
        unset($this->{$prop}[$index]);
        $this->{$prop} = array_values($this->{$prop});
        $this->clearActionState();
    }

    // ----- Save / submit -----

    public function saveDraft(): void
    {
        $this->clearActionState();

        $payload = $this->buildPayload();

        try {
            $service = app(CandidateDraftService::class);

            $row = $this->isEditing
                ? $service->updateDraft(Auth::user(), $this->candidateId, $payload + ['version' => $this->version])
                : $service->createDraft(Auth::user(), $payload);

            $this->candidateId = (int) $row->id;
            $this->isEditing = true;
            $this->version = (int) $row->version;
            $this->serverErrors = [];

            session()->flash('status', __('ui.form.saved'));

            $this->redirect(route('candidate.show', $this->candidateId));
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function submitCandidate(): void
    {
        $this->actionError = null;
        $this->serverErrors = [];
        $this->similarityMatches = null;

        if (! $this->isEditing) {
            $this->saveDraft();

            if ($this->conflict || $this->serverErrors !== []) {
                return;
            }
        }

        try {
            $row = app(CandidateSubmitService::class)->submit(Auth::user(), $this->candidateId, [
                'version' => $this->version,
                'confirm_similarity' => $this->confirmSimilarity,
            ]);

            $this->redirect(route('candidate.show', (int) $row->id));
        } catch (SimilarityConfirmationRequired $exception) {
            $this->similarityMatches = array_map(
                static function (mixed $match): array {
                    $match = (object) $match;

                    return [
                        'candidate_id' => (int) ($match->candidate_id ?? 0),
                        'nomor_induk' => (string) ($match->nomor_induk ?? ''),
                        'score' => round((float) ($match->score ?? 0), 3),
                    ];
                },
                $exception->matches,
            );
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (ValidationException $exception) {
            $this->mapValidation($exception);
        }
    }

    public function confirmSimilarityAndSubmit(): void
    {
        $this->confirmSimilarity = true;
        $this->submitCandidate();
    }

    // ----- Photo -----

    public function updatedPhotoFile(): void
    {
        $this->clearActionState();

        if ($this->photoFile === null) {
            return;
        }

        if (! $this->isEditing) {
            $this->photoError = __('ui.form.photo_save_first');

            return;
        }

        try {
            $row = app(CandidatePhotoService::class)->store(
                Auth::user(),
                $this->candidateId,
                $this->photoFile,
                $this->version,
            );

            $payload = app(CandidateQueryService::class)->detail(Auth::user(), $this->candidateId);
            $this->version = (int) ($payload['candidate']->version ?? 0);
            $this->photoError = null;
            $this->photoStatus = __('ui.form.photo_uploaded');
            $this->photoUrl = app(CandidatePhotoService::class)->temporaryUrl(Auth::user(), $this->candidateId);
        } catch (ValidationException $exception) {
            $this->photoStatus = null;
            $this->photoError = collect($exception->errors())->flatten()->first() ?? __('ui.form.photo_failed');
        } catch (ConflictHttpException) {
            $this->conflict = true;
        } catch (\Throwable) {
            $this->photoStatus = null;
            $this->photoError = __('ui.form.photo_failed');
        }
    }

    // ----- Inline lookup request -----

    public function openLookupRequest(string $table): void
    {
        $this->lookupTable = $table;
        $this->lookupLabelId = '';
        $this->lookupLabelJa = '';
        $this->lookupReason = '';
        $this->lookupStatus = '';
        $this->lookupRequested = false;
        $this->clearActionState();
    }

    public function closeLookupRequest(): void
    {
        $this->lookupTable = null;
        $this->lookupRequested = false;
    }

    public function submitLookupRequest(): void
    {
        if ($this->lookupTable === null) {
            return;
        }

        try {
            app(LookupRequestService::class)->submitLookup(Auth::user(), [
                'lookup_table' => $this->lookupTable,
                'code' => $this->lookupCode(),
                'label_id' => $this->lookupLabelId,
                'label_ja' => $this->lookupLabelJa,
                'reason' => $this->lookupReason,
            ]);

            $this->lookupRequested = true;
            $this->lookupStatus = '';
            $this->lookupLabelId = '';
            $this->lookupLabelJa = '';
            $this->lookupReason = '';
        } catch (ValidationException $exception) {
            $this->lookupRequested = false;
            $this->lookupStatus = collect($exception->errors())->flatten()->first() ?? __('ui.form.lookup_failed');
        }
    }

    private function lookupCode(): string
    {
        $base = strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($this->lookupLabelId)) ?? '');

        if ($base === '') {
            return 'NEW';
        }

        return match ($this->lookupTable) {
            'negara' => mb_substr($base, 0, 2),
            'bahasa' => strtolower(mb_substr($base, 0, 2)),
            default => mb_substr($base, 0, 64),
        };
    }

    // ----- Payload building -----

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $main = [
            'nama_alphabet' => $this->formNamaAlphabet,
            'nama_katakana' => $this->formNamaKatakana ?: null,
            'tanggal_lahir' => $this->formTanggalLahir ?: null,
            'tempat_lahir_kota_id' => $this->intOrNull($this->formTempatLahirKotaId),
            'alamat_detail' => $this->formAlamatDetail ?: null,
            'email' => $this->formEmail ?: null,
            'phone' => $this->formPhone ?: null,
            'line_id' => $this->formLineId ?: null,
            'kewarganegaraan_id' => $this->intOrNull($this->formKewarganegaraanId),
            'asal_rekrutmen_id' => $this->intOrNull($this->formAsalRekrutmenId),
            'agama_id' => $this->intOrNull($this->formAgamaId),
            'alamat_provinsi_id' => $this->intOrNull($this->formAlamatProvinsiId),
            'alamat_kota_kabupaten_id' => $this->intOrNull($this->formAlamatKotaKabupatenId),
            'alamat_kecamatan_id' => $this->intOrNull($this->formAlamatKecamatanId),
            'jenis_kelamin' => $this->formJenisKelamin ?: null,
            'status_pernikahan' => $this->formStatusPernikahan ?: null,
            'catatan_tambahan' => $this->formCatatanTambahan ?: null,
        ];

        $children = [
            'physical' => $this->nonEmpty([
                'tinggi_cm' => $this->formTinggiCm !== '' ? (float) $this->formTinggiCm : null,
                'berat_kg' => $this->formBeratKg !== '' ? (float) $this->formBeratKg : null,
                'lingkar_perut_cm' => $this->formLingkarPerutCm !== '' ? (float) $this->formLingkarPerutCm : null,
                'golongan_darah_id' => $this->intOrNull($this->formGolonganDarahId),
                'ukuran_sepatu_id' => $this->intOrNull($this->formUkuranSepatuId),
                'penglihatan_kiri_id' => $this->intOrNull($this->formPenglihatanKiriId),
                'penglihatan_kanan_id' => $this->intOrNull($this->formPenglihatanKananId),
                'dominan_tangan' => $this->formDominanTangan ?: null,
                'buta_warna' => $this->formButaWarna ?: null,
                'merokok' => $this->formMerokok ?: null,
                'minum_sake' => $this->formMinumSake ?: null,
                'pembatasan_makanan' => $this->formPembatasanMakanan ?: null,
                'riwayat_penyakit' => $this->formRiwayatPenyakit ?: null,
                'riwayat_operasi' => $this->formRiwayatOperasi ?: null,
                'catatan_kesehatan' => $this->formCatatanKesehatan ?: null,
            ]),
            'self_promo' => $this->nonEmpty([
                'skor_iq' => $this->formSkorIq ?: null,
                'skor_matematika' => $this->formSkorMatematika ?: null,
                'bidang_diminati_id' => $this->intOrNull($this->formBidangDiminatiId),
                'video_jikoshokai_url' => $this->formVideoJikoshokaiUrl ?: null,
                'video_keahlian_url' => $this->formVideoKeahlianUrl ?: null,
                'final_laporan_psikotes' => $this->formFinalLaporanPsikotes ?: null,
            ]),
            'family_contact' => $this->nonEmpty([
                'status_keluarga_id' => $this->intOrNull($this->formKontakStatusKeluargaId),
                'nama' => $this->formKontakNama ?: null,
                'phone' => $this->formKontakPhone ?: null,
                'alamat' => $this->formKontakAlamat ?: null,
            ]),
            'immigration' => $this->nonEmpty([
                'nomor_paspor' => $this->formNomorPaspor ?: null,
                'masa_berlaku_paspor' => $this->formMasaBerlakuPaspor ?: null,
                'nomor_zairyu' => $this->formNomorZairyu ?: null,
                'alamat_zairyu' => $this->formAlamatZairyu ?: null,
                'jenis_visa_id' => $this->intOrNull($this->formJenisVisaId),
                'pernah_ke_jepang' => $this->formPernahKeJepang ?: null,
                'catatan' => $this->formCatatanImigrasi ?: null,
            ]),
            'education' => $this->rows($this->education, ['tingkat_pendidikan_id', 'jurusan_id', 'nama_institusi', 'tanggal_masuk', 'tanggal_keluar']),
            'work' => $this->rows($this->work, ['nama_perusahaan', 'perusahaan_penanggung', 'bidang_pekerjaan_id', 'tanggal_masuk', 'tanggal_keluar']),
            'qual_english' => $this->rows($this->qualEnglish, ['jenis_id', 'tanggal_akuisisi', 'skor', 'url_file']),
            'qual_japanese' => $this->rows($this->qualJapanese, ['jenis_id', 'tanggal_akuisisi', 'skor', 'url_file']),
            'qual_ssw' => $this->rows($this->qualSsw, ['skill_ssw_id', 'tanggal_akuisisi', 'url_file']),
            'qual_driving' => $this->rows($this->qualDriving, ['kualifikasi_mengemudi_id', 'tanggal_akuisisi']),
            'qual_other' => $this->rows($this->qualOther, ['kualifikasi_keahlian_lainnya_id', 'tanggal_akuisisi', 'url_file']),
            'family' => $this->rows($this->family, ['status_keluarga_id', 'nama', 'tanggal_lahir']),
            'documents' => $this->rows($this->documents, ['jenis_dokumen_id', 'url_dokumen', 'nama_file', 'catatan']),
        ];

        return $main + array_filter($children, fn (array $value): bool => $value !== []);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, array $fields): array
    {
        $out = [];

        foreach ($rows as $index => $row) {
            $clean = [];

            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (is_string($value)) {
                    $value = trim($value);
                }
                if ($value === '') {
                    $clean[$field] = null;
                } else {
                    $clean[$field] = $value;
                }
            }

            if (array_filter($clean, fn (mixed $value): bool => $value !== null) !== []) {
                $clean['sort_order'] = $index + 1;
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function nonEmpty(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function intOrNull(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }

    // ----- Prefill (edit) -----

    /**
     * @param  array<string, Collection<int, object>>  $children
     */
    private function prefill(object $row, array $children): void
    {
        $this->formNamaAlphabet = (string) $row->nama_alphabet;
        $this->formNamaKatakana = (string) ($row->nama_katakana ?? '');
        $this->formTanggalLahir = $row->tanggal_lahir ? date('Y-m-d', strtotime($row->tanggal_lahir)) : '';
        $this->formTempatLahirKotaId = (string) ($row->tempat_lahir_kota_id ?? '');
        $this->formAlamatDetail = (string) ($row->alamat_detail ?? '');
        $this->formEmail = (string) ($row->email ?? '');
        $this->formPhone = (string) ($row->phone ?? '');
        $this->formLineId = (string) ($row->line_id ?? '');
        $this->formKewarganegaraanId = (string) ($row->kewarganegaraan_id ?? '');
        $this->formAsalRekrutmenId = (string) ($row->asal_rekrutmen_id ?? '');
        $this->formAgamaId = (string) ($row->agama_id ?? '');
        $this->formAlamatProvinsiId = (string) ($row->alamat_provinsi_id ?? '');
        $this->formAlamatKotaKabupatenId = (string) ($row->alamat_kota_kabupaten_id ?? '');
        $this->formAlamatKecamatanId = (string) ($row->alamat_kecamatan_id ?? '');
        $this->formJenisKelamin = (string) ($row->jenis_kelamin ?? '');
        $this->formStatusPernikahan = (string) ($row->status_pernikahan ?? '');
        $this->formCatatanTambahan = (string) ($row->catatan_tambahan ?? '');

        $this->prefillSingle($children['candidate_physical'], [
            'tinggi_cm' => 'formTinggiCm', 'berat_kg' => 'formBeratKg', 'lingkar_perut_cm' => 'formLingkarPerutCm',
            'golongan_darah_id' => 'formGolonganDarahId', 'ukuran_sepatu_id' => 'formUkuranSepatuId',
            'penglihatan_kiri_id' => 'formPenglihatanKiriId', 'penglihatan_kanan_id' => 'formPenglihatanKananId',
            'dominan_tangan' => 'formDominanTangan', 'buta_warna' => 'formButaWarna', 'merokok' => 'formMerokok',
            'minum_sake' => 'formMinumSake', 'pembatasan_makanan' => 'formPembatasanMakanan',
            'riwayat_penyakit' => 'formRiwayatPenyakit', 'riwayat_operasi' => 'formRiwayatOperasi',
            'catatan_kesehatan' => 'formCatatanKesehatan',
        ]);
        $this->prefillSingle($children['candidate_self_promo'], [
            'skor_iq' => 'formSkorIq', 'skor_matematika' => 'formSkorMatematika',
            'bidang_diminati_id' => 'formBidangDiminatiId', 'video_jikoshokai_url' => 'formVideoJikoshokaiUrl',
            'video_keahlian_url' => 'formVideoKeahlianUrl', 'final_laporan_psikotes' => 'formFinalLaporanPsikotes',
        ]);
        $this->prefillSingle($children['candidate_family_contact'], [
            'status_keluarga_id' => 'formKontakStatusKeluargaId', 'nama' => 'formKontakNama',
            'phone' => 'formKontakPhone', 'alamat' => 'formKontakAlamat',
        ]);
        $this->prefillSingle($children['candidate_immigration'], [
            'nomor_paspor' => 'formNomorPaspor', 'masa_berlaku_paspor' => 'formMasaBerlakuPaspor',
            'nomor_zairyu' => 'formNomorZairyu', 'alamat_zairyu' => 'formAlamatZairyu',
            'jenis_visa_id' => 'formJenisVisaId', 'pernah_ke_jepang' => 'formPernahKeJepang',
            'catatan' => 'formCatatanImigrasi',
        ]);

        $this->education = $this->prefillRows($children['candidate_education'], ['tingkat_pendidikan_id', 'jurusan_id', 'nama_institusi', 'tanggal_masuk', 'tanggal_keluar']);
        $this->work = $this->prefillRows($children['candidate_work'], ['nama_perusahaan', 'perusahaan_penanggung', 'bidang_pekerjaan_id', 'tanggal_masuk', 'tanggal_keluar']);
        $this->qualEnglish = $this->prefillRows($children['candidate_qual_english'], ['jenis_id', 'tanggal_akuisisi', 'skor', 'url_file']);
        $this->qualJapanese = $this->prefillRows($children['candidate_qual_japanese'], ['jenis_id', 'tanggal_akuisisi', 'skor', 'url_file']);
        $this->qualSsw = $this->prefillRows($children['candidate_qual_ssw'], ['skill_ssw_id', 'tanggal_akuisisi', 'url_file']);
        $this->qualDriving = $this->prefillRows($children['candidate_qual_driving'], ['kualifikasi_mengemudi_id', 'tanggal_akuisisi']);
        $this->qualOther = $this->prefillRows($children['candidate_qual_other'], ['kualifikasi_keahlian_lainnya_id', 'tanggal_akuisisi', 'url_file']);
        $this->family = $this->prefillRows($children['candidate_family'], ['status_keluarga_id', 'nama', 'tanggal_lahir']);
        $this->documents = $this->prefillRows($children['candidate_document'], ['jenis_dokumen_id', 'url_dokumen', 'nama_file', 'catatan']);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<string, string>  $map
     */
    private function prefillSingle($rows, array $map): void
    {
        $row = $rows->first();
        if ($row === null) {
            return;
        }

        foreach ($map as $column => $prop) {
            $value = $row->{$column} ?? '';
            if ($value !== null && $value !== '') {
                $this->{$prop} = (string) $value;
            }
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    private function prefillRows($rows, array $fields): array
    {
        return $rows
            ->map(fn (object $row): array => collect($fields)
                ->mapWithKeys(fn (string $field): array => [
                    $field => $row->{$field} === null ? '' : (string) $row->{$field},
                ])
                ->all())
            ->values()
            ->all();
    }

    // ----- Helpers -----

    private function collectionFor(string $key): string
    {
        return match ($key) {
            'education' => 'education',
            'work' => 'work',
            'qual_english' => 'qualEnglish',
            'qual_japanese' => 'qualJapanese',
            'qual_ssw' => 'qualSsw',
            'qual_driving' => 'qualDriving',
            'qual_other' => 'qualOther',
            'family' => 'family',
            'documents' => 'documents',
            default => 'education',
        };
    }

    private function mapValidation(ValidationException $exception): void
    {
        $this->serverErrors = [];

        foreach ($exception->errors() as $field => $messages) {
            $this->serverErrors[$field] = collect($messages)->first();
        }

        $this->actionError = __('ui.form.validation_failed');
    }

    private function clearActionState(): void
    {
        $this->actionError = null;
        $this->serverErrors = [];
        $this->similarityMatches = null;
        $this->confirmSimilarity = false;
    }

    /**
     * @return array<string, string>
     */
    public function lookupTables(): array
    {
        return [
            'negara' => __('ui.form.lookup_tables.negara'),
            'asal_rekrutmen' => __('ui.form.lookup_tables.asal_rekrutmen'),
            'agama' => __('ui.form.lookup_tables.agama'),
            'provinsi' => __('ui.form.lookup_tables.provinsi'),
            'kota_kabupaten' => __('ui.form.lookup_tables.kota_kabupaten'),
            'kecamatan' => __('ui.form.lookup_tables.kecamatan'),
        ];
    }
}
