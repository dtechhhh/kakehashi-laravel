<?php

namespace Tests\Feature\UI;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Modules\Auth\Rbac;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staffUser(): User
    {
        $user = User::factory()->active()->create();

        $user->assignRole(Rbac::STAFF_INPUT);

        return $user;
    }

    private function userWithConfirmedTwoFactor(string $role): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole($role);

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    public function test_guest_is_redirected_away_from_home(): void
    {
        $this->get('/home')->assertRedirect();
    }

    public function test_authenticated_user_sees_shell_and_home(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee('Kakehashi')
            ->assertSee('Beranda')
            ->assertSee($user->name);
    }

    public function test_shell_contains_skip_link_and_main_landmark(): void
    {
        $this->actingAs($this->staffUser())
            ->get('/home')
            ->assertOk()
            ->assertSee('Lompat ke konten utama')
            ->assertSee('id="main-content"', false);
    }

    public function test_nav_item_is_not_rendered_when_route_is_unregistered(): void
    {
        $this->actingAs($this->staffUser())
            ->get('/home')
            ->assertOk()
            ->assertDontSee('Data Master')
            ->assertDontSee('Kelola Akun');
    }

    public function test_super_admin_sees_admin_nav_items(): void
    {
        $admin = $this->userWithConfirmedTwoFactor(Rbac::SUPER_ADMIN);

        $this->actingAs($admin)
            ->get('/home')
            ->assertOk()
            ->assertSee('Kelola Akun')
            ->assertSee('Audit');
    }

    public function test_staff_does_not_see_admin_nav_items(): void
    {
        $staff = $this->staffUser();

        $this->actingAs($staff)
            ->get('/home')
            ->assertOk()
            ->assertSee('Beranda')
            ->assertDontSee('Kelola Akun')
            ->assertDontSee('Audit')
            ->assertDontSee('Data Master');
    }

    public function test_language_switch_switches_ui_locale(): void
    {
        $user = $this->staffUser();

        $this->actingAs($user)
            ->post('/language', ['locale' => 'ja'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee('ホーム');

        $this->actingAs($user)
            ->post('/language', ['locale' => 'id'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee('Beranda');
    }

    public function test_language_switch_rejects_unknown_locale(): void
    {
        $this->actingAs($this->staffUser())
            ->post('/language', ['locale' => 'fr'])
            ->assertNotFound();
    }

    public function test_guest_cannot_switch_language(): void
    {
        $this->post('/language', ['locale' => 'ja'])->assertRedirect();
    }

    public function test_home_renders_after_assets_manifest_absence(): void
    {
        $this->actingAs($this->staffUser())
            ->get('/home')
            ->assertOk();
    }
}
