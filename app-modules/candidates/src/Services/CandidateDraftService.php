<?php

namespace Modules\Candidates\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Enums\DominantHand;
use Modules\Candidates\Enums\Gender;
use Modules\Candidates\Enums\MaritalStatus;
use Modules\Candidates\Enums\YesNo;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * W3-T2 — create/update draft kandidat tanpa NIK dan tanpa pending.
 *
 * Juga mengedit baris revision Draft/Ditolak (W3-T5). Submit revision terpisah.
 */
final class CandidateDraftService
{
    private const MAIN_FIELDS = [
        'nama_alphabet',
        'nama_katakana',
        'tanggal_lahir',
        'tempat_lahir_kota_id',
        'alamat_detail',
        'email',
        'phone',
        'line_id',
        'kewarganegaraan_id',
        'asal_rekrutmen_id',
        'agama_id',
        'alamat_provinsi_id',
        'alamat_kota_kabupaten_id',
        'alamat_kecamatan_id',
        'jenis_kelamin',
        'status_pernikahan',
        'catatan_tambahan',
    ];

    private const SYSTEM_FIELDS = [
        'id',
        'nomor_induk',
        'status_ketersediaan',
        'status_approval',
        'parent_candidate_id',
        'version',
        'created_by',
        'approved_by',
        'catatan_penolakan_terakhir',
        'deleted_at',
        'pii_anonymized_at',
        'created_at',
        'updated_at',
    ];

    private const CHILD_KEYS = [
        'physical',
        'education',
        'work',
        'qual_english',
        'qual_japanese',
        'qual_ssw',
        'qual_driving',
        'qual_other',
        'self_promo',
        'family',
        'family_contact',
        'immigration',
        'documents',
    ];

    private const CHILD_LIMITS = [
        'education' => 5,
        'work' => 5,
        'qual_ssw' => 8,
        'qual_driving' => 5,
        'qual_other' => 5,
        'family' => 10,
    ];

    /** HTTPS hosts for dokumen peserta + sertifikat (Google Drive privat). */
    private const DRIVE_HOSTS = [
        'drive.google.com',
        'docs.google.com',
    ];

    /** HTTPS hosts for video embed (Drive + YouTube). */
    private const VIDEO_HOSTS = [
        'drive.google.com',
        'docs.google.com',
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(User $actor, array $payload): object
    {
        return DB::transaction(function () use ($actor, $payload): object {
            $this->authorizeCreate($actor);
            [$main, $children] = $this->validated($payload, creating: true);

            $id = DB::table('candidate')->insertGetId([
                ...$main,
                'nomor_induk' => null,
                'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                'status_approval' => CandidateApprovalStatus::Draft->value,
                'parent_candidate_id' => null,
                'version' => 0,
                'created_by' => $actor->getKey(),
                'approved_by' => null,
                'catatan_penolakan_terakhir' => null,
                'deleted_at' => null,
                'pii_anonymized_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncChildren($id, $children, $actor->getKey());

            $this->audit->record(
                actionType: ActionType::CANDIDATE_CREATED,
                entityType: 'candidate',
                entityId: $id,
                detail: [
                    'status_approval' => CandidateApprovalStatus::Draft->value,
                    'has_nomor_induk' => false,
                    'version' => 0,
                ],
                actorId: $actor->getKey(),
            );

            $row = $this->findOrFail($id);
            $this->assertDraftGate($row);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $payload  must include `version` (optimistic lock)
     */
    public function updateDraft(User $actor, int $candidateId, array $payload): object
    {
        return DB::transaction(function () use ($actor, $candidateId, $payload): object {
            $this->authorizeUpdate($actor);

            // BR-CON-01: optimistic lock only — no SELECT FOR UPDATE on draft form path.
            $row = DB::table('candidate')->where('id', $candidateId)->first();
            if ($row === null) {
                $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
            }

            $this->assertEditableDraft($row);

            $expectedVersion = $payload['version'] ?? null;
            if (! is_int($expectedVersion) && ! (is_string($expectedVersion) && ctype_digit($expectedVersion))) {
                $this->fail('version', 'CANDIDATE_VERSION_REQUIRED');
            }
            $expectedVersion = (int) $expectedVersion;

            [$main, $children] = $this->validated($payload, creating: false);

            if ($main === [] && $children === []) {
                return $this->findOrFail($candidateId);
            }

            $updates = $main + ['updated_at' => now()];
            $affected = DB::table('candidate')
                ->where('id', $candidateId)
                ->where('version', $expectedVersion)
                ->update($updates + ['version' => $expectedVersion + 1]);

            if ($affected === 0) {
                throw new ConflictHttpException('CONFLICT');
            }

            $this->syncChildren($candidateId, $children, $actor->getKey());

            $this->audit->record(
                actionType: ActionType::CANDIDATE_UPDATED,
                entityType: 'candidate',
                entityId: $candidateId,
                detail: [
                    'status_approval' => $row->status_approval,
                    'version' => [$expectedVersion, $expectedVersion + 1],
                    'fields' => array_values(array_unique(array_merge(
                        array_keys($main),
                        array_keys($children),
                    ))),
                ],
                actorId: $actor->getKey(),
            );

            $fresh = $this->findOrFail($candidateId);
            $this->assertDraftGate($fresh);

            return $fresh;
        });
    }

    /**
     * Gate task: draft/rejected without NIK is not operational for Jobs/Placement.
     */
    public function isOperational(object $candidate): bool
    {
        return $candidate->status_approval === CandidateApprovalStatus::Disetujui->value
            && $candidate->nomor_induk !== null
            && $candidate->pii_anonymized_at === null
            && $candidate->deleted_at === null;
    }

    public function hasActivePending(int $candidateId): bool
    {
        return DB::table('pending_request')
            ->where('target_type', 'candidate')
            ->where('target_id', $candidateId)
            ->where('status', 'pending')
            ->exists();
    }

    private function authorizeCreate(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.create');
    }

    private function authorizeUpdate(User $actor): void
    {
        if ((int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('CANDIDATE_ACTOR_MISMATCH');
        }

        Gate::forUser($actor)->authorize('candidate.update');
    }

    private function assertEditableDraft(object $row): void
    {
        if ($row->pii_anonymized_at !== null || $row->deleted_at !== null) {
            $this->fail('candidate', 'CANDIDATE_NOT_EDITABLE');
        }

        $status = CandidateApprovalStatus::tryFrom((string) $row->status_approval);
        if ($status === null || ! $status->isDraftEditable()) {
            $this->fail('status_approval', 'CANDIDATE_NOT_DRAFT_EDITABLE');
        }
    }

    private function assertDraftGate(object $row): void
    {
        if ($row->status_approval === CandidateApprovalStatus::Draft->value
            && ($row->nomor_induk !== null || $this->hasActivePending((int) $row->id))) {
            throw new \RuntimeException('Draft invariant violated: NIK or pending present.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validated(array $payload, bool $creating): array
    {
        $unknown = array_diff(
            array_keys($payload),
            array_merge(self::MAIN_FIELDS, self::CHILD_KEYS, ['version']),
            self::SYSTEM_FIELDS,
        );

        // System fields may appear but are never applied; reject unknown non-system noise.
        $unknown = array_values(array_diff($unknown, self::SYSTEM_FIELDS));
        if ($unknown !== []) {
            $this->fail('attributes', 'CANDIDATE_FIELD_UNKNOWN');
        }

        $mainInput = array_intersect_key($payload, array_flip(self::MAIN_FIELDS));
        $mainInput = array_map(
            static fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $mainInput,
        );

        $rules = [
            'nama_alphabet' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'nama_katakana' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tanggal_lahir' => [$creating ? 'required' : 'sometimes', 'date'],
            'tempat_lahir_kota_id' => ['sometimes', 'nullable', 'integer'],
            'alamat_detail' => ['sometimes', 'nullable', 'string'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'line_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'kewarganegaraan_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'asal_rekrutmen_id' => ['sometimes', 'nullable', 'integer'],
            'agama_id' => ['sometimes', 'nullable', 'integer'],
            'alamat_provinsi_id' => ['sometimes', 'nullable', 'integer'],
            'alamat_kota_kabupaten_id' => ['sometimes', 'nullable', 'integer'],
            'alamat_kecamatan_id' => ['sometimes', 'nullable', 'integer'],
            'jenis_kelamin' => [$creating ? 'required' : 'sometimes', Rule::enum(Gender::class)],
            'status_pernikahan' => ['sometimes', 'nullable', Rule::enum(MaritalStatus::class)],
            'catatan_tambahan' => ['sometimes', 'nullable', 'string'],
        ];

        $main = Validator::make($mainInput, $rules)->validate();

        if (array_key_exists('jenis_kelamin', $main) && $main['jenis_kelamin'] instanceof Gender) {
            $main['jenis_kelamin'] = $main['jenis_kelamin']->value;
        }
        if (array_key_exists('status_pernikahan', $main) && $main['status_pernikahan'] instanceof MaritalStatus) {
            $main['status_pernikahan'] = $main['status_pernikahan']->value;
        }

        $this->assertLookupExists('negara', $main['kewarganegaraan_id'] ?? null, 'kewarganegaraan_id');
        $this->assertLookupExists('kota_kabupaten', $main['tempat_lahir_kota_id'] ?? null, 'tempat_lahir_kota_id');
        $this->assertLookupExists('asal_rekrutmen', $main['asal_rekrutmen_id'] ?? null, 'asal_rekrutmen_id');
        $this->assertLookupExists('agama', $main['agama_id'] ?? null, 'agama_id');
        $this->assertLookupExists('provinsi', $main['alamat_provinsi_id'] ?? null, 'alamat_provinsi_id');
        $this->assertLookupExists('kota_kabupaten', $main['alamat_kota_kabupaten_id'] ?? null, 'alamat_kota_kabupaten_id');
        $this->assertLookupExists('kecamatan', $main['alamat_kecamatan_id'] ?? null, 'alamat_kecamatan_id');

        $children = [];
        foreach (self::CHILD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $children[$key] = $this->validateChild($key, $payload[$key]);
        }

        return [$main, $children];
    }

    private function assertLookupExists(string $table, mixed $id, string $field): void
    {
        if ($id === null) {
            return;
        }

        if (! DB::table($table)->where('id', $id)->exists()) {
            $this->fail($field, 'CANDIDATE_LOOKUP_INVALID');
        }
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function validateChild(string $key, mixed $value): array
    {
        return match ($key) {
            'physical' => $this->validatePhysical($value),
            'self_promo' => $this->validateSelfPromo($value),
            'family_contact' => $this->validateFamilyContact($value),
            'immigration' => $this->validateImmigration($value),
            'education' => $this->validateList($key, $value, [
                'tingkat_pendidikan_id' => ['required', 'integer'],
                'jurusan_id' => ['sometimes', 'nullable', 'integer'],
                'nama_institusi' => ['sometimes', 'nullable', 'string', 'max:255'],
                'tanggal_masuk' => ['sometimes', 'nullable', 'date'],
                'tanggal_keluar' => ['sometimes', 'nullable', 'date'],
                'sort_order' => ['sometimes', 'integer'],
            ], [
                'tingkat_pendidikan_id' => 'tingkat_pendidikan',
                'jurusan_id' => 'jurusan',
            ]),
            'work' => $this->validateList($key, $value, [
                'nama_perusahaan' => ['sometimes', 'nullable', 'string', 'max:255'],
                'perusahaan_penanggung' => ['sometimes', 'nullable', 'string', 'max:255'],
                'bidang_pekerjaan_id' => ['sometimes', 'nullable', 'integer'],
                'tanggal_masuk' => ['sometimes', 'nullable', 'date'],
                'tanggal_keluar' => ['sometimes', 'nullable', 'date'],
                'sort_order' => ['sometimes', 'integer'],
            ], [
                'bidang_pekerjaan_id' => 'bidang_pekerjaan',
            ]),
            'qual_english' => $this->validateList($key, $value, [
                'jenis_id' => ['required', 'integer'],
                'tanggal_akuisisi' => ['sometimes', 'nullable', 'date'],
                'skor' => ['sometimes', 'nullable', 'string', 'max:64'],
                'url_file' => $this->driveUrlRules(required: false),
            ], ['jenis_id' => 'jenis_kualifikasi_bahasa_inggris']),
            'qual_japanese' => $this->validateList($key, $value, [
                'jenis_id' => ['required', 'integer'],
                'tanggal_akuisisi' => ['sometimes', 'nullable', 'date'],
                'skor' => ['sometimes', 'nullable', 'string', 'max:64'],
                'url_file' => $this->driveUrlRules(required: false),
            ], ['jenis_id' => 'jenis_kualifikasi_bahasa_jepang']),
            'qual_ssw' => $this->validateList($key, $value, [
                'skill_ssw_id' => ['required', 'integer'],
                'tanggal_akuisisi' => ['sometimes', 'nullable', 'date'],
                'url_file' => $this->driveUrlRules(required: false),
            ], ['skill_ssw_id' => 'skill_ssw']),
            'qual_driving' => $this->validateList($key, $value, [
                'kualifikasi_mengemudi_id' => ['required', 'integer'],
                'tanggal_akuisisi' => ['sometimes', 'nullable', 'date'],
            ], ['kualifikasi_mengemudi_id' => 'kualifikasi_mengemudi']),
            'qual_other' => $this->validateList($key, $value, [
                'kualifikasi_keahlian_lainnya_id' => ['required', 'integer'],
                'tanggal_akuisisi' => ['sometimes', 'nullable', 'date'],
                'url_file' => $this->driveUrlRules(required: false),
            ], ['kualifikasi_keahlian_lainnya_id' => 'kualifikasi_keahlian_lainnya']),
            'family' => $this->validateList($key, $value, [
                'status_keluarga_id' => ['required', 'integer'],
                'nama' => ['sometimes', 'nullable', 'string', 'max:255'],
                'tanggal_lahir' => ['sometimes', 'nullable', 'date'],
                'sort_order' => ['sometimes', 'integer'],
            ], ['status_keluarga_id' => 'status_keluarga']),
            'documents' => $this->validateList($key, $value, [
                'jenis_dokumen_id' => ['required', 'integer'],
                'url_dokumen' => $this->driveUrlRules(required: true),
                'nama_file' => ['sometimes', 'nullable', 'string', 'max:255'],
                'catatan' => ['sometimes', 'nullable', 'string'],
                'sort_order' => ['sometimes', 'integer'],
            ], ['jenis_dokumen_id' => 'jenis_dokumen']),
            default => $this->fail($key, 'CANDIDATE_FIELD_UNKNOWN'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePhysical(mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail('physical', 'CANDIDATE_CHILD_INVALID');
        }

        $row = Validator::make($value, [
            'tinggi_cm' => ['sometimes', 'nullable', 'numeric'],
            'berat_kg' => ['sometimes', 'nullable', 'numeric'],
            'lingkar_perut_cm' => ['sometimes', 'nullable', 'numeric'],
            'golongan_darah_id' => ['sometimes', 'nullable', 'integer'],
            'ukuran_sepatu_id' => ['sometimes', 'nullable', 'integer'],
            'penglihatan_kiri_id' => ['sometimes', 'nullable', 'integer'],
            'penglihatan_kanan_id' => ['sometimes', 'nullable', 'integer'],
            'dominan_tangan' => ['sometimes', 'nullable', Rule::enum(DominantHand::class)],
            'buta_warna' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'merokok' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'minum_sake' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'pembatasan_makanan' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'riwayat_penyakit' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'riwayat_operasi' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'catatan_kesehatan' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        foreach (['dominan_tangan', 'buta_warna', 'merokok', 'minum_sake', 'pembatasan_makanan', 'riwayat_penyakit', 'riwayat_operasi'] as $enumField) {
            if (array_key_exists($enumField, $row) && is_object($row[$enumField])) {
                $row[$enumField] = $row[$enumField]->value;
            }
        }

        $this->assertLookupExists('golongan_darah', $row['golongan_darah_id'] ?? null, 'physical.golongan_darah_id');
        $this->assertLookupExists('ukuran_sepatu', $row['ukuran_sepatu_id'] ?? null, 'physical.ukuran_sepatu_id');
        $this->assertLookupExists('tingkat_penglihatan', $row['penglihatan_kiri_id'] ?? null, 'physical.penglihatan_kiri_id');
        $this->assertLookupExists('tingkat_penglihatan', $row['penglihatan_kanan_id'] ?? null, 'physical.penglihatan_kanan_id');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSelfPromo(mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail('self_promo', 'CANDIDATE_CHILD_INVALID');
        }

        $row = Validator::make($value, [
            'skor_iq' => ['sometimes', 'nullable', 'integer'],
            'skor_matematika' => ['sometimes', 'nullable', 'integer'],
            'bidang_diminati_id' => ['sometimes', 'nullable', 'integer'],
            'video_jikoshokai_url' => $this->videoUrlRules(),
            'video_keahlian_url' => $this->videoUrlRules(),
            'final_laporan_psikotes' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $this->assertLookupExists('bidang_diminati', $row['bidang_diminati_id'] ?? null, 'self_promo.bidang_diminati_id');

        return $row;
    }

    /**
     * @return list<mixed>
     */
    private function driveUrlRules(bool $required): array
    {
        return [
            ...($required ? ['required'] : ['sometimes', 'nullable']),
            'string',
            'max:2048',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! is_string($value) || ! $this->isAllowedHttpsUrl($value, self::DRIVE_HOSTS)) {
                    $fail('CANDIDATE_URL_INVALID');
                }
            },
        ];
    }

    /**
     * @return list<mixed>
     */
    private function videoUrlRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            'max:2048',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! is_string($value) || ! $this->isAllowedHttpsUrl($value, self::VIDEO_HOSTS)) {
                    $fail('CANDIDATE_URL_INVALID');
                }
            },
        ];
    }

    /**
     * @param  list<string>  $hosts
     */
    private function isAllowedHttpsUrl(string $value, array $hosts): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        $host = strtolower((string) $parts['host']);

        return in_array($host, $hosts, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateFamilyContact(mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail('family_contact', 'CANDIDATE_CHILD_INVALID');
        }

        $row = Validator::make($value, [
            'status_keluarga_id' => ['sometimes', 'nullable', 'integer'],
            'nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'alamat' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        $this->assertLookupExists('status_keluarga', $row['status_keluarga_id'] ?? null, 'family_contact.status_keluarga_id');

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateImmigration(mixed $value): array
    {
        if (! is_array($value)) {
            $this->fail('immigration', 'CANDIDATE_CHILD_INVALID');
        }

        $row = Validator::make($value, [
            'nomor_paspor' => ['sometimes', 'nullable', 'string', 'max:64'],
            'masa_berlaku_paspor' => ['sometimes', 'nullable', 'date'],
            'nomor_zairyu' => ['sometimes', 'nullable', 'string', 'max:64'],
            'alamat_zairyu' => ['sometimes', 'nullable', 'string'],
            'jenis_visa_id' => ['sometimes', 'nullable', 'integer'],
            'pernah_ke_jepang' => ['sometimes', 'nullable', Rule::enum(YesNo::class)],
            'catatan' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        if (array_key_exists('pernah_ke_jepang', $row) && $row['pernah_ke_jepang'] instanceof YesNo) {
            $row['pernah_ke_jepang'] = $row['pernah_ke_jepang']->value;
        }

        $this->assertLookupExists('jenis_visa', $row['jenis_visa_id'] ?? null, 'immigration.jenis_visa_id');

        return $row;
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @param  array<string, string>  $lookups
     * @return list<array<string, mixed>>
     */
    private function validateList(string $key, mixed $value, array $rules, array $lookups = []): array
    {
        if (! is_array($value)) {
            $this->fail($key, 'CANDIDATE_CHILD_INVALID');
        }

        $limit = self::CHILD_LIMITS[$key] ?? null;
        if ($limit !== null && count($value) > $limit) {
            $this->fail($key, 'CANDIDATE_CHILD_LIMIT');
        }

        $rows = [];
        foreach (array_values($value) as $index => $item) {
            if (! is_array($item)) {
                $this->fail("{$key}.{$index}", 'CANDIDATE_CHILD_INVALID');
            }

            $row = Validator::make($item, $rules)->validate();
            foreach ($lookups as $field => $table) {
                $this->assertLookupExists($table, $row[$field] ?? null, "{$key}.{$index}.{$field}");
            }
            if (! array_key_exists('sort_order', $row) && array_key_exists('sort_order', $rules)) {
                $row['sort_order'] = $index;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $children
     */
    private function syncChildren(int $candidateId, array $children, int $actorId): void
    {
        foreach ($children as $key => $value) {
            match ($key) {
                'physical' => $this->upsertOne('candidate_physical', $candidateId, $value),
                'self_promo' => $this->upsertOne('candidate_self_promo', $candidateId, $value),
                'family_contact' => $this->upsertOne('candidate_family_contact', $candidateId, $value),
                'immigration' => $this->upsertOne('candidate_immigration', $candidateId, $value),
                'education' => $this->replaceMany('candidate_education', $candidateId, $value),
                'work' => $this->replaceMany('candidate_work', $candidateId, $value),
                'qual_english' => $this->replaceMany('candidate_qual_english', $candidateId, $value),
                'qual_japanese' => $this->replaceMany('candidate_qual_japanese', $candidateId, $value),
                'qual_ssw' => $this->replaceMany('candidate_qual_ssw', $candidateId, $value),
                'qual_driving' => $this->replaceMany('candidate_qual_driving', $candidateId, $value),
                'qual_other' => $this->replaceMany('candidate_qual_other', $candidateId, $value),
                'family' => $this->replaceMany('candidate_family', $candidateId, $value),
                'documents' => $this->replaceDocuments($candidateId, $value, $actorId),
                default => null,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertOne(string $table, int $candidateId, array $values): void
    {
        $existing = DB::table($table)->where('candidate_id', $candidateId)->first();
        $now = now();

        if ($existing === null) {
            DB::table($table)->insert($values + [
                'candidate_id' => $candidateId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table($table)->where('candidate_id', $candidateId)->update($values + ['updated_at' => $now]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceMany(string $table, int $candidateId, array $rows): void
    {
        DB::table($table)->where('candidate_id', $candidateId)->delete();
        $now = now();

        foreach ($rows as $row) {
            DB::table($table)->insert($row + [
                'candidate_id' => $candidateId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceDocuments(int $candidateId, array $rows, int $actorId): void
    {
        DB::table('candidate_document')->where('candidate_id', $candidateId)->delete();
        $now = now();

        foreach ($rows as $index => $row) {
            DB::table('candidate_document')->insert($row + [
                'candidate_id' => $candidateId,
                'uploaded_by' => $actorId,
                'sort_order' => $row['sort_order'] ?? $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function findOrFail(int $id): object
    {
        $row = DB::table('candidate')->where('id', $id)->first();
        if ($row === null) {
            $this->fail('candidate', 'CANDIDATE_NOT_FOUND');
        }

        return $row;
    }

    private function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code]);
    }
}
