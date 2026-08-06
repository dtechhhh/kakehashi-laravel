<?php

namespace Tests\Feature\UI;

use App\Livewire\Placement\PlacementIndex;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Placement\Public\PlacementQueryService;
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

    public function test_livewire_index_renders_empty_state(): void
    {
        Livewire::actingAs($this->maker())
            ->test(PlacementIndex::class)
            ->assertSee('Belum ada kontainer');
    }
}
