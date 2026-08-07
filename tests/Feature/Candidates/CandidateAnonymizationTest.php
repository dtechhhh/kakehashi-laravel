<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Candidates\Services\CandidateAnonymizationService;
use Modules\Candidates\Services\CandidatePhotoService;
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\GuestAccess\Exceptions\GuestAccessDeniedException;
use Modules\GuestAccess\Public\GuestCandidateReadModel;
use Modules\GuestAccess\Services\GuestAccessService;
use Modules\Jobs\Services\GuestLinkService;
use RuntimeException;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Files\DocumentLinkAuditService;
use Shared\Files\FileStorageService;
use Tests\TestCase;

/**
 * W7-T3 — anonymization E2E: irreversible tombstone, PII scrub, R2 photo
 * cleanup, Drive URL removal, CANDIDATE_ANONYMIZED audit, Guest exclusion,
 * every guard, step-up missing, and file-failure non-rollback.
 */
class CandidateAnonymizationTest extends TestCase
{
    use RefreshDatabase;

    /** Real commits so after-commit photo cleanup actually runs. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->maker = User::factory()->active()->create();
        $this->maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->checker = User::factory()->active()->create();
        $this->checker->assignRole(Rbac::JOB_MANAGER);
        $this->makerId = (int) $this->maker->id;
        $this->checkerId = (int) $this->checker->id;
        $this->countryId = $this->lookup('negara', $this->uniqueCountryCode(), 'Indonesia', 'インドネシア');
        $this->containerId = $this->createGuestContainer();
        config([
            'filesystems.disks.r2.driver' => 'local',
            'filesystems.disks.r2.root' => storage_path('framework/testing/r2-anonymize-'.uniqid('', true)),
            'filesystems.disks.r2.throw' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    protected int $makerId;

    protected int $checkerId;

    protected int $containerId;

    protected int $countryId;

    private User $maker;

    private User $checker;

    public function test_eligible_candidate_is_fully_anonymized_with_audit_and_photo_cleanup(): void
    {
        $candidateId = $this->eligibleCandidate();
        $suffix = $this->uniqueCode();
        $this->addEducation($candidateId, 'EDU_'.$suffix, '大学');
        $this->addWorkHistory($candidateId, 'WORK_'.$suffix, '食品製造業');
        $this->addJapaneseLevel($candidateId, 'JLPT_'.$suffix, '日本語能力試験N3');
        $this->addSswQualification($candidateId, 'SSW_'.$suffix, '飲食料品製造業');
        $this->addBidangDiminati($candidateId, 'BIDANG_'.$suffix, '食品製造');
        $this->addPhoto($candidateId);
        DB::table('candidate_document')->insert([
            'candidate_id' => $candidateId,
            'jenis_dokumen_id' => $this->lookup('jenis_dokumen', 'PASSPORT_'.$suffix, 'Paspor', 'パスポート'),
            'url_dokumen' => 'https://drive.google.com/file/d/SECRET-DOC/view',
            'nama_file' => 'paspor.pdf',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('candidate_physical')->insert([
            'candidate_id' => $candidateId,
            'tinggi_cm' => 170,
            'catatan_kesehatan' => 'sehat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('candidate_immigration')->insert([
            'candidate_id' => $candidateId,
            'nomor_paspor' => 'AB123456',
            'catatan' => 'data imigrasi',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('candidate_family')->insert([
            'candidate_id' => $candidateId,
            'status_keluarga_id' => $this->lookup('status_keluarga', 'SPOUSE_'.$suffix, 'Pasangan', '配偶者'),
            'nama' => 'Siti',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $photoKey = (string) DB::table('candidate_photo')->where('candidate_id', $candidateId)->value('object_key');
        app(FileStorageService::class)->storeCandidatePhoto($photoKey, 'fake-photo-bytes', 'image/jpeg');
        $this->assertTrue(app(FileStorageService::class)->exists($photoKey));

        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevate($candidateId);

        app(CandidateAnonymizationService::class)->anonymize($admin, $candidateId);

        $row = DB::table('candidate')->where('id', $candidateId)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->pii_anonymized_at);
        $this->assertSame('ANONIM', $row->nama_alphabet);
        $this->assertNull($row->nama_katakana);
        $this->assertSame('1970-01-01', (string) $row->tanggal_lahir);
        $this->assertNull($row->email);
        $this->assertNull($row->phone);
        $this->assertNull($row->line_id);
        $this->assertNull($row->alamat_detail);
        $this->assertNull($row->catatan_tambahan);

        foreach ([
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
            'candidate_photo',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->where('candidate_id', $candidateId)->count(), "{$table} must be empty.");
        }

        $this->assertFalse(app(FileStorageService::class)->exists($photoKey), 'R2 photo must be deleted.');

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_ANONYMIZED)->sole();
        $this->assertSame($admin->getKey(), $audit->actor_id);
        $this->assertSame(Rbac::SUPER_ADMIN, $audit->actor_role_snapshot);
        $this->assertSame($candidateId, $audit->detail['candidate_id']);
        $this->assertSame($row->nomor_induk, $audit->detail['nomor_induk']);
        $this->assertStringNotContainsString('SECRET-DOC', json_encode($audit->detail, JSON_THROW_ON_ERROR));
    }

    public function test_every_guard_blocks_with_real_cross_module_probes(): void
    {
        $cases = [
            'availability' => function (int $candidateId): void {
                DB::table('candidate')->where('id', $candidateId)->update(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
            },
            'participation' => function (int $candidateId): void {
                DB::table('participation')->insert([
                    'interview_container_id' => $this->containerId,
                    'candidate_id' => $candidateId,
                    'status_wawancara' => 'Menunggu Wawancara',
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            'placement' => function (int $candidateId): void {
                $container = DB::table('interview_container')->where('id', $this->containerId)->first();
                $containerId = (int) DB::table('placement_container')->insertGetId([
                    'nama' => 'Placement Guard',
                    'perusahaan_id' => $container->perusahaan_id,
                    'status' => 'Draft',
                    'dibuat_oleh' => $this->makerId,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('placement_participants')->insert([
                    'placement_container_id' => $containerId,
                    'candidate_id' => $candidateId,
                    'source_participation_id' => 999,
                    'jenis_visa_id' => $container->jenis_visa_id,
                    'status_penempatan' => 'Bekerja',
                    'tanggal_mulai_kerja' => '2026-01-01',
                    'durasi_kontrak_bulan' => 12,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            'pending' => function (int $candidateId): void {
                DB::table('pending_request')->insert([
                    'type' => 'CANDIDATE_NEW',
                    'target_type' => 'candidate',
                    'target_id' => $candidateId,
                    'requested_by' => $this->makerId,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            'revision' => function (int $candidateId): void {
                DB::table('candidate')->insert([
                    'nama_alphabet' => 'Active Revision',
                    'tanggal_lahir' => '2000-01-01',
                    'kewarganegaraan_id' => $this->countryId,
                    'jenis_kelamin' => 'M',
                    'status_ketersediaan' => 'TERSEDIA',
                    'status_approval' => 'Draft',
                    'parent_candidate_id' => $candidateId,
                    'version' => 0,
                    'created_by' => $this->makerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        ];

        foreach ($cases as $label => $seed) {
            $candidateId = $this->eligibleCandidate();
            $seed($candidateId);
            $admin = $this->superAdmin();
            $this->actingAs($admin);
            $this->elevate($candidateId);

            try {
                app(CandidateAnonymizationService::class)->anonymize($admin, $candidateId);
                $this->fail("{$label} must block anonymization.");
            } catch (ValidationException $exception) {
                $this->assertSame(['candidate' => ['PII_ACTIVE']], $exception->errors());
            }

            $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        }
    }

    public function test_step_up_missing_and_wrong_scope_are_rejected_before_tombstone(): void
    {
        $candidateId = $this->eligibleCandidate();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->assertStepUpRequired($candidateId, $admin);

        $this->elevate($candidateId, StepUpAction::MANAGE_LOOKUP_OR_COMPANY);
        $this->assertStepUpRequired($candidateId, $admin);

        $this->elevate($candidateId);
        app(CandidateAnonymizationService::class)->anonymize($admin, $candidateId);

        $this->assertNotNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
    }

    public function test_non_super_admin_and_inactive_admin_are_denied(): void
    {
        $candidateId = $this->eligibleCandidate();

        foreach ([
            $this->staff(),
            $this->approver(),
            $this->jobManager(),
            $this->inactiveSuperAdmin(),
        ] as $user) {
            $this->actingAs($user);
            $this->elevate($candidateId);

            try {
                app(CandidateAnonymizationService::class)->anonymize($user, $candidateId);
                $this->fail('Non-admin must be denied.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('CANDIDATE_ANONYMIZE_FORBIDDEN', $exception->getMessage());
            }

            $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        }
    }

    public function test_file_deletion_failure_never_rolls_back_tombstone(): void
    {
        $candidateId = $this->eligibleCandidate();
        $this->addPhoto($candidateId);
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevate($candidateId);

        $this->makeR2DeleteThrow();

        app(CandidateAnonymizationService::class)->anonymize($admin, $candidateId);

        $this->assertNotNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::CANDIDATE_ANONYMIZED)
                ->where('entity_id', $candidateId)
                ->count(),
        );
    }

    public function test_anonymized_candidate_is_irreversible_and_hidden_from_guest(): void
    {
        $candidateId = $this->createEligibleGuestCandidate();
        $this->addPhoto($candidateId);
        $session = $this->enter();
        $ids = app(GuestCandidateReadModel::class)->listForContainer($session)->items();
        $this->assertContains($candidateId, array_map(static fn (object $item): int => (int) $item->id, $ids));

        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevate($candidateId);
        app(CandidateAnonymizationService::class)->anonymize($admin, $candidateId);

        $idsAfter = app(GuestCandidateReadModel::class)->listForContainer($this->enter())->items();
        $this->assertNotContains($candidateId, array_map(static fn (object $item): int => (int) $item->id, $idsAfter));

        try {
            app(GuestCandidateReadModel::class)->detailForGuest($this->enter(), $candidateId);
            $this->fail('Anonymized direct detail must be denied generically.');
        } catch (GuestAccessDeniedException) {
            $this->assertTrue(true);
        }

        $this->actingAs($admin);
        $this->assertNull(app(CandidateQueryService::class)->detail($admin, $candidateId));

        try {
            app(CandidatePhotoService::class)->temporaryUrl($admin, $candidateId);
            $this->fail('Photo URL must be denied after anonymization.');
        } catch (ValidationException $exception) {
            $this->assertSame('CANDIDATE_NOT_ACCESSIBLE', $exception->errors()['candidate'][0]);
        }

        try {
            app(DocumentLinkAuditService::class)->revealLink($candidateId, 1, (int) $admin->getKey());
            $this->fail('Document reveal must be denied after anonymization.');
        } catch (ValidationException $exception) {
            $this->assertSame('CANDIDATE_DOCUMENT_NOT_FOUND', $exception->errors()['candidate_document'][0]);
        }

        $staff = $this->staff();
        $this->actingAs($staff);
        try {
            app(CandidateRevisionService::class)->createRevision($staff, $candidateId, ['version' => 1]);
            $this->fail('Revision must be denied after anonymization.');
        } catch (ValidationException $exception) {
            $this->assertSame('CANDIDATE_NOT_REVISABLE', $exception->errors()['candidate'][0]);
        }
    }

    private function eligibleCandidate(): int
    {
        $now = now();

        return (int) DB::table('candidate')->insertGetId([
            'nomor_induk' => 'K-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => '1998-05-10',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'line_id' => 'budi.line',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'M',
            'status_pernikahan' => 'SINGLE',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Disetujui',
            'version' => 1,
            'created_by' => $this->makerId,
            'approved_by' => $this->checkerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createEligibleGuestCandidate(): int
    {
        $candidateId = $this->eligibleCandidate();
        DB::table('participation')->insert([
            'interview_container_id' => $this->containerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Tidak Lolos',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $candidateId;
    }

    private function createGuestContainer(): int
    {
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W7 テスト会社',
            'nama_romaji' => 'W7 Test Company',
            'nama_id' => 'Perusahaan Test W7',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->lookup('posisi_pekerjaan', 'W7_POSITION_'.$this->uniqueCode(), 'Posisi Test', 'テストポジション');
        $visaId = $this->lookup('jenis_visa', 'W7_VISA_'.$this->uniqueCode(), 'Visa Test', 'テストビザ', ['kategori' => 'SSW']);

        return (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W7 Container',
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 1,
            'deskripsi' => 'Synthetic W7 fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $this->makerId,
            'disetujui_oleh' => $this->checkerId,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lookup(string $table, string $code, string $labelId, string $labelJa, array $extra = []): int
    {
        return (int) DB::table($table)->insertGetId(array_merge([
            'code' => $code,
            'label_id' => $labelId,
            'label_ja' => $labelJa,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function uniqueCountryCode(): string
    {
        do {
            $code = chr(65 + random_int(0, 25)).chr(65 + random_int(0, 25));
        } while ($code === 'ID');

        return $code;
    }

    private function uniqueCode(): string
    {
        return strtoupper(bin2hex(random_bytes(4)));
    }

    private function cleanFixtures(): void
    {
        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, notifications, pending_request, nik_counter, container_counter, '
            .'candidate, interview_container, placement_container, guest_link, perusahaan, negara, '
            .'posisi_pekerjaan, jenis_visa, tingkat_pendidikan, jurusan, bidang_pekerjaan, '
            .'jenis_kualifikasi_bahasa_jepang, skill_ssw, bidang_diminati, jenis_dokumen, status_keluarga '
            .'RESTART IDENTITY CASCADE',
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }

    private function addEducation(int $candidateId, string $code, string $labelJa): void
    {
        $tingkatId = $this->lookup('tingkat_pendidikan', $code, 'Pendidikan '.$code, $labelJa);
        $jurusanId = $this->lookup('jurusan', $code.'_JURUSAN', 'Jurusan '.$code, '機械工学');
        DB::table('candidate_education')->insert([
            'candidate_id' => $candidateId,
            'tingkat_pendidikan_id' => $tingkatId,
            'jurusan_id' => $jurusanId,
            'nama_institusi' => 'W7 大学',
            'tanggal_masuk' => '2016-04-01',
            'tanggal_keluar' => '2020-03-31',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addWorkHistory(int $candidateId, string $code, string $labelJa): void
    {
        $bidangId = $this->lookup('bidang_pekerjaan', $code, 'Bidang '.$code, $labelJa);
        DB::table('candidate_work')->insert([
            'candidate_id' => $candidateId,
            'nama_perusahaan' => 'W7 製造株式会社',
            'perusahaan_penanggung' => 'TSK 株式会社',
            'bidang_pekerjaan_id' => $bidangId,
            'tanggal_masuk' => '2020-04-01',
            'tanggal_keluar' => '2024-03-31',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addJapaneseLevel(int $candidateId, string $code, string $labelJa): void
    {
        $typeId = $this->lookup('jenis_kualifikasi_bahasa_jepang', $code, 'Bahasa Jepang '.$code, $labelJa);
        DB::table('candidate_qual_japanese')->insert([
            'candidate_id' => $candidateId,
            'jenis_id' => $typeId,
            'tanggal_akuisisi' => '2024-03-01',
            'skor' => 'N3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addSswQualification(int $candidateId, string $code, string $labelJa): void
    {
        $skillId = $this->lookup('skill_ssw', $code, 'SSW '.$code, $labelJa);
        DB::table('candidate_qual_ssw')->insert([
            'candidate_id' => $candidateId,
            'skill_ssw_id' => $skillId,
            'tanggal_akuisisi' => '2024-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addBidangDiminati(int $candidateId, string $code, string $labelJa): void
    {
        $bidangId = $this->lookup('bidang_diminati', $code, 'Bidang '.$code, $labelJa);
        DB::table('candidate_self_promo')->insert([
            'candidate_id' => $candidateId,
            'bidang_diminati_id' => $bidangId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addPhoto(int $candidateId): void
    {
        DB::table('candidate_photo')->insert([
            'candidate_id' => $candidateId,
            'object_key' => 'candidates/'.$candidateId.'/photo-'.uniqid('', true).'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'uploaded_by' => $this->makerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{token: string, link_id: int}
     */
    private function approveLink(): array
    {
        Auth::login($this->maker);
        try {
            $request = app(GuestLinkService::class)->requestGuestLink(
                $this->maker,
                $this->containerId,
                [
                    'version' => 0,
                    'label' => 'W7 guest link',
                    'tanggal_kadaluarsa' => now()->addDays(3)->format('Y-m-d H:i:s'),
                    'kode_tambahan' => null,
                ],
            );
        } finally {
            Auth::logout();
        }

        Auth::login($this->checker);
        try {
            $token = (string) app(GuestLinkService::class)
                ->approveGuestLink($this->checker, (int) $request->getKey())
                ->token;
        } finally {
            Auth::logout();
        }

        return [
            'token' => $token,
            'link_id' => (int) DB::table('guest_link')
                ->where('interview_container_id', $this->containerId)
                ->where('token_hash', hash('sha256', $token))
                ->value('id'),
        ];
    }

    private function enter(): object
    {
        ['token' => $token] = $this->approveLink();

        return app(GuestAccessService::class)->enter($token, null);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        return $user;
    }

    private function staff(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::STAFF_INPUT);

        return $user;
    }

    private function approver(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        return $user;
    }

    private function jobManager(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::JOB_MANAGER);

        return $user;
    }

    private function inactiveSuperAdmin(): User
    {
        $user = User::factory()->create(['status_akun' => 'Nonaktif']);
        $user->assignRole(Rbac::SUPER_ADMIN);

        return $user;
    }

    private function elevate(int $candidateId, string $action = StepUpAction::ANONYMIZE_PII): void
    {
        session([
            'stepup.tokens' => [
                $action.'.candidate.'.$candidateId => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
    }

    private function assertStepUpRequired(int $candidateId, User $actor): void
    {
        try {
            app(CandidateAnonymizationService::class)->anonymize($actor, $candidateId);
            $this->fail('Expected STEPUP_REQUIRED.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }
    }

    private function makeR2DeleteThrow(): void
    {
        $root = (string) config('filesystems.disks.r2.root');

        Storage::extend('throwing-r2', function ($app, array $config): FilesystemAdapter {
            $adapter = new class($config['root']) extends LocalFilesystemAdapter
            {
                public function delete(string $path): void
                {
                    throw new RuntimeException('forced r2 delete failure');
                }
            };

            $flysystem = new Flysystem($adapter);

            return new FilesystemAdapter($flysystem, $adapter, $config);
        });

        config([
            'filesystems.disks.r2.driver' => 'throwing-r2',
            'filesystems.disks.r2.root' => $root,
        ]);
    }
}
