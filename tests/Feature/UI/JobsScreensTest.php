<?php

namespace Tests\Feature\UI;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Modules\Auth\Rbac;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Jobs\Public\InterviewQueryService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class JobsScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedJobsLookup();
        $this->seedNegara();
    }

    private int $perusahaanId;

    private int $posisiId;

    private int $visaId;

    private int $negaraId;

    private ?int $approverUserId = null;

    private function seedNegara(): void
    {
        $this->negaraId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedJobsLookup(): void
    {
        $this->perusahaanId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'テスト株式会社',
            'nama_romaji' => 'Test Kabushiki Kaisha',
            'nama_id' => 'PT Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->posisiId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'IT_ENG',
            'label_id' => 'Teknisi IT',
            'label_ja' => 'IT技術者',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'TOKUGINOU',
            'label_id' => 'Tokutei Gino',
            'label_ja' => '特定技能',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function maker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::ASSISTANT_MANAGER);

        return $user;
    }

    private function checker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::JOB_MANAGER);

        return $this->withTwoFactor($user);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        return $this->withTwoFactor($user);
    }

    private function noRoleUser(): User
    {
        return User::factory()->active()->create();
    }

    private function withTwoFactor(User $user): User
    {
        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function createContainer(array $overrides = []): int
    {
        $data = array_merge([
            'kode_kontainer' => null,
            'judul' => 'Wawancara Batch April',
            'perusahaan_id' => $this->perusahaanId,
            'posisi_pekerjaan_id' => $this->posisiId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $this->visaId,
            'tanggal_wawancara' => '2026-08-10',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 3,
            'deskripsi' => null,
            'syarat' => null,
            'status' => 'Draft',
            'dibuat_oleh' => $this->maker()->id,
            'disetujui_oleh' => null,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => null,
            'closed_at' => null,
            'updated_at' => now(),
        ], $overrides);

        if (in_array($data['status'], ['Aktif', 'Ditutup'], true) && $data['disetujui_oleh'] === null) {
            $data['disetujui_oleh'] = $this->approverUserId ??= User::factory()->active()->create()->id;
        }

        return (int) DB::table('interview_container')->insertGetId($data);
    }

    private function createCandidate(array $overrides = []): int
    {
        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => 'K-2026-00001',
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => Carbon::parse('1998-05-10'),
            'tempat_lahir_kota_id' => null,
            'alamat_detail' => 'Jl. Melati No. 1',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'line_id' => null,
            'kewarganegaraan_id' => $this->negaraId,
            'asal_rekrutmen_id' => null,
            'agama_id' => null,
            'alamat_provinsi_id' => null,
            'alamat_kota_kabupaten_id' => null,
            'alamat_kecamatan_id' => null,
            'jenis_kelamin' => 'M',
            'status_pernikahan' => 'SINGLE',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker()->id,
            'approved_by' => $this->approverUserId ??= User::factory()->active()->create()->id,
            'catatan_penolakan_terakhir' => null,
            'catatan_tambahan' => null,
            'deleted_at' => null,
            'pii_anonymized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addParticipation(int $containerId, int $candidateId, array $overrides = []): int
    {
        return (int) DB::table('participation')->insertGetId(array_merge([
            'interview_container_id' => $containerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Menunggu Wawancara',
            'catatan' => null,
            'frozen_at' => null,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addPending(string $type, string $targetType, int $targetId): void
    {
        DB::table('pending_request')->insert([
            'type' => $type,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'requested_by' => $this->maker()->id,
            'reason_maker' => 'Alasan uji',
            'checker_id' => null,
            'note_checker' => null,
            'payload' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ----- Query service -----

    public function test_paginate_requires_jobs_view(): void
    {
        $this->expectException(AuthorizationException::class);

        app(InterviewQueryService::class)->paginate($this->noRoleUser());
    }

    public function test_detail_requires_jobs_view(): void
    {
        $this->expectException(AuthorizationException::class);

        app(InterviewQueryService::class)->detail($this->noRoleUser(), 1);
    }

    public function test_detail_returns_null_for_missing_container(): void
    {
        $this->assertNull(app(InterviewQueryService::class)->detail($this->maker(), 999999));
    }

    public function test_eligible_pull_picker_requires_jobs_execute(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CandidateQueryService::class)->eligibleForInterviewPull($this->noRoleUser());
    }

    public function test_eligible_pull_picker_lists_only_disetujui_tersedia(): void
    {
        $maker = $this->maker();
        $this->createCandidate();
        $this->createCandidate([
            'nomor_induk' => 'K-2026-00002',
            'nama_alphabet' => 'Siti Aminah',
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
        ]);
        $this->createCandidate([
            'nomor_induk' => 'K-2026-00003',
            'nama_alphabet' => 'Draft Dedi',
            'status_approval' => 'Draft',
        ]);

        $result = app(CandidateQueryService::class)->eligibleForInterviewPull($maker);

        $this->assertSame(1, $result->total());
        $this->assertSame('K-2026-00001', $result->first()->nomor_induk);
    }

    // ----- Pages -----

    public function test_list_page_opens_for_jobs_roles(): void
    {
        $this->createContainer();

        foreach ([$this->maker(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)
                ->get('/jobs')
                ->assertOk()
                ->assertSee('Kontainer Wawancara')
                ->assertSee('Wawancara Batch April');
        }
    }

    public function test_list_page_forbids_user_without_jobs_view(): void
    {
        $this->actingAs($this->noRoleUser())->get('/jobs')->assertForbidden();
    }

    public function test_list_page_redirects_guest(): void
    {
        $this->get('/jobs')->assertRedirect();
    }

    public function test_detail_page_renders_participations_and_pending_overlay(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer(['status' => 'Aktif']);
        $this->addParticipation($containerId, $candidateId);
        $this->addPending('IC_CLOSE', 'interview_container', $containerId);

        $this->actingAs($maker)
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertSee('Wawancara Batch April')
            ->assertSee('Budi Santoso')
            ->assertSee('Menunggu Wawancara')
            ->assertSee('Persetujuan penutupan');
    }

    public function test_detail_shows_soft_warning_when_target_reached(): void
    {
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'target_peserta_diterima' => 1,
        ]);
        $this->addParticipation($containerId, $candidateId, ['status_wawancara' => 'Lulus']);

        $this->actingAs($this->maker())
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertSee('TARGET_WARN')
            ->assertSee('Lanjut tetap diizinkan');
    }

    public function test_detail_hides_soft_warning_below_target(): void
    {
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'target_peserta_diterima' => 5,
        ]);
        $this->addParticipation($containerId, $candidateId, ['status_wawancara' => 'Lulus']);

        $this->actingAs($this->maker())
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertDontSee('TARGET_WARN');
    }

    public function test_detail_shows_closed_banner_and_frozen_badge(): void
    {
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Ditutup',
            'closed_at' => now(),
        ]);
        $this->addParticipation($containerId, $candidateId, [
            'status_wawancara' => 'Menunggu Wawancara',
            'frozen_at' => now(),
        ]);

        $this->actingAs($this->maker())
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertSee('partisipasi non-terminal dibekukan')
            ->assertSee('Dibekukan');
    }

    public function test_detail_page_shows_not_found_state(): void
    {
        $this->actingAs($this->maker())
            ->get('/jobs/999999')
            ->assertOk()
            ->assertSee('Kontainer tidak ditemukan');
    }

    public function test_detail_page_forbids_user_without_jobs_view(): void
    {
        $containerId = $this->createContainer();

        $this->actingAs($this->noRoleUser())->get('/jobs/'.$containerId)->assertForbidden();
    }
}
