<?php

namespace Tests\Feature\UI;

use App\Livewire\Lookup\LookupIndex;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\LookupService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LookupScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedLookupFixtures();
    }

    private int $indonesiaId;

    private int $malaysiaId;

    private function seedLookupFixtures(): void
    {
        $this->indonesiaId = DB::table('negara')->insertGetId([
            'code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 1, 'is_active' => true,
        ]);
        DB::table('negara')->insertGetId([
            'code' => 'JP', 'label_id' => 'Jepang', 'label_ja' => '日本', 'sort_order' => 2, 'is_active' => true,
        ]);
        $this->malaysiaId = DB::table('negara')->insertGetId([
            'code' => 'MY', 'label_id' => 'Malaysia', 'label_ja' => 'マレーシア', 'sort_order' => 3, 'is_active' => false,
        ]);

        DB::table('provinsi')->insert([
            ['code' => 'JABAR', 'label_id' => 'Jawa Barat', 'label_ja' => '西ジャワ', 'negara_id' => $this->indonesiaId, 'sort_order' => 1, 'is_active' => true],
        ]);
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

    private function elevateLookupCreate(string $table): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.lookup_create:'.$table.'.1' => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    private function elevateLookup(string $table, int $id): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.lookup:'.$table.'.'.$id => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    // ----- Read contract -----

    public function test_paginate_includes_inactive_values(): void
    {
        $rows = app(LookupService::class)->paginate('negara');

        $this->assertSame(3, $rows->total());
        $this->assertCount(1, $rows->where('is_active', false));
    }

    public function test_paginate_filters_by_search_and_active(): void
    {
        $service = app(LookupService::class);

        $this->assertSame(1, $service->paginate('negara', ['search' => 'malay'])->total());
        $this->assertSame(2, $service->paginate('negara', ['active' => '1'])->total());
        $this->assertSame(1, $service->paginate('negara', ['active' => '0'])->total());
    }

    public function test_paginate_ignores_unknown_sort_columns(): void
    {
        $rows = app(LookupService::class)->paginate('negara', ['sort' => 'secret_column', 'direction' => 'desc']);

        $this->assertSame(3, $rows->total());
    }

    public function test_find_returns_row_or_null(): void
    {
        $service = app(LookupService::class);

        $this->assertSame('ID', $service->find('negara', (int) $this->indonesiaId)?->code);
        $this->assertNull($service->find('negara', 9999));
    }

    public function test_paginate_rejects_unknown_table(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LookupService::class)->paginate('tidak_ada');
    }

    // ----- Page access -----

    public function test_lookup_page_is_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/lookup')
            ->assertOk()
            ->assertSee('Data Master (Lookup)')
            ->assertSee('Indonesia')
            ->assertSee('日本');
    }

    public function test_lookup_page_forbids_staff(): void
    {
        $this->actingAs($this->staff())->get('/lookup')->assertForbidden();
    }

    public function test_lookup_page_redirects_guest(): void
    {
        $this->get('/lookup')->assertRedirect();
    }

    // ----- Create -----

    public function test_create_dispatches_step_up_without_token(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(LookupIndex::class)
            ->call('startCreate')
            ->set('formCode', 'TH')
            ->set('formLabelId', 'Thailand')
            ->set('formLabelJa', 'タイ')
            ->call('save')
            ->assertDispatched('stepup.open');

        $this->assertNull(DB::table('negara')->where('code', 'TH')->first());
    }

    public function test_create_executes_with_valid_elevation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateLookupCreate('negara');

        Livewire::test(LookupIndex::class)
            ->call('startCreate')
            ->set('formCode', 'TH')
            ->set('formLabelId', 'Thailand')
            ->set('formLabelJa', 'タイ')
            ->set('formSortOrder', '5')
            ->call('save')
            ->assertNotDispatched('stepup.open')
            ->assertSet('showForm', false);

        $row = DB::table('negara')->where('code', 'TH')->first();
        $this->assertNotNull($row);
        $this->assertSame('タイ', $row->label_ja);
        $this->assertSame(5, $row->sort_order);
        $this->assertTrue((bool) $row->is_active);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateLookupCreate('negara');

        $component = Livewire::test(LookupIndex::class)
            ->call('startCreate')
            ->set('formCode', 'JP')
            ->set('formLabelId', 'Dup')
            ->set('formLabelJa', 'Dup')
            ->call('save');

        $this->assertSame('Kode sudah digunakan.', $component->get('actionError'));
    }

    public function test_create_with_inactive_parent_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateLookupCreate('provinsi');

        $component = Livewire::test(LookupIndex::class)
            ->call('setTable', 'provinsi')
            ->call('startCreate')
            ->set('formCode', 'BALI')
            ->set('formLabelId', 'Bali')
            ->set('formLabelJa', 'バリ')
            ->set('formExtras.negara_id', (string) $this->malaysiaId)
            ->call('save');

        $this->assertSame('Induk yang dipilih tidak aktif atau tidak ditemukan.', $component->get('actionError'));
        $this->assertNull(DB::table('provinsi')->where('code', 'BALI')->first());
    }

    // ----- Update -----

    public function test_edit_prefills_row_and_code_stays_immutable(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateLookup('negara', (int) $this->indonesiaId);

        $component = Livewire::test(LookupIndex::class)
            ->call('startEdit', (int) $this->indonesiaId)
            ->assertSet('editingId', (int) $this->indonesiaId)
            ->assertSet('formCode', 'ID')
            ->assertSet('formLabelJa', 'インドネシア');

        $component->set('formLabelJa', 'インドネシア共和国')
            ->call('save')
            ->assertNotDispatched('stepup.open');

        $row = DB::table('negara')->find($this->indonesiaId);
        $this->assertSame('ID', $row->code);
        $this->assertSame('インドネシア共和国', $row->label_ja);
    }

    public function test_edit_supports_extra_columns(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevateLookup('negara', (int) $this->indonesiaId);

        Livewire::test(LookupIndex::class)
            ->call('startEdit', (int) $this->indonesiaId)
            ->set('formExtras.region', 'Asia Tenggara')
            ->set('formExtras.dial_code', '+62')
            ->call('save')
            ->assertNotDispatched('stepup.open');

        $row = DB::table('negara')->find($this->indonesiaId);
        $this->assertSame('Asia Tenggara', $row->region);
        $this->assertSame('+62', $row->dial_code);
    }

    // ----- Soft disable -----

    public function test_toggle_active_requires_step_up_and_flips_state(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(LookupIndex::class)
            ->call('toggleActive', $this->indonesiaId, false)
            ->assertDispatched('stepup.open');

        $this->assertTrue((bool) DB::table('negara')->find($this->indonesiaId)->is_active);

        $this->elevateLookup('negara', (int) $this->indonesiaId);

        Livewire::test(LookupIndex::class)
            ->call('toggleActive', $this->indonesiaId, false)
            ->assertNotDispatched('stepup.open');

        $this->assertFalse((bool) DB::table('negara')->find($this->indonesiaId)->is_active);

        $this->elevateLookup('negara', (int) $this->indonesiaId);

        Livewire::test(LookupIndex::class)
            ->call('toggleActive', $this->indonesiaId, true)
            ->assertNotDispatched('stepup.open');

        $this->assertTrue((bool) DB::table('negara')->find($this->indonesiaId)->is_active);
    }

    public function test_step_up_success_event_runs_staged_update(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(LookupIndex::class)
            ->call('startEdit', (int) $this->indonesiaId)
            ->set('formLabelJa', '日本国')
            ->call('save')
            ->assertDispatched('stepup.open');

        $this->assertSame('インドネシア', DB::table('negara')->find($this->indonesiaId)->label_ja);

        $this->elevateLookup('negara', (int) $this->indonesiaId);

        Livewire::test(LookupIndex::class)
            ->call('startEdit', (int) $this->indonesiaId)
            ->set('formLabelJa', '日本国')
            ->call('save')
            ->dispatch('stepup.success',
                action: StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                entityType: 'lookup:negara',
                entityId: (int) $this->indonesiaId,
            )
            ->assertHasNoErrors();

        $this->assertSame('日本国', DB::table('negara')->find($this->indonesiaId)->label_ja);
    }

    public function test_step_up_success_with_wrong_scope_does_nothing(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(LookupIndex::class)
            ->call('startEdit', (int) $this->indonesiaId)
            ->set('formLabelJa', 'Ubah Saja')
            ->call('save')
            ->assertDispatched('stepup.open')
            ->dispatch('stepup.success',
                action: StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                entityType: 'lookup:provinsi',
                entityId: 1,
            )
            ->assertHasNoErrors();

        $this->assertSame('インドネシア', DB::table('negara')->find($this->indonesiaId)->label_ja);
    }

    public function test_bilingual_rows_render_for_old_inactive_data(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/lookup')
            ->assertOk()
            ->assertSee('Malaysia')
            ->assertSee('マレーシア');
    }
}
