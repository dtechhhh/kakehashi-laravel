<?php

namespace Tests\Feature\UI;

use App\Livewire\Placement\PlacementBatchPanel;
use App\Livewire\Placement\PlacementDetail;
use App\Livewire\Placement\PlacementForceMajeurPanel;
use App\Livewire\Placement\PlacementForm;
use App\Livewire\Placement\PlacementIndex;
use App\Livewire\Placement\PlacementReviewQueue;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementBatchService;
use Modules\Placement\Services\PlacementContainerService;
use Modules\Placement\Services\PlacementForceMajeurService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PlacementScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedPlacementReferences();
        $this->seedNegara();
    }

    protected int $companyId;

    private function seedPlacementReferences(): void
    {
        $this->companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W5 配属会社',
            'nama_romaji' => 'W5 Haizoku Kaisha',
            'nama_id' => 'Perusahaan W5',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedNegara(): void
    {
        DB::table('negara')->insertOrIgnore([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function maker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::ASSISTANT_MANAGER);

        return $user;
    }

    protected function checker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::JOB_MANAGER);

        return $this->withTwoFactor($user);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        return $this->withTwoFactor($user);
    }

    protected function noRoleUser(): User
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

    protected function createPlacementContainer(array $overrides = []): int
    {
        return (int) DB::table('placement_container')->insertGetId(array_merge([
            'kode_kontainer' => null,
            'nama' => 'Kontainer April',
            'perusahaan_id' => $this->companyId,
            'status' => 'Draft',
            'dibuat_oleh' => $this->maker()->id,
            'disetujui_oleh' => null,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => null,
            'archived_at' => null,
            'updated_at' => now(),
        ], $overrides));
    }

    protected function createCompany(string $namaJa): int
    {
        return (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => $namaJa,
            'nama_romaji' => 'Romaji '.$namaJa,
            'nama_id' => 'ID '.$namaJa,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createCandidate(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => sprintf('K-2026-%05d', $sequence),
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => Carbon::parse('1998-05-10'),
            'kewarganegaraan_id' => (int) DB::table('negara')->where('code', 'ID')->value('id'),
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker()->id,
            'approved_by' => $this->checker()->id,
            'deleted_at' => null,
            'pii_anonymized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function addParticipant(int $containerId, int $candidateId, array $overrides = []): int
    {
        return (int) DB::table('placement_participants')->insertGetId(array_merge([
            'placement_container_id' => $containerId,
            'candidate_id' => $candidateId,
            'source_participation_id' => 1,
            'kategori_force_majeur_id' => null,
            'alasan_force_majeur' => null,
            'jenis_visa_id' => $this->visaId(),
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => 'Bekerja',
            'tanggal_status_final' => null,
            'catatan_alasan' => null,
            'disetujui_oleh' => $this->checker()->id,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function visaId(): int
    {
        DB::table('jenis_visa')->insertOrIgnore([
            'code' => 'W5_SSW',
            'label_id' => 'Visa W5',
            'label_ja' => 'W5ビザ',
            'kategori' => 'SSW',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('jenis_visa')->where('code', 'W5_SSW')->value('id');
    }

    private function positionId(): int
    {
        return (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W5_ENGINEER',
            'label_id' => 'Teknisi W5',
            'label_ja' => 'W5技術者',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function interviewContainerId(): int
    {
        return (int) DB::table('interview_container')->insertGetId([
            'kode_kontainer' => 'W-2026-00001',
            'judul' => 'W5 Interview Container',
            'perusahaan_id' => $this->companyId,
            'posisi_pekerjaan_id' => $this->positionId(),
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $this->visaId(),
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 10,
            'deskripsi' => 'Synthetic fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $this->maker()->id,
            'disetujui_oleh' => $this->checker()->id,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{candidate_id: int, participation_id: int}
     */
    private function batchReadyCandidate(array $candidateOverrides = []): array
    {
        $candidateId = $this->createCandidate(array_merge([
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
        ], $candidateOverrides));
        $participationId = (int) DB::table('participation')->insertGetId([
            'interview_container_id' => $this->interviewContainerId(),
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Siap Dikirim',
            'catatan' => null,
            'frozen_at' => null,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'candidate_id' => $candidateId,
            'participation_id' => $participationId,
        ];
    }

    private function kategoriFmId(): int
    {
        return (int) DB::table('kategori_force_majeur')->insertGetId([
            'code' => 'FM_HEALTH',
            'label_id' => 'Kesehatan',
            'label_ja' => '健康',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_paginate_requires_placement_view(): void
    {
        $this->expectException(AuthorizationException::class);

        app(PlacementQueryService::class)->paginate($this->noRoleUser());
    }

    public function test_list_page_opens_for_placement_roles(): void
    {
        $this->createPlacementContainer([
            'kode_kontainer' => 'P-2026-00001',
            'nama' => 'Kontainer April',
        ]);

        foreach ([$this->maker(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)
                ->get('/placements')
                ->assertOk()
                ->assertSee('Kontainer Penempatan')
                ->assertSee('Kontainer April');
        }
    }

    public function test_list_page_forbids_user_without_placement_view(): void
    {
        $this->actingAs($this->noRoleUser())->get('/placements')->assertForbidden();
    }

    public function test_list_page_redirects_guest(): void
    {
        $this->get('/placements')->assertRedirect();
    }

    public function test_create_page_forbids_non_execute_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/placements/create')->assertForbidden();
        }

        $this->actingAs($this->maker())->get('/placements/create')->assertOk();
    }

    public function test_review_page_forbids_non_review_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->maker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/placements/review')->assertForbidden();
        }

        $this->actingAs($this->checker())->get('/placements/review')->assertOk();
    }

    public function test_detail_and_edit_routes_enforce_ability(): void
    {
        $id = $this->createPlacementContainer();

        $this->actingAs($this->noRoleUser())->get('/placements/'.$id)->assertForbidden();
        $this->actingAs($this->noRoleUser())->get('/placements/'.$id.'/edit')->assertForbidden();
    }

    public function test_detail_returns_null_for_missing_container(): void
    {
        $this->assertNull(app(PlacementQueryService::class)->detail($this->maker(), 999999));
    }

    public function test_detail_page_renders_participants_and_pending_overlay(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createPlacementContainer([
            'kode_kontainer' => 'P-2026-00002',
            'nama' => 'Kontainer Aktif',
            'status' => 'Aktif',
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);
        $this->addParticipant($containerId, $candidateId);
        DB::table('pending_request')->insert([
            'type' => 'PC_CANCEL_ACTIVE',
            'target_type' => 'placement_container',
            'target_id' => $containerId,
            'requested_by' => $maker->id,
            'reason_maker' => 'Tidak jadi',
            'checker_id' => null,
            'note_checker' => null,
            'payload' => json_encode(['snapshot' => ['version' => 0]]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker)
            ->get('/placements/'.$containerId)
            ->assertOk()
            ->assertSee('Kontainer Aktif')
            ->assertSee('Budi Santoso')
            ->assertSee('Bekerja')
            ->assertSee('Pembatalan kontainer aktif');
    }

    public function test_detail_page_shows_not_found_state(): void
    {
        $this->actingAs($this->maker())
            ->get('/placements/999999')
            ->assertOk()
            ->assertSee('Kontainer tidak ditemukan');
    }

    public function test_detail_page_forbids_user_without_placement_view(): void
    {
        $containerId = $this->createPlacementContainer();

        $this->actingAs($this->noRoleUser())->get('/placements/'.$containerId)->assertForbidden();
    }

    public function test_archived_detail_is_read_only(): void
    {
        $containerId = $this->createPlacementContainer([
            'nama' => 'Kontainer Arsip',
            'status' => 'Arsip',
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
            'archived_at' => now(),
        ]);

        $this->actingAs($this->maker())
            ->get('/placements/'.$containerId)
            ->assertOk()
            ->assertSee('Kontainer Arsip')
            ->assertSee('hanya dapat dilihat');
    }

    // ----- P3 draft form -----

    public function test_create_draft_via_form(): void
    {
        $maker = $this->maker();

        Livewire::actingAs($maker)
            ->test(PlacementForm::class)
            ->set('nama', 'Kontainer Mei')
            ->set('perusahaanId', (string) $this->companyId)
            ->call('saveDraft')
            ->assertRedirect();

        $row = DB::table('placement_container')->where('nama', 'Kontainer Mei')->first();
        $this->assertNotNull($row);
        $this->assertSame('Draft', $row->status);
        $this->assertNull($row->kode_kontainer);
        $this->assertSame(0, DB::table('pending_request')->where('target_id', $row->id)->count());
    }

    public function test_submit_creates_code_and_pending(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->call('submit')
            ->assertRedirect(route('placements.show', $id));

        $row = DB::table('placement_container')->where('id', $id)->first();
        $this->assertSame('Menunggu Approval', $row->status);
        $this->assertMatchesRegularExpression('/^P-\d{4}-\d{5}$/', (string) $row->kode_kontainer);
        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'PC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_edit_page_loads_draft_fields(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->assertSet('nama', 'Kontainer April')
            ->assertSet('perusahaanId', (string) $this->companyId)
            ->assertSet('readonly', false)
            ->assertSet('canCancel', true);
    }

    public function test_update_draft_changes_fields(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->set('nama', 'Nama Revisi')
            ->call('saveDraft')
            ->assertRedirect(route('placements.show', $id));

        $row = DB::table('placement_container')->where('id', $id)->first();
        $this->assertSame('Nama Revisi', $row->nama);
        $this->assertSame(1, $row->version);
    }

    public function test_update_draft_rejects_company_change(): void
    {
        $maker = $this->maker();
        $otherCompany = $this->createCompany('Perusahaan Lain');
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->set('perusahaanId', (string) $otherCompany)
            ->call('saveDraft')
            ->assertSet('actionError', __('ui.placement.errors.PC_COMPANY_IMMUTABLE'));

        $this->assertSame($this->companyId, (int) DB::table('placement_container')->where('id', $id)->value('perusahaan_id'));
    }

    public function test_cancel_draft(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->call('cancel')
            ->assertRedirect(route('placements.index'));

        $this->assertSame('Dibatalkan', DB::table('placement_container')->where('id', $id)->value('status'));
    }

    public function test_cancel_pending_approval(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->submit($maker, $id, ['version' => 0]);

        Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id])
            ->assertSet('readonly', true)
            ->assertSet('canCancel', true)
            ->call('cancel')
            ->assertRedirect(route('placements.index'));

        $this->assertSame('Dibatalkan', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('pending_request')
            ->where('type', 'PC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_version_conflict_shows_banner(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer(['dibuat_oleh' => $maker->id]);

        $component = Livewire::actingAs($maker)
            ->test(PlacementForm::class, ['containerId' => $id]);
        $component->set('nama', 'Versi Basi');

        DB::table('placement_container')->where('id', $id)->update(['version' => 9]);

        $component->call('saveDraft')
            ->assertSet('conflict', true);
    }

    // ----- GAP-4: cancel active empty container -----

    public function test_maker_requests_cancel_active_keeps_container_active(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(PlacementDetail::class, ['containerId' => $id])
            ->call('startCancelRequest')
            ->set('cancelReason', 'Kebutuhan tertutup')
            ->call('requestCancelActive')
            ->assertRedirect(route('placements.show', $id));

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_cancel_active_button_hidden_when_participants_exist(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);
        $this->addParticipant($id, $candidateId);

        $this->actingAs($maker)
            ->get('/placements/'.$id)
            ->assertOk()
            ->assertDontSee('Ajukan pembatalan kontainer');
    }

    public function test_cancel_active_button_hidden_when_not_maker(): void
    {
        $maker = $this->maker();
        $otherMaker = User::factory()->active()->create();
        $otherMaker->assignRole(Rbac::ASSISTANT_MANAGER);
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($otherMaker)
            ->get('/placements/'.$id)
            ->assertOk()
            ->assertDontSee('Ajukan pembatalan kontainer');
    }

    public function test_checker_approves_cancel_active_from_detail(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->requestCancelActive($maker, $id, 'Tidak jadi', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementDetail::class, ['containerId' => $id])
            ->call('approveCancelActive', $pendingId)
            ->assertRedirect(route('placements.show', $id));

        $this->assertSame('Dibatalkan', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame('approved', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_checker_reject_cancel_active_requires_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->requestCancelActive($maker, $id, null, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementDetail::class, ['containerId' => $id])
            ->call('startCancelReject', $pendingId)
            ->call('rejectCancelActive', $pendingId)
            ->assertSet('actionError', __('ui.placement.cancel_active.note_required'));

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame('pending', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_checker_reject_cancel_active_keeps_active(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->requestCancelActive($maker, $id, 'Batal saja', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementDetail::class, ['containerId' => $id])
            ->call('startCancelReject', $pendingId)
            ->set('cancelRejectNote', 'Kontrak masih berjalan')
            ->call('rejectCancelActive', $pendingId)
            ->assertRedirect(route('placements.show', $id));

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    // ----- Checker review queue -----

    private function addPlacementPending(string $type, int $containerId, ?array $payload = null): int
    {
        $makerId = $this->maker()->id;

        return (int) DB::table('pending_request')->insertGetId([
            'type' => $type,
            'target_type' => 'placement_container',
            'target_id' => $containerId,
            'requested_by' => $makerId,
            'reason_maker' => 'Alasan uji',
            'checker_id' => null,
            'note_checker' => null,
            'payload' => $payload === null ? null : json_encode($payload),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_review_queue_lists_pending_create_and_cancel_active(): void
    {
        $maker = $this->maker();
        $createId = $this->createPlacementContainer([
            'nama' => 'Kontainer Menunggu',
            'dibuat_oleh' => $maker->id,
            'status' => 'Menunggu Approval',
            'kode_kontainer' => 'P-2026-00003',
        ]);
        $cancelId = $this->createPlacementContainer([
            'nama' => 'Kontainer Batal',
            'dibuat_oleh' => $maker->id,
            'status' => 'Aktif',
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);
        $this->addPlacementPending('PC_CREATE', $createId);
        $this->addPlacementPending('PC_CANCEL_ACTIVE', $cancelId, ['snapshot' => ['version' => 0]]);

        $this->actingAs($this->checker())
            ->get('/placements/review')
            ->assertOk()
            ->assertSee('Kontainer Menunggu')
            ->assertSee('Kontainer Batal')
            ->assertSee('Persetujuan pembuatan kontainer')
            ->assertSee('Pembatalan kontainer aktif');
    }

    public function test_review_queue_approve_create_activates_container(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'nama' => 'Kontainer Approve',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('approve', $pendingId, 'PC_CREATE', 1)
            ->assertRedirect(route('placements.review'));

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame('approved', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_review_queue_reject_create_requires_note_and_returns_draft(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'nama' => 'Kontainer Reject',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->call('reject', $pendingId, 'PC_CREATE', 1)
            ->assertSet('actionError', __('ui.placement.queue.note_required'));

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->set('rejectNote', 'Lengkapi data')
            ->call('reject', $pendingId, 'PC_CREATE', 1)
            ->assertRedirect(route('placements.review'));

        $this->assertSame('Draft', DB::table('placement_container')->where('id', $id)->value('status'));
        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_review_queue_approve_cancel_active_cancels_container(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'nama' => 'Kontainer Cancel',
            'dibuat_oleh' => $maker->id,
            'status' => 'Aktif',
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->requestCancelActive($maker, $id, 'Batal', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('approve', $pendingId, 'PC_CANCEL_ACTIVE', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertSame('Dibatalkan', DB::table('placement_container')->where('id', $id)->value('status'));
    }

    public function test_review_queue_reject_cancel_active_keeps_active(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $id = $this->createPlacementContainer([
            'nama' => 'Kontainer Reject Cancel',
            'dibuat_oleh' => $maker->id,
            'status' => 'Aktif',
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->requestCancelActive($maker, $id, 'Batal', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CANCEL_ACTIVE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->set('rejectNote', 'Tidak disetujui')
            ->call('reject', $pendingId, 'PC_CANCEL_ACTIVE', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertSame('Aktif', DB::table('placement_container')->where('id', $id)->value('status'));
    }

    public function test_review_queue_self_approve_is_denied(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer([
            'nama' => 'Kontainer Self',
            'dibuat_oleh' => $maker->id,
        ]);
        $this->actingAs($maker);
        app(PlacementContainerService::class)->submit($maker, $id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', 'PC_CREATE')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->value('id');
        $maker->givePermissionTo('placement.review');

        Livewire::actingAs($maker)
            ->test(PlacementReviewQueue::class)
            ->call('approve', $pendingId, 'PC_CREATE', 1)
            ->assertSet('actionError', __('ui.placement.errors.APV_SELF'));

        $this->assertSame('Menunggu Approval', DB::table('placement_container')->where('id', $id)->value('status'));
    }

    // ----- P4 batch submit (Maker) -----

    public function test_batch_panel_lists_only_siap_dikirim_sedang_dipakai(): void
    {
        $maker = $this->maker();
        $ready = $this->batchReadyCandidate();
        $this->createCandidate([
            'nomor_induk' => 'K-2026-00999',
            'nama_alphabet' => 'Tersedia Tono',
            'status_ketersediaan' => 'TERSEDIA',
        ]);
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        $component = Livewire::actingAs($maker)
            ->test(PlacementBatchPanel::class, ['containerId' => $id, 'version' => 0])
            ->assertSee('Eligible: Siap Dikirim + Sedang Dipakai')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Tersedia Tono');
    }

    public function test_batch_submit_creates_pending_and_leaves_source_untouched(): void
    {
        $maker = $this->maker();
        $ready = $this->batchReadyCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);
        $visaId = $this->visaId();

        Livewire::actingAs($maker)
            ->test(PlacementBatchPanel::class, ['containerId' => $id, 'version' => 0])
            ->call('toggle', $ready['candidate_id'], $ready['participation_id'], $visaId)
            ->set('rows.'.$ready['candidate_id'].'.tanggal_mulai_kerja', '2026-09-01')
            ->set('rows.'.$ready['candidate_id'].'.durasi_kontrak_bulan', 12)
            ->call('submitBatch')
            ->assertRedirect(route('placements.show', $id));

        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'PLACEMENT_BATCH')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
        $this->assertSame('Siap Dikirim', DB::table('participation')->where('id', $ready['participation_id'])->value('status_wawancara'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $ready['candidate_id'])->value('status_ketersediaan'));
    }

    public function test_batch_submit_rejects_tersedia_candidate_server_side(): void
    {
        $maker = $this->maker();
        $ready = $this->batchReadyCandidate(['status_ketersediaan' => 'TERSEDIA']);
        $this->actingAs($maker);
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        try {
            app(PlacementBatchService::class)
                ->submitBatch($maker, $id, [[
                    'candidate_id' => $ready['candidate_id'],
                    'source_participation_id' => $ready['participation_id'],
                    'jenis_visa_id' => $this->visaId(),
                    'tanggal_mulai_kerja' => '2026-09-01',
                    'durasi_kontrak_bulan' => 12,
                    'tanggal_berakhir_kontrak' => null,
                ]], ['version' => 0]);
            $this->fail('A Tersedia candidate must be rejected by the service.');
        } catch (ValidationException $exception) {
            $this->assertContains('CANDIDATE_NOT_IN_USE', collect($exception->errors())->flatten()->all());
        }

        $this->assertSame(0, DB::table('pending_request')
            ->where('type', 'PLACEMENT_BATCH')
            ->where('target_id', $id)
            ->count());
    }

    public function test_batch_panel_caps_at_fifty(): void
    {
        $maker = $this->maker();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[$i] = [
                'participation_id' => $i,
                'visa_id' => $this->visaId(),
                'tanggal_mulai_kerja' => '2026-09-01',
                'durasi_kontrak_bulan' => 12,
                'tanggal_berakhir_kontrak' => null,
            ];
        }

        Livewire::actingAs($maker)
            ->test(PlacementBatchPanel::class, ['containerId' => $id, 'version' => 0])
            ->set('rows', $rows)
            ->call('toggle', 999999, 999999, null)
            ->assertSet('actionError', __('ui.placement.batch.max_reached'));
    }

    // ----- P4 batch decide (Checker) -----

    public function test_review_queue_approve_batch_sends_sources_and_keeps_in_use(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $ready = $this->batchReadyCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        $result = app(PlacementBatchService::class)->submitBatch($maker, $id, [[
            'candidate_id' => $ready['candidate_id'],
            'source_participation_id' => $ready['participation_id'],
            'jenis_visa_id' => $this->visaId(),
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => null,
        ]], ['version' => 0]);
        $pendingId = (int) $result->pending_request_id;

        $this->actingAs($checker)
            ->get('/placements/review')
            ->assertOk()
            ->assertSee('Keputusan batch penempatan');

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('approve', $pendingId, 'PLACEMENT_BATCH', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertDatabaseHas('placement_participants', [
            'placement_container_id' => $id,
            'candidate_id' => $ready['candidate_id'],
            'status_penempatan' => 'Bekerja',
        ]);
        $this->assertSame('Terkirim', DB::table('participation')->where('id', $ready['participation_id'])->value('status_wawancara'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $ready['candidate_id'])->value('status_ketersediaan'));
        $this->assertSame('approved', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_review_queue_reject_batch_keeps_sources_untouched(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $ready = $this->batchReadyCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        $result = app(PlacementBatchService::class)->submitBatch($maker, $id, [[
            'candidate_id' => $ready['candidate_id'],
            'source_participation_id' => $ready['participation_id'],
            'jenis_visa_id' => $this->visaId(),
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => null,
        ]], ['version' => 0]);
        $pendingId = (int) $result->pending_request_id;

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->call('reject', $pendingId, 'PLACEMENT_BATCH', 0)
            ->assertSet('actionError', __('ui.placement.queue.note_required'));

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->set('rejectNote', 'Dokumen kurang')
            ->call('reject', $pendingId, 'PLACEMENT_BATCH', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertSame(0, DB::table('placement_participants')
            ->where('placement_container_id', $id)
            ->count());
        $this->assertSame('Siap Dikirim', DB::table('participation')->where('id', $ready['participation_id'])->value('status_wawancara'));
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $ready['candidate_id'])->value('status_ketersediaan'));
        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_livewire_index_renders_empty_state(): void
    {
        Livewire::actingAs($this->maker())
            ->test(PlacementIndex::class)
            ->assertSee('Belum ada kontainer');
    }

    // ----- P5 Force-Majeur -----

    public function test_fm_panel_lists_only_tersedia_disetujui(): void
    {
        $maker = $this->maker();
        $eligible = $this->createCandidate([
            'nomor_induk' => 'K-2026-00050',
            'nama_alphabet' => 'FM Eligible',
        ]);
        $this->createCandidate([
            'nomor_induk' => 'K-2026-00051',
            'nama_alphabet' => 'FM Dipakai',
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
        ]);
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(PlacementForceMajeurPanel::class, ['containerId' => $id, 'version' => 0])
            ->assertSee('FM Eligible')
            ->assertDontSee('FM Dipakai');
    }

    public function test_fm_request_requires_reason(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(PlacementForceMajeurPanel::class, ['containerId' => $id, 'version' => 0])
            ->call('selectCandidate', $candidateId)
            ->set('kategoriId', (string) $this->kategoriFmId())
            ->set('visaId', (string) $this->visaId())
            ->set('tanggalMulai', '2026-09-01')
            ->set('alasan', '   ')
            ->call('submit')
            ->assertSet('actionError', __('ui.placement.force_majeur.reason_required'));

        $this->assertSame(0, DB::table('pending_request')->where('type', 'FORCE_MAJEUR')->count());
    }

    public function test_fm_request_creates_pending_and_candidate_stays_tersedia(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);

        Livewire::actingAs($maker)
            ->test(PlacementForceMajeurPanel::class, ['containerId' => $id, 'version' => 0])
            ->call('selectCandidate', $candidateId)
            ->set('kategoriId', (string) $this->kategoriFmId())
            ->set('visaId', (string) $this->visaId())
            ->set('tanggalMulai', '2026-09-01')
            ->set('durasi', '12')
            ->set('alasan', 'Keluarga sakit')
            ->call('submit')
            ->assertRedirect(route('placements.show', $id));

        $this->assertSame(1, DB::table('pending_request')
            ->where('type', 'FORCE_MAJEUR')
            ->where('target_id', $id)
            ->where('status', 'pending')
            ->count());
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }

    public function test_fm_approve_from_queue_without_step_up(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate();
        $kategoriId = $this->kategoriFmId();
        $visaId = $this->visaId();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        $result = app(PlacementForceMajeurService::class)->requestForceMajeur($maker, $id, [
            'candidate_id' => $candidateId,
            'kategori_force_majeur_id' => $kategoriId,
            'alasan_force_majeur' => 'Keluarga sakit',
            'jenis_visa_id' => $visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => null,
        ], ['version' => 0]);
        $pendingId = (int) $result->pending_request_id;

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('approve', $pendingId, 'FORCE_MAJEUR', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertDatabaseHas('placement_participants', [
            'placement_container_id' => $id,
            'candidate_id' => $candidateId,
            'status_penempatan' => 'Bekerja',
            'kategori_force_majeur_id' => $kategoriId,
            'alasan_force_majeur' => 'Keluarga sakit',
            'source_participation_id' => null,
        ]);
        $this->assertSame('SEDANG_DIPAKAI', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
        $this->assertSame('approved', DB::table('pending_request')->where('id', $pendingId)->value('status'));
    }

    public function test_fm_reject_records_rejected_and_keeps_candidate_tersedia(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();
        $candidateId = $this->createCandidate();
        $id = $this->createPlacementContainer([
            'status' => 'Aktif',
            'dibuat_oleh' => $maker->id,
            'disetujui_oleh' => $checker->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($maker);
        $result = app(PlacementForceMajeurService::class)->requestForceMajeur($maker, $id, [
            'candidate_id' => $candidateId,
            'kategori_force_majeur_id' => $this->kategoriFmId(),
            'alasan_force_majeur' => 'Keluarga sakit',
            'jenis_visa_id' => $this->visaId(),
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => null,
        ], ['version' => 0]);
        $pendingId = (int) $result->pending_request_id;

        Livewire::actingAs($checker)
            ->test(PlacementReviewQueue::class)
            ->call('startReject', $pendingId)
            ->set('rejectNote', 'Dokumen kurang')
            ->call('reject', $pendingId, 'FORCE_MAJEUR', 0)
            ->assertRedirect(route('placements.review'));

        $this->assertSame('rejected', DB::table('pending_request')->where('id', $pendingId)->value('status'));
        $this->assertSame(0, DB::table('placement_participants')
            ->where('placement_container_id', $id)
            ->count());
        $this->assertSame('TERSEDIA', DB::table('candidate')->where('id', $candidateId)->value('status_ketersediaan'));
    }
}
