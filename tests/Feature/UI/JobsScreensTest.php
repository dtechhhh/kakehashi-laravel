<?php

namespace Tests\Feature\UI;

use App\Livewire\Jobs\InterviewDetail;
use App\Livewire\Jobs\InterviewForm;
use App\Livewire\Jobs\InterviewPull;
use App\Livewire\Jobs\InterviewReviewQueue;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Jobs\Public\InterviewQueryService;
use Modules\Jobs\Services\GuestLinkService;
use Modules\Jobs\Services\InterviewContainerService;
use Modules\Jobs\Services\InterviewParticipationService;
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

    public function test_jumlah_peserta_counts_active_participations_only(): void
    {
        $containerId = $this->createContainer(['status' => 'Aktif']);
        $this->addParticipation($containerId, $this->createCandidate());
        $this->addParticipation($containerId, $this->createCandidate([
            'nomor_induk' => 'K-2026-00002',
            'nama_alphabet' => 'Siti Aminah',
        ]), ['status_wawancara' => 'Lulus']);
        $this->addParticipation($containerId, $this->createCandidate([
            'nomor_induk' => 'K-2026-00003',
            'nama_alphabet' => 'Draft Dedi',
        ]), ['frozen_at' => now()]);
        $this->addParticipation($containerId, $this->createCandidate([
            'nomor_induk' => 'K-2026-00004',
            'nama_alphabet' => 'Gagal Gilang',
        ]), ['status_wawancara' => 'Tidak Lolos']);

        $maker = $this->maker();
        $payload = app(InterviewQueryService::class)->detail($maker, $containerId);

        $this->assertSame(2, (int) $payload['container']->jumlah_peserta);
        $this->assertSame(2, (int) app(InterviewQueryService::class)->paginate($maker)->first()->jumlah_peserta);
        $this->assertSame(0, (int) DB::table('interview_container')->where('id', $containerId)->value('jumlah_peserta'));

        $this->actingAs($maker)
            ->get('/jobs/'.$containerId)
            ->assertOk();
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

    // ----- W3 form -----

    public function test_create_page_forbids_non_execute_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/jobs/create')->assertForbidden();
        }

        $this->actingAs($this->maker())->get('/jobs/create')->assertOk();
    }

    public function test_create_draft_via_form(): void
    {
        $maker = $this->maker();

        Livewire::actingAs($maker)
            ->test(InterviewForm::class)
            ->set('judul', 'Wawancara Mei')
            ->set('perusahaanId', (string) $this->perusahaanId)
            ->set('posisiPekerjaanId', (string) $this->posisiId)
            ->set('jenisWawancara', 'ONLINE')
            ->set('jenisVisaId', (string) $this->visaId)
            ->set('tanggalWawancara', '2026-08-15')
            ->call('saveDraft')
            ->assertRedirect();

        $row = DB::table('interview_container')->where('judul', 'Wawancara Mei')->first();
        $this->assertNotNull($row);
        $this->assertSame('Draft', $row->status);
        $this->assertNull($row->kode_kontainer);
        $this->assertSame(0, DB::table('pending_request')->where('target_id', $row->id)->count());
    }

    public function test_submit_creates_code_and_pending(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id])
            ->call('submit')
            ->assertRedirect(route('jobs.show', $id));

        $row = DB::table('interview_container')->where('id', $id)->first();
        $this->assertSame('Menunggu Approval', $row->status);
        $this->assertMatchesRegularExpression('/^W-\d{4}-\d{5}$/', (string) $row->kode_kontainer);
        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_edit_page_loads_draft_fields(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id])
            ->assertSet('judul', 'Wawancara Batch April')
            ->assertSet('perusahaanId', (string) $this->perusahaanId)
            ->assertSet('jenisWawancara', 'ONLINE')
            ->assertSet('readonly', false)
            ->assertSet('canCancel', true);
    }

    public function test_update_draft_changes_fields(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id])
            ->set('judul', 'Judul Revisi')
            ->set('targetPesertaDiterima', '7')
            ->call('saveDraft')
            ->assertRedirect(route('jobs.show', $id));

        $row = DB::table('interview_container')->where('id', $id)->first();
        $this->assertSame('Judul Revisi', $row->judul);
        $this->assertSame(7, $row->target_peserta_diterima);
        $this->assertSame(1, $row->version);
    }

    public function test_cancel_draft(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id])
            ->call('cancel')
            ->assertRedirect(route('jobs.index'));

        $this->assertSame('Dibatalkan', DB::table('interview_container')->where('id', $id)->value('status'));
    }

    public function test_cancel_pending_approval(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);

        Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id])
            ->assertSet('readonly', true)
            ->assertSet('canCancel', true)
            ->call('cancel')
            ->assertRedirect(route('jobs.index'));

        $this->assertSame('Dibatalkan', DB::table('interview_container')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_version_conflict_shows_banner(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);

        $component = Livewire::actingAs($maker)
            ->test(InterviewForm::class, ['containerId' => $id]);
        $component->set('judul', 'Versi Basi');

        DB::table('interview_container')->where('id', $id)->update(['version' => 9]);

        $component->call('saveDraft')
            ->assertSet('conflict', true);
    }

    // ----- W4 approval queue -----

    public function test_review_queue_forbids_non_review_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->maker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/jobs/review')->assertForbidden();
        }

        $this->actingAs($this->checker())->get('/jobs/review')->assertOk();
    }

    public function test_review_queue_lists_pending_create(): void
    {
        $maker = $this->maker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);

        $this->actingAs($this->checker())
            ->get('/jobs/review')
            ->assertOk()
            ->assertSee('Wawancara Batch April')
            ->assertSee('Setujui');
    }

    public function test_approve_from_queue_activates_container(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewReviewQueue::class)
            ->call('approve', $pendingId, 1)
            ->assertRedirect(route('jobs.review'));

        $row = DB::table('interview_container')->where('id', $id)->first();
        $this->assertSame('Aktif', $row->status);
        $this->assertSame((int) $checker->id, (int) $row->disetujui_oleh);
        $this->assertSame(0, DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_reject_from_queue_requires_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewReviewQueue::class)
            ->call('startReject', $pendingId)
            ->call('reject', $pendingId, 1)
            ->assertSet('actionError', __('ui.jobs.queue.note_required'));

        $this->assertSame('Menunggu Approval', DB::table('interview_container')->where('id', $id)->value('status'));
    }

    public function test_reject_from_queue_returns_to_draft_and_keeps_code(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->value('id');
        $code = DB::table('interview_container')->where('id', $id)->value('kode_kontainer');

        Livewire::actingAs($checker)
            ->test(InterviewReviewQueue::class)
            ->call('startReject', $pendingId)
            ->set('rejectNote', 'Data kurang lengkap')
            ->call('reject', $pendingId, 1)
            ->assertRedirect(route('jobs.review'));

        $row = DB::table('interview_container')->where('id', $id)->first();
        $this->assertSame('Draft', $row->status);
        $this->assertSame($code, $row->kode_kontainer);
        $this->assertNull($row->disetujui_oleh);
        $this->assertSame(0, DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_double_decision_shows_conflict(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->value('id');

        $component = Livewire::actingAs($checker)->test(InterviewReviewQueue::class);
        $component->call('approve', $pendingId, 1);
        $component->call('approve', $pendingId, 1)
            ->assertSet('conflict', true);
    }

    public function test_approve_from_detail_activates_container(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CREATE')
            ->where('target_id', $id)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $id])
            ->call('approveCreate', $pendingId)
            ->assertRedirect(route('jobs.show', $id));

        $this->assertSame('Aktif', DB::table('interview_container')->where('id', $id)->value('status'));
    }

    // ----- W6 bulk pull -----

    public function test_pull_picker_requires_jobs_execute(): void
    {
        $this->expectException(AuthorizationException::class);

        app(CandidateQueryService::class)->interviewPullPicker($this->noRoleUser());
    }

    public function test_pull_picker_lists_tersedia_and_disabled_in_use(): void
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

        $result = app(CandidateQueryService::class)->interviewPullPicker($maker);

        $this->assertSame(2, $result->total());
        $statuses = collect($result->items())->pluck('status_ketersediaan')->sort()->values()->all();
        $this->assertSame(['SEDANG_DIPAKAI', 'TERSEDIA'], $statuses);
    }

    public function test_pull_creates_participations_and_marks_in_use(): void
    {
        $maker = $this->maker();
        $first = $this->createCandidate();
        $second = $this->createCandidate([
            'nomor_induk' => 'K-2026-00002',
            'nama_alphabet' => 'Siti Aminah',
        ]);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewPull::class, ['containerId' => $containerId])
            ->set('selected', [$first => $first, $second => $second])
            ->call('pullCandidates')
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame(2, DB::table('participation')
            ->where('interview_container_id', $containerId)
            ->count());
        $this->assertSame(2, DB::table('participation')
            ->where('interview_container_id', $containerId)
            ->where('status_wawancara', 'Menunggu Wawancara')
            ->count());
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $first)->value('status_ketersediaan'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $second)->value('status_ketersediaan'));
    }

    public function test_pull_batch_fails_when_one_ineligible(): void
    {
        $maker = $this->maker();
        $eligible = $this->createCandidate();
        $inUse = $this->createCandidate([
            'nomor_induk' => 'K-2026-00002',
            'nama_alphabet' => 'Siti Aminah',
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
        ]);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewPull::class, ['containerId' => $containerId])
            ->set('selected', [$eligible => $eligible, $inUse => $inUse])
            ->call('pullCandidates')
            ->assertSet('actionError', __('ui.jobs.errors.CANDIDATE_NOT_AVAILABLE'));

        $this->assertSame(0, DB::table('participation')
            ->where('interview_container_id', $containerId)
            ->count());
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $eligible)->value('status_ketersediaan'));
    }

    public function test_pull_rejects_more_than_fifty(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewPull::class, ['containerId' => $containerId])
            ->set('selected', array_fill_keys(range(1, 51), 1))
            ->call('pullCandidates')
            ->assertSet('actionError', __('ui.jobs.pull.max_reached'));

        $this->assertSame(0, DB::table('participation')
            ->where('interview_container_id', $containerId)
            ->count());
    }

    public function test_pull_panel_hidden_for_checker(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        $this->actingAs($this->checker())
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertDontSee('Tarik Kandidat');
    }

    // ----- W7 natural status updates -----

    public function test_status_advance_buttons_visible_for_maker_without_terkirim(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->addParticipation($containerId, $candidateId);

        $this->actingAs($maker)
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertSee('Lulus')
            ->assertSee('Tidak Lolos')
            ->assertSee('Mengundurkan Diri')
            ->assertDontSee('Terkirim');
    }

    public function test_update_status_advances_participation(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('updateParticipationStatus', $participationId, 'Lulus', 0)
            ->assertRedirect(route('jobs.show', $containerId));

        $row = DB::table('participation')->where('id', $participationId)->first();
        $this->assertSame('Lulus', $row->status_wawancara);
        $this->assertSame(1, $row->version);
    }

    public function test_terminal_status_releases_availability(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('updateParticipationStatus', $participationId, 'Tidak Lolos', 0)
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Tidak Lolos', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_status_actions_hidden_when_container_closed(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Ditutup',
            'dibuat_oleh' => $maker->id,
            'closed_at' => now(),
        ]);
        $this->addParticipation($containerId, $candidateId, ['frozen_at' => now()]);

        $this->actingAs($maker)
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertDontSee('Aksi')
            ->assertDontSee('Tidak Lolos');
    }

    public function test_update_status_shows_version_conflict(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        DB::table('participation')->where('id', $participationId)->update(['version' => 9]);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('updateParticipationStatus', $participationId, 'Lulus', 0)
            ->assertSet('conflict', true);
    }

    // ----- W8 expel -----

    private function elevateExpelFor(int $participationId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_CANDIDATE_EXPEL.'.participation.'.$participationId => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    private function elevateCloseFor(int $containerId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$containerId => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    public function test_expel_request_requires_reason(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelRequest', $participationId)
            ->call('requestExpel', $participationId, 0)
            ->assertSet('actionError', __('ui.jobs.expel.reason_required'));

        $this->assertSame(0, DB::table('pending_request')->where('type', 'IC_EXPEL')->count());
    }

    public function test_expel_request_creates_pending_without_changing_participation(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelRequest', $participationId)
            ->set('expelReason', 'Dokumen tidak lengkap')
            ->call('requestExpel', $participationId, 0)
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'IC_EXPEL')
            ->where('target_type', 'participation')
            ->where('target_id', $participationId)
            ->where('status', 'pending')
            ->count());
        $this->assertSame('Menunggu Wawancara', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_expel_approve_requires_step_up_then_executes_on_success(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewParticipationService::class)->requestExpel($maker, $participationId, 'Dokumen tidak lengkap', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_EXPEL')
            ->where('target_id', $participationId)
            ->value('id');

        $component = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelApprove', $pendingId)
            ->set('expelApproveNote', 'Setuju, dokumen final menyusul')
            ->call('approveExpel', $pendingId, $participationId)
            ->assertDispatched('stepup.open');

        $this->assertSame('Menunggu Wawancara', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));

        $this->elevateExpelFor($participationId);

        $component->dispatch('stepup.success',
            action: StepUpAction::APPROVE_CANDIDATE_EXPEL,
            entityType: 'participation',
            entityId: $participationId,
        )->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Dikeluarkan', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_expel_approve_executes_immediately_with_valid_elevation(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewParticipationService::class)->requestExpel($maker, $participationId, 'Dokumen tidak lengkap', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_EXPEL')
            ->where('target_id', $participationId)
            ->value('id');
        $this->elevateExpelFor($participationId);

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelApprove', $pendingId)
            ->set('expelApproveNote', 'Setuju, dokumen final menyusul')
            ->call('approveExpel', $pendingId, $participationId)
            ->assertNotDispatched('stepup.open')
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Dikeluarkan', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));
    }

    public function test_expel_reject_requires_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewParticipationService::class)->requestExpel($maker, $participationId, 'Dokumen tidak lengkap', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_EXPEL')
            ->where('target_id', $participationId)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelReject', $pendingId)
            ->call('rejectExpel', $pendingId)
            ->assertSet('actionError', __('ui.jobs.expel.note_required'));

        $this->assertSame('pending', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_expel_reject_with_note_keeps_participation(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewParticipationService::class)->requestExpel($maker, $participationId, 'Dokumen tidak lengkap', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_EXPEL')
            ->where('target_id', $participationId)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startExpelReject', $pendingId)
            ->set('expelRejectNote', 'Alasan kurang jelas')
            ->call('rejectExpel', $pendingId)
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
        $this->assertSame('Menunggu Wawancara', DB::table('participation')->where('id', $participationId)->value('status_wawancara'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_expel_overlay_visible_for_checker(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewParticipationService::class)->requestExpel($maker, $participationId, 'Dokumen tidak lengkap', ['version' => 0]);

        $this->actingAs($this->checker())
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertSee('Persetujuan pengeluaran kandidat')
            ->assertSee('Setujui');
    }

    // ----- W5 close -----

    public function test_close_request_requires_reason(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseRequest')
            ->call('requestClose')
            ->assertSet('actionError', __('ui.jobs.close.reason_required'));

        $this->assertSame(0, DB::table('pending_request')->where('type', 'IC_CLOSE')->count());
    }

    public function test_close_request_creates_pending_and_stays_active(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseRequest')
            ->set('closeReason', 'Batch selesai')
            ->call('requestClose')
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Aktif', DB::table('interview_container')->where('id', $containerId)->value('status'));
        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $containerId)
            ->where('status', 'pending')
            ->count());
    }

    public function test_close_approve_requires_step_up_then_executes(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $participationId = $this->addParticipation($containerId, $candidateId);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->requestClose($maker, $containerId, 'Batch selesai', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $containerId)
            ->value('id');

        $component = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseApprove', $pendingId)
            ->set('closeApproveNote', 'Setuju, semua berkas beres')
            ->call('approveClose', $pendingId)
            ->assertDispatched('stepup.open');

        $this->assertSame('Aktif', DB::table('interview_container')->where('id', $containerId)->value('status'));

        $this->elevateCloseFor($containerId);

        $component->dispatch('stepup.success',
            action: StepUpAction::APPROVE_INTERVIEW_CLOSE,
            entityType: 'interview_container',
            entityId: $containerId,
        )->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Ditutup', DB::table('interview_container')->where('id', $containerId)->value('status'));
        $participation = DB::table('participation')->where('id', $participationId)->first();
        $this->assertNotNull($participation->frozen_at);
        $this->assertSame('Menunggu Wawancara', $participation->status_wawancara);
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_close_approve_executes_immediately_with_valid_elevation(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->requestClose($maker, $containerId, 'Batch selesai', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $containerId)
            ->value('id');
        $this->elevateCloseFor($containerId);

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseApprove', $pendingId)
            ->set('closeApproveNote', 'Setuju')
            ->call('approveClose', $pendingId)
            ->assertNotDispatched('stepup.open')
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Ditutup', DB::table('interview_container')->where('id', $containerId)->value('status'));
    }

    public function test_close_reject_requires_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->requestClose($maker, $containerId, 'Batch selesai', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $containerId)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseReject', $pendingId)
            ->call('rejectClose', $pendingId)
            ->assertSet('actionError', __('ui.jobs.close.note_required'));

        $this->assertSame('Aktif', DB::table('interview_container')->where('id', $containerId)->value('status'));
        $this->assertSame('pending', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_close_reject_with_note_keeps_active(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->requestClose($maker, $containerId, 'Batch selesai', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $containerId)
            ->value('id');

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startCloseReject', $pendingId)
            ->set('closeRejectNote', 'Masih ada dokumen kurang')
            ->call('rejectClose', $pendingId)
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame('Aktif', DB::table('interview_container')->where('id', $containerId)->value('status'));
        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_repull_after_close_proves_r1(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate(['status_ketersediaan' => 'SEDANG_DIPAKAI']);
        $firstContainer = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->addParticipation($firstContainer, $candidateId);
        $this->actingAs($maker);
        app(InterviewContainerService::class)->requestClose($maker, $firstContainer, 'Batch selesai', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'IC_CLOSE')
            ->where('target_id', $firstContainer)
            ->value('id');
        $this->actingAs($checker);
        $this->elevateCloseFor($firstContainer);
        app(InterviewContainerService::class)->approveClose($checker, $pendingId, 'Setuju');

        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));

        $secondContainer = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewPull::class, ['containerId' => $secondContainer])
            ->set('selected', [$candidateId => $candidateId])
            ->call('pullCandidates')
            ->assertRedirect(route('jobs.show', $secondContainer));

        $this->assertSame(1, DB::table('participation')
            ->where('interview_container_id', $secondContainer)
            ->where('candidate_id', $candidateId)
            ->where('status_wawancara', 'Menunggu Wawancara')
            ->count());
    }

    // ----- W9 guest link (internal) -----

    public function test_guest_request_rejects_past_expiry(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startGuestRequest')
            ->set('guestLabel', 'Link batch April')
            ->set('guestExpiresAt', '2020-01-01')
            ->call('requestGuestLink')
            ->assertSet('actionError', __('ui.jobs.errors.GUEST_EXPIRY_PAST'));

        $this->assertSame(0, DB::table('pending_request')->where('type', 'GUEST_LINK')->count());
        $this->assertSame(0, DB::table('guest_link')->count());
    }

    public function test_guest_request_creates_pending_without_token_or_row(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);

        Livewire::actingAs($maker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startGuestRequest')
            ->set('guestLabel', 'Link batch April')
            ->set('guestExpiresAt', '2026-12-31')
            ->set('guestAdditionalCode', 'ABC123')
            ->call('requestGuestLink')
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'GUEST_LINK')
            ->where('target_id', $containerId)
            ->where('status', 'pending')
            ->count());
        $this->assertSame(0, DB::table('guest_link')->count());
    }

    public function test_guest_approve_shows_token_once_and_stores_hash(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $containerId, [
            'label' => 'Link batch April',
            'expires_at' => '2026-12-31',
            'additional_code' => null,
            'version' => 0,
        ]);

        $component = Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('approveGuestLink', (int) $request->getKey())
            ->assertSet('guestToken', fn (?string $token): bool => is_string($token) && strlen($token) === 64);

        $token = $component->get('guestToken');
        $row = DB::table('guest_link')->first();
        $this->assertNotNull($row);
        $this->assertSame('Aktif', $row->status_link);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
    }

    public function test_guest_reject_requires_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $containerId, [
            'label' => 'Link batch April',
            'expires_at' => '2026-12-31',
            'additional_code' => null,
            'version' => 0,
        ]);

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startGuestReject', (int) $request->getKey())
            ->call('rejectGuestLink', (int) $request->getKey())
            ->assertSet('actionError', __('ui.jobs.guest.note_required'));

        $this->assertSame(0, DB::table('guest_link')->count());
        $this->assertSame('pending', DB::table('pending_request')->where('id', $request->getKey())->value('status'));
    }

    public function test_guest_reject_with_note_creates_no_token(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $containerId = $this->createContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        $request = app(GuestLinkService::class)->requestGuestLink($maker, $containerId, [
            'label' => 'Link batch April',
            'expires_at' => '2026-12-31',
            'additional_code' => null,
            'version' => 0,
        ]);

        Livewire::actingAs($checker)
            ->test(InterviewDetail::class, ['containerId' => $containerId])
            ->call('startGuestReject', (int) $request->getKey())
            ->set('guestRejectNote', 'Tidak diperlukan')
            ->call('rejectGuestLink', (int) $request->getKey())
            ->assertRedirect(route('jobs.show', $containerId));

        $this->assertSame(0, DB::table('guest_link')->count());
        $this->assertSame('rejected', DB::table('pending_request')->where('id', $request->getKey())->value('status'));
    }

    public function test_guest_request_hidden_for_non_active_container(): void
    {
        $maker = $this->maker();
        $containerId = $this->createContainer(['dibuat_oleh' => $maker->id]);

        $this->actingAs($maker)
            ->get('/jobs/'.$containerId)
            ->assertOk()
            ->assertDontSee('Ajukan Link Tamu');
    }
}
