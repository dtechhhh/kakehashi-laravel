<?php

namespace Tests\Feature\UI;

use App\Livewire\Placement\PlacementDetail;
use App\Livewire\Placement\PlacementForm;
use App\Livewire\Placement\PlacementIndex;
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
use Modules\Placement\Public\PlacementQueryService;
use Modules\Placement\Services\PlacementContainerService;
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
        return (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W5_SSW',
            'label_id' => 'Visa W5',
            'label_ja' => 'W5ビザ',
            'kategori' => 'SSW',
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

    public function test_livewire_index_renders_empty_state(): void
    {
        Livewire::actingAs($this->maker())
            ->test(PlacementIndex::class)
            ->assertSee('Belum ada kontainer');
    }
}
