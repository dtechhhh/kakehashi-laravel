<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use PragmaRX\Google2FA\Google2FA;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class TotpStepUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_requires_confirm_before_two_factor_is_active(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->actingAs($user);

        $enable = $this->postJson('/user/two-factor-authentication');
        $enable->assertOk()
            ->assertJsonPath('message', 'TWOFA_ENABLED')
            ->assertJsonPath('confirmed', false)
            ->assertJsonStructure(['otpauth_url', 'secret']);

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());

        $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'TWOFA_FAILED');

        $code = $this->currentTotp($user);
        $confirm = $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => $code,
        ]);

        $confirm->assertOk()
            ->assertJsonPath('message', 'TWOFA_CONFIRMED')
            ->assertJsonStructure(['recovery_codes']);

        $this->assertCount(8, $confirm->json('recovery_codes'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::TWOFA_FAILED)->count());
        $setup = AuditLog::query()->where('action_type', ActionType::TWOFA_SETUP)->sole();
        $this->assertSame(['regenerate' => false], $setup->detail);
        $this->assertSame($user->getKey(), $setup->actor_id);
    }

    public function test_confirmed_user_cannot_reenable_or_read_qr_secret(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $secretBefore = $user->two_factor_secret;

        $this->actingAs($user);

        $this->postJson('/user/two-factor-authentication')
            ->assertStatus(422)
            ->assertJson(['message' => 'TWOFA_ALREADY_ENABLED']);

        $user->refresh();
        $this->assertSame($secretBefore, $user->two_factor_secret);
        $this->assertNotNull($user->two_factor_confirmed_at);

        $this->getJson('/user/two-factor-qr-code')
            ->assertStatus(422)
            ->assertJson(['message' => 'TWOFA_NOT_PENDING']);

        $this->getJson('/user/two-factor-secret-key')
            ->assertStatus(422)
            ->assertJson(['message' => 'TWOFA_NOT_PENDING']);
    }

    public function test_pending_enrollment_can_resume_without_rotating_secret(): void
    {
        $user = User::factory()->active()->create();
        $this->actingAs($user);

        $first = $this->postJson('/user/two-factor-authentication');
        $first->assertOk()->assertJsonPath('message', 'TWOFA_ENABLED');
        $secret = $first->json('secret');
        $url = $first->json('otpauth_url');

        $second = $this->postJson('/user/two-factor-authentication');
        $second->assertOk()
            ->assertJsonPath('message', 'TWOFA_ENABLED')
            ->assertJsonPath('secret', $secret)
            ->assertJsonPath('otpauth_url', $url);

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);

        $this->getJson('/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg', 'url']);
        $this->getJson('/user/two-factor-secret-key')
            ->assertOk()
            ->assertJsonPath('secret', $secret);

        $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => $this->currentTotp($user),
        ])->assertOk()->assertJsonPath('message', 'TWOFA_CONFIRMED');

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_login_with_confirmed_two_factor_requires_challenge_and_accepts_totp(): void
    {
        $user = $this->userWithConfirmedTwoFactor([
            'email' => '2fa@example.com',
            'password' => 'password',
        ]);

        $login = $this->postJson('/login', [
            'email' => '2fa@example.com',
            'password' => 'password',
        ]);

        $login->assertOk()->assertJson(['message' => 'TWOFA_REQUIRED']);
        $this->assertGuest();
        $this->assertNotNull(session('login.id'));

        $this->postJson('/two-factor-challenge', [
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'TWOFA_FAILED');

        $this->assertGuest();

        $this->postJson('/two-factor-challenge', [
            'code' => $this->currentTotp($user),
        ])->assertOk()
            ->assertJsonPath('message', 'LOGIN_SUCCESS');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('login.id'));
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::TWOFA_FAILED)->count());
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::TWOFA_VERIFIED)->count());
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::LOGIN_SUCCESS)->count());
    }

    public function test_recovery_code_is_single_use(): void
    {
        $user = $this->userWithConfirmedTwoFactor([
            'email' => 'recovery@example.com',
            'password' => 'password',
        ]);

        $codes = $user->recoveryCodes();
        $code = $codes[0];

        $this->postJson('/login', [
            'email' => 'recovery@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/two-factor-challenge', [
            'recovery_code' => $code,
        ])->assertOk()
            ->assertJsonPath('message', 'LOGIN_SUCCESS');

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();

        $user->refresh();
        $this->assertFalse(in_array($code, $user->recoveryCodes(), true));

        $this->postJson('/login', [
            'email' => 'recovery@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/two-factor-challenge', [
            'recovery_code' => $code,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'TWOFA_FAILED');

        $this->assertGuest();
        $recoveryAudit = AuditLog::query()
            ->where('action_type', ActionType::TWOFA_RECOVERY_USED)
            ->sole();
        $this->assertSame(7, $recoveryAudit->detail['codes_left']);
        $this->assertStringNotContainsString(
            $code,
            json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_mandatory_role_cannot_access_app_until_two_factor_enrolled(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        $this->actingAs($user);

        $this->getJson('/auth/session')
            ->assertForbidden()
            ->assertJson(['message' => 'TWOFA_ENROLL_REQUIRED']);

        $this->postJson('/user/two-factor-authentication')->assertOk();
        $code = $this->currentTotp($user->fresh());
        $this->postJson('/user/confirmed-two-factor-authentication', [
            'code' => $code,
        ])->assertOk();

        $this->getJson('/auth/session')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);
    }

    public function test_mandatory_role_cannot_disable_two_factor(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->userWithConfirmedTwoFactor();
        $user->assignRole(Rbac::JOB_MANAGER);

        $this->actingAs($user);

        $this->deleteJson('/user/two-factor-authentication')
            ->assertForbidden()
            ->assertJson(['message' => 'TWOFA_DISABLE_FORBIDDEN']);
    }

    public function test_step_up_requires_password_and_totp_and_ttl_is_five_minutes_per_action(): void
    {
        $user = $this->userWithConfirmedTwoFactor([
            'password' => 'ValidPass123',
        ]);
        $this->actingAs($user);

        $service = app(StepUpService::class);

        try {
            $service->require(StepUpAction::ANONYMIZE_PII, 'candidate', 9);
            $this->fail('Expected step-up gate to reject without elevation.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $e->getResponse()->getData(true)['message']);
        }

        $this->postJson('/user/step-up', [
            'password' => 'wrong-password',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 9,
        ])->assertForbidden()
            ->assertJsonPath('message', 'STEPUP_FAILED');

        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => '000000',
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 9,
        ])->assertForbidden()
            ->assertJsonPath('message', 'STEPUP_FAILED');

        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 9,
        ])->assertOk()
            ->assertJson([
                'message' => 'STEPUP_OK',
                'ttl_seconds' => 300,
            ]);

        $service->require(StepUpAction::ANONYMIZE_PII, 'candidate', 9);

        // Single-use: second require without re-elevate fails.
        try {
            $service->require(StepUpAction::ANONYMIZE_PII, 'candidate', 9);
            $this->fail('Expected single-use elevation to be consumed.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }

        // Per-action scope: elevation for A does not cover B.
        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
            'entity_type' => 'lookup',
            'entity_id' => 1,
        ])->assertOk();

        try {
            $service->require(StepUpAction::USER_ROLE_OR_DEACTIVATE, 'user', 1);
            $this->fail('Expected different action to remain gated.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }

        $service->require(StepUpAction::MANAGE_LOOKUP_OR_COMPANY, 'lookup', 1);

        // TTL 5 minutes (relative to elevation time).
        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::APPROVE_INTERVIEW_CLOSE,
            'entity_type' => 'interview_container',
            'entity_id' => 3,
        ])->assertOk();

        $elevatedAt = now()->copy();

        Carbon::setTestNow($elevatedAt->copy()->addSeconds(StepUpService::TTL_SECONDS - 1));
        $this->assertTrue($service->hasValidElevation(
            StepUpAction::APPROVE_INTERVIEW_CLOSE,
            'interview_container',
            3,
        ));

        Carbon::setTestNow($elevatedAt->copy()->addSeconds(StepUpService::TTL_SECONDS + 1));
        try {
            $service->require(StepUpAction::APPROVE_INTERVIEW_CLOSE, 'interview_container', 3);
            $this->fail('Expected elevation to expire after 5 minutes.');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $e->getResponse()->getData(true)['message']);
        }

        $this->assertSame(2, AuditLog::query()->where('action_type', ActionType::STEPUP_FAILED)->count());
        $this->assertSame(3, AuditLog::query()->where('action_type', ActionType::STEPUP_REAUTH)->count());
        $json = json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('ValidPass123', $json);
        $this->assertStringNotContainsString('wrong-password', $json);
    }

    public function test_step_up_throttle_locks_after_five_failures_and_clears_on_success_or_ttl(): void
    {
        Carbon::setTestNow('2026-07-24 15:00:00');

        $user = $this->userWithConfirmedTwoFactor([
            'password' => 'ValidPass123',
        ]);
        $this->actingAs($user);

        $throttleKey = 'stepup:'.$user->id.'|127.0.0.1';
        RateLimiter::clear($throttleKey);
        // Ensure array cache isolation even if a prior concurrency test left Redis env.
        config(['cache.default' => 'array']);
        RateLimiter::clear($throttleKey);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/user/step-up', [
                'password' => 'wrong-password',
                'code' => '000000',
                'action' => StepUpAction::ANONYMIZE_PII,
                'entity_type' => 'candidate',
                'entity_id' => 1,
            ])->assertForbidden()
                ->assertJsonPath('message', 'STEPUP_FAILED');
        }

        $this->postJson('/user/step-up', [
            'password' => 'wrong-password',
            'code' => '000000',
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 1,
        ])->assertStatus(429)
            ->assertJsonPath('message', 'STEPUP_LOCKED_OUT');

        // Still locked before TTL.
        Carbon::setTestNow('2026-07-24 15:14:00');
        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 1,
        ])->assertStatus(429);

        // Clears after 15 minutes.
        Carbon::setTestNow('2026-07-24 15:15:01');
        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 1,
        ])->assertOk()
            ->assertJsonPath('message', 'STEPUP_OK');

        // Success clears counter: failures below lockout remain 403, then success clears.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/user/step-up', [
                'password' => 'wrong-password',
                'code' => '000000',
                'action' => StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                'entity_type' => 'lookup',
                'entity_id' => 2,
            ])->assertForbidden();
        }

        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
            'entity_type' => 'lookup',
            'entity_id' => 2,
        ])->assertOk();

        // After success, five more failures are allowed before lockout again.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/user/step-up', [
                'password' => 'wrong-password',
                'code' => '000000',
                'action' => StepUpAction::USER_ROLE_OR_DEACTIVATE,
                'entity_type' => 'user',
                'entity_id' => 3,
            ])->assertForbidden();
        }

        $this->postJson('/user/step-up', [
            'password' => 'wrong-password',
            'code' => '000000',
            'action' => StepUpAction::USER_ROLE_OR_DEACTIVATE,
            'entity_type' => 'user',
            'entity_id' => 3,
        ])->assertStatus(429)
            ->assertJsonPath('message', 'STEPUP_LOCKED_OUT');
    }

    public function test_step_up_entity_id_must_be_positive_integer(): void
    {
        $user = $this->userWithConfirmedTwoFactor(['password' => 'ValidPass123']);
        $this->actingAs($user);
        $code = $this->currentTotp($user);

        foreach ([
            ['entity_id' => [1]],
            ['entity_id' => 'abc'],
            ['entity_id' => 0],
            ['entity_id' => -1],
            ['entity_id' => '1.5'],
        ] as $payload) {
            $this->postJson('/user/step-up', array_merge([
                'password' => 'ValidPass123',
                'code' => $code,
                'action' => StepUpAction::ANONYMIZE_PII,
                'entity_type' => 'candidate',
            ], $payload))->assertStatus(422)
                ->assertJsonValidationErrors(['entity_id']);
        }
    }

    public function test_step_up_catalog_is_exactly_five_final_triggers(): void
    {
        $this->assertSame([
            StepUpAction::USER_ROLE_OR_DEACTIVATE,
            StepUpAction::APPROVE_INTERVIEW_CLOSE,
            StepUpAction::APPROVE_CANDIDATE_EXPEL,
            StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
            StepUpAction::ANONYMIZE_PII,
        ], StepUpAction::all());

        $this->assertCount(5, StepUpAction::all());

        // Routine approvals and Force-Majeur are NOT step-up actions.
        foreach ([
            'APPROVE_CANDIDATE',
            'REJECT_CANDIDATE',
            'FORCE_MAJEUR_APPROVE',
            'PLACEMENT_BATCH_APPROVE',
            'APPROVE_RESIGN',
            'password.confirm',
        ] as $invalid) {
            $this->assertFalse(StepUpAction::isValid($invalid), $invalid);
        }

        $user = $this->userWithConfirmedTwoFactor(['password' => 'ValidPass123']);
        $this->actingAs($user);

        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => 'FORCE_MAJEUR_APPROVE',
            'entity_type' => 'placement',
            'entity_id' => 1,
        ])->assertStatus(422);

        $service = app(StepUpService::class);
        try {
            $service->require('FORCE_MAJEUR_APPROVE', 'placement', 1);
            $this->fail('Expected invalid action to be rejected.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_ACTION_INVALID', $e->getResponse()->getData(true)['message']);
        }
    }

    public function test_regenerate_recovery_codes_invalidates_old_set(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $old = $user->recoveryCodes();

        $this->actingAs($user);

        $response = $this->postJson('/user/two-factor-recovery-codes');
        $response->assertOk()
            ->assertJsonPath('message', 'TWOFA_RECOVERY_REGENERATED');

        $new = $response->json('recovery_codes');
        $this->assertCount(8, $new);
        $this->assertEmpty(array_intersect($old, $new));

        $audit = AuditLog::query()->where('action_type', ActionType::TWOFA_SETUP)->sole();
        $this->assertSame(['regenerate' => true], $audit->detail);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function userWithConfirmedTwoFactor(array $overrides = []): User
    {
        $user = User::factory()->active()->create(array_merge([
            'password' => 'password',
        ], $overrides));

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $code = $this->currentTotp($user);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function currentTotp(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        // Fortify marks used TOTP codes in cache. Clear only that marker so tests
        // can re-verify within the same 30s window without wiping RateLimiter.
        Cache::forget('fortify.2fa_codes.'.md5($code));

        return $code;
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate from concurrency tests that force Redis via process env.
        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.block' => false,
        ]);
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
