<?php

namespace Tests\Feature\UI;

use App\Livewire\Lookup\CompanyMaster;
use App\Livewire\Lookup\RequestQueue;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\CompanyAdminService;
use Modules\LookupData\Public\LookupRequestService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class RequestQueueScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedLookupFixtures();
    }

    private int $japanId;

    private function seedLookupFixtures(): void
    {
        $this->japanId = DB::table('negara')->insertGetId(['code' => 'JP', 'label_id' => 'Jepang', 'label_ja' => '日本', 'sort_order' => 1, 'is_active' => true]);
        DB::table('negara')->insertGetId(['code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 2, 'is_active' => true]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function staff(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::STAFF_INPUT);

        return $user;
    }

    private function submitLookupRequest(User $maker): int
    {
        return (int) app(LookupRequestService::class)->submitLookup($maker, [
            'lookup_table' => 'negara',
            'code' => 'KR',
            'label_id' => 'Korea Selatan',
            'label_ja' => '韓国',
            'reason' => 'Butuh untuk data kandidat',
        ])->id;
    }

    private function submitCompanyRequest(User $maker): int
    {
        return (int) app(LookupRequestService::class)->submitCompany($maker, [
            'nama_ja' => 'テスト株式会社',
            'nama_romaji' => 'Test Kabushiki Kaisha',
            'nama_id' => 'PT Test',
            'reason' => 'Perusahaan mitra baru',
        ])->id;
    }

    private function assistantManager(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::ASSISTANT_MANAGER);

        return $user;
    }

    private function elevateFor(string $entityType, int $entityId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.'.$entityType.'.'.$entityId => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    // ----- Read contracts -----

    public function test_paginate_requests_is_super_admin_only(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $this->submitLookupRequest($maker);

        $this->actingAs($admin);
        $rows = app(LookupRequestService::class)->paginateRequests($admin, 'lookup_request');
        $this->assertSame(1, $rows->total());
        $this->assertSame('pending', $rows->first()->status);
        $this->assertSame($maker->name, $rows->first()->requested_by_name);
    }

    public function test_paginate_requests_rejects_non_super_admin(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(LookupRequestService::class)->paginateRequests($staff, 'lookup_request');
    }

    public function test_paginate_requests_rejects_unknown_table(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->expectException(InvalidArgumentException::class);

        app(LookupRequestService::class)->paginateRequests($admin, 'not_a_request_table');
    }

    public function test_company_paginate_and_find(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $id = DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'ソフトウェア株式会社', 'nama_romaji' => null, 'nama_id' => null,
            'negara_id' => $this->japanId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, app(CompanyAdminService::class)->paginate($admin)->total());
        $this->assertSame('ソフトウェア株式会社', app(CompanyAdminService::class)->find($admin, $id)?->nama_ja);
        $this->assertNull(app(CompanyAdminService::class)->find($admin, 9999));
    }

    // ----- Page access -----

    public function test_requests_page_is_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/lookup/requests')
            ->assertOk()
            ->assertSee('Permintaan Data');
    }

    public function test_requests_page_forbids_staff(): void
    {
        $this->actingAs($this->staff())->get('/lookup/requests')->assertForbidden();
    }

    public function test_companies_page_is_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/companies')
            ->assertOk()
            ->assertSee('Master Perusahaan');
    }

    public function test_companies_page_forbids_staff(): void
    {
        $this->actingAs($this->staff())->get('/companies')->assertForbidden();
    }

    // ----- Approve / reject -----

    public function test_approve_lookup_requires_step_up_and_creates_target(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $requestId = $this->submitLookupRequest($maker);

        $this->actingAs($admin);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->assertDispatched('stepup.open');

        $this->assertSame('pending', DB::table('lookup_request')->find($requestId)->status);

        $this->elevateFor('lookup_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->assertNotDispatched('stepup.open');

        $this->assertSame('approved', DB::table('lookup_request')->find($requestId)->status);
        $this->assertNotNull(DB::table('negara')->where('code', 'KR')->first());
    }

    public function test_approve_company_creates_company_with_default_japan(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->assistantManager();
        $this->actingAs($maker);
        $requestId = $this->submitCompanyRequest($maker);

        $this->actingAs($admin);
        $this->elevateFor('company_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->call('setTab', 'company_request')
            ->call('approve', $requestId)
            ->assertNotDispatched('stepup.open');

        $this->assertSame('approved', DB::table('company_request')->find($requestId)->status);

        $company = DB::table('perusahaan')->where('nama_ja', 'テスト株式会社')->first();
        $this->assertNotNull($company);
        $this->assertSame(DB::table('negara')->where('code', 'JP')->value('id'), $company->negara_id);
    }

    public function test_reject_requires_note(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $requestId = $this->submitLookupRequest($maker);

        $this->actingAs($admin);

        $component = Livewire::test(RequestQueue::class)
            ->call('reject', $requestId);

        $this->assertSame('Catatan penolakan wajib diisi.', $component->get('actionError'));
        $this->assertSame('pending', DB::table('lookup_request')->find($requestId)->status);
    }

    public function test_reject_with_note_sets_rejected_and_creates_no_target(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $requestId = $this->submitLookupRequest($maker);

        $this->actingAs($admin);
        $this->elevateFor('lookup_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->set('rejectNotes.'.$requestId, 'Data kurang lengkap')
            ->call('reject', $requestId)
            ->assertNotDispatched('stepup.open');

        $row = DB::table('lookup_request')->find($requestId);
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Data kurang lengkap', $row->note_checker);
        $this->assertNull(DB::table('negara')->where('code', 'KR')->first());
    }

    public function test_maker_cannot_decide_own_request(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $requestId = (int) DB::table('lookup_request')->insertGetId([
            'lookup_table' => 'negara',
            'code' => 'KR',
            'label_id' => 'Korea Selatan',
            'label_ja' => '韓国',
            'extra' => null,
            'requested_by' => $admin->id,
            'reason' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->elevateFor('lookup_request', $requestId);

        $component = Livewire::test(RequestQueue::class)
            ->call('approve', $requestId);

        $this->assertSame('Anda tidak dapat memutuskan permintaan sendiri.', $component->get('actionError'));
        $this->assertSame('pending', DB::table('lookup_request')->find($requestId)->status);
    }

    public function test_double_decision_surfaces_conflict_banner(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $requestId = $this->submitLookupRequest($maker);

        $this->actingAs($admin);
        $this->elevateFor('lookup_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->assertNotDispatched('stepup.open');

        $this->assertSame('approved', DB::table('lookup_request')->find($requestId)->status);

        $this->elevateFor('lookup_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->assertSet('conflict', true);
    }

    public function test_step_up_success_event_executes_staged_decision(): void
    {
        $admin = $this->superAdmin();
        $maker = $this->staff();
        $this->actingAs($maker);
        $requestId = $this->submitLookupRequest($maker);

        $this->actingAs($admin);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->assertDispatched('stepup.open');

        $this->assertSame('pending', DB::table('lookup_request')->find($requestId)->status);

        $this->elevateFor('lookup_request', $requestId);

        Livewire::test(RequestQueue::class)
            ->call('approve', $requestId)
            ->dispatch('stepup.success',
                action: StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                entityType: 'lookup_request',
                entityId: $requestId,
            )
            ->assertHasNoErrors();

        $this->assertSame('approved', DB::table('lookup_request')->find($requestId)->status);
    }

    // ----- Company master -----

    public function test_company_create_requires_step_up_and_saves(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(CompanyMaster::class)
            ->call('startCreate')
            ->set('formNamaJa', '日本株式会社')
            ->set('formNamaId', 'PT Nihon')
            ->call('save')
            ->assertDispatched('stepup.open');

        $this->assertNull(DB::table('perusahaan')->where('nama_ja', '日本株式会社')->first());

        $this->elevateFor('perusahaan_create', 1);

        Livewire::test(CompanyMaster::class)
            ->call('startCreate')
            ->set('formNamaJa', '日本株式会社')
            ->set('formNamaId', 'PT Nihon')
            ->call('save')
            ->assertNotDispatched('stepup.open')
            ->assertSet('showForm', false);

        $row = DB::table('perusahaan')->where('nama_ja', '日本株式会社')->first();
        $this->assertNotNull($row);
        $this->assertSame(DB::table('negara')->where('code', 'JP')->value('id'), $row->negara_id);
    }

    public function test_company_create_requires_nama_ja(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateFor('perusahaan_create', 1);

        $component = Livewire::test(CompanyMaster::class)
            ->call('startCreate')
            ->set('formNamaId', 'PT Tanpa Nama Jepang')
            ->call('save');

        $this->assertNotNull($component->get('actionError'));
        $this->assertNull(DB::table('perusahaan')->where('nama_id', 'PT Tanpa Nama Jepang')->first());
    }

    public function test_company_update_and_soft_disable(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateFor('perusahaan_create', 1);

        Livewire::test(CompanyMaster::class)
            ->call('startCreate')
            ->set('formNamaJa', 'テスト株式会社')
            ->call('save');

        $id = (int) DB::table('perusahaan')->where('nama_ja', 'テスト株式会社')->value('id');
        $this->elevateFor('perusahaan', $id);

        Livewire::test(CompanyMaster::class)
            ->call('startEdit', $id)
            ->assertSet('formNamaJa', 'テスト株式会社')
            ->set('formNamaRomaji', 'Test KK')
            ->call('save')
            ->assertNotDispatched('stepup.open');

        $this->assertSame('Test KK', DB::table('perusahaan')->find($id)->nama_romaji);

        $this->elevateFor('perusahaan', $id);

        Livewire::test(CompanyMaster::class)
            ->call('toggleActive', $id, false)
            ->assertNotDispatched('stepup.open');

        $this->assertFalse((bool) DB::table('perusahaan')->find($id)->is_active);
    }
}
