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

class AuthScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithTwoFactorConfirmed(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    public function test_login_page_renders_for_guest(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Gunakan email dan kata sandi Anda untuk masuk.')
            ->assertSee('Email')
            ->assertSee('Kata sandi')
            ->assertSee('Masuk')
            ->assertSee('name="locale"', false)
            ->assertSee('value="ja"', false)
            ->assertSee('required', false)
            ->assertDontSee('novalidate', false);
    }

    public function test_login_page_redirects_authenticated_user(): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user)->get('/login')->assertRedirect(route('home'));
    }

    public function test_challenge_page_renders_for_guest(): void
    {
        $this->get('/two-factor/challenge')
            ->assertOk()
            ->assertSee('Verifikasi dua langkah')
            ->assertSee('6 digit');
    }

    public function test_lockout_page_renders_for_guest_with_countdown(): void
    {
        $this->get('/lockout?retry=300')
            ->assertOk()
            ->assertSee('Terlalu banyak percobaan')
            ->assertSee('lockout-countdown', false);
    }

    public function test_forced_password_page_is_available_while_password_is_stale(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user)
            ->get('/password/forced')
            ->assertOk()
            ->assertSee('Anda harus mengganti kata sandi sebelum melanjutkan.');
    }

    public function test_forced_password_page_exposes_specific_policy_and_confirmation_messages(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user)
            ->get('/password/forced')
            ->assertSee('password-change-error-policy', false)
            ->assertSee('password-change-error-confirmation', false)
            ->assertSee('minimal 12 karakter dan mengandung setidaknya 3 dari 4 kelas', false)
            ->assertSee('Konfirmasi kata sandi baru tidak cocok.');
    }

    public function test_forced_password_user_can_switch_public_locale(): void
    {
        $user = User::factory()->active()->create(['must_change_password' => true]);
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user)
            ->from('/password/forced')
            ->post('/language', ['locale' => 'ja'])
            ->assertRedirect('/password/forced');

        $this->get('/password/forced')
            ->assertOk()
            ->assertSee('パスワードの変更');
    }

    public function test_forced_password_page_is_blocked_for_guest(): void
    {
        $this->get('/password/forced')->assertRedirect();
    }

    public function test_forced_password_page_still_blocks_other_routes(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user)->get('/home')->assertForbidden();
    }

    public function test_enroll_page_is_available_for_mandatory_role_without_two_factor(): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($user)
            ->get('/two-factor/enroll')
            ->assertOk()
            ->assertSee('Aktifkan verifikasi dua langkah')
            ->assertSee('enroll-page', false);
    }

    public function test_mandatory_two_factor_html_request_redirects_to_enrollment(): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($user)
            ->get('/home', ['Accept' => 'text/html'])
            ->assertRedirect(route('two-factor.enroll'));
    }

    public function test_mandatory_two_factor_json_request_keeps_json_boundary(): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($user)
            ->getJson('/home')
            ->assertForbidden()
            ->assertJson(['message' => 'TWOFA_ENROLL_REQUIRED']);
    }

    public function test_mandatory_two_factor_user_can_switch_public_locale(): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($user)
            ->from('/two-factor/enroll')
            ->post('/language', ['locale' => 'ja'])
            ->assertRedirect('/two-factor/enroll');

        $this->get('/two-factor/enroll')
            ->assertOk()
            ->assertSee('二段階認証の設定');
    }

    public function test_enroll_page_is_available_for_confirmed_mandatory_user(): void
    {
        $this->actingAs($this->userWithTwoFactorConfirmed())
            ->get('/two-factor/enroll')
            ->assertOk();
    }

    public function test_enroll_page_is_blocked_for_guest(): void
    {
        $this->get('/two-factor/enroll')->assertRedirect();
    }

    public function test_language_toggle_works_on_public_pages(): void
    {
        $this->withSession(['locale' => 'ja'])
            ->get('/login')
            ->assertOk()
            ->assertSee('ログイン');
    }
}
