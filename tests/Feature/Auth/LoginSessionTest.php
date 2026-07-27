<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Rbac;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class LoginSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_idle_lifetime_is_thirty_minutes(): void
    {
        $this->assertSame(30, (int) config('session.lifetime'));
    }

    public function test_login_accepts_email_only_case_insensitive_and_regenerates_session(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => 'password',
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);
        $user->assignRole(Rbac::STAFF_INPUT);

        $this->startSession();
        $sessionIdBefore = session()->getId();

        $response = $this->postJson('/login', [
            'email' => 'STAFF@Example.COM',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'LOGIN_SUCCESS',
                'must_change_password' => false,
            ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionIdBefore, session()->getId());

        $audit = AuditLog::query()->sole();
        $this->assertSame(ActionType::LOGIN_SUCCESS, $audit->action_type);
        $this->assertSame($user->getKey(), $audit->actor_id);
        $this->assertSame(Rbac::STAFF_INPUT, $audit->actor_role_snapshot);
        $this->assertSame(['user_id' => $user->getKey()], $audit->detail);
        $this->assertNull($audit->user_agent);
    }

    public function test_login_rejects_unknown_or_bad_password_with_422(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => 'password',
            'status_akun' => 'Aktif',
        ]);

        $this->postJson('/login', [
            'email' => 'staff@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'LOGIN_FAILED')
            ->assertJsonPath('errors.email.0', 'LOGIN_FAILED');

        $this->postJson('/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'LOGIN_FAILED');

        $this->assertGuest();

        $audits = AuditLog::query()->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame(ActionType::LOGIN_FAILED, $audits[0]->action_type);
        $this->assertSame($user->getKey(), $audits[0]->detail['user_id']);
        $this->assertArrayNotHasKey('email_masked_or_fingerprint', $audits[0]->detail);
        $this->assertNull($audits[1]->detail['user_id']);
        $this->assertMatchesRegularExpression(
            '/^hmac:[a-f0-9]{64}$/',
            $audits[1]->detail['email_masked_or_fingerprint'],
        );

        $json = json_encode($audits->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('staff@example.com', $json);
        $this->assertStringNotContainsString('nobody@example.com', $json);
        $this->assertStringNotContainsString('wrong-password', $json);
    }

    public function test_inactive_account_receives_403_even_with_valid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'gone@example.com',
            'password' => 'password',
            'status_akun' => 'Nonaktif',
        ]);

        $this->postJson('/login', [
            'email' => 'gone@example.com',
            'password' => 'password',
        ])->assertForbidden()
            ->assertJson(['message' => 'LOGIN_INACTIVE']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::LOGIN_FAILED->value,
            'entity_id' => $user->getKey(),
        ]);
        $this->assertSame('inactive', AuditLog::query()->sole()->detail['reason']);
    }

    public function test_lockout_returns_429_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'lock@example.com',
            'password' => 'password',
            'status_akun' => 'Aktif',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => 'LOCK@Example.COM',
                'password' => 'bad-password',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => 'lock@example.com',
            'password' => 'password',
        ])->assertStatus(429)
            ->assertJson(['message' => 'LOGIN_LOCKED_OUT']);

        $this->assertGuest();
        $this->assertSame(5, AuditLog::query()->where('action_type', ActionType::LOGIN_FAILED)->count());
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::LOGIN_LOCKED_OUT)->count());
    }

    public function test_lockout_remains_after_fourteen_minutes_and_clears_after_fifteen(): void
    {
        Carbon::setTestNow('2026-07-24 10:00:00');

        User::factory()->create([
            'email' => 'ttl@example.com',
            'password' => 'password',
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => 'ttl@example.com',
                'password' => 'bad-password',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => 'ttl@example.com',
            'password' => 'password',
        ])->assertStatus(429)
            ->assertJson(['message' => 'LOGIN_LOCKED_OUT']);

        Carbon::setTestNow('2026-07-24 10:14:00');

        $this->postJson('/login', [
            'email' => 'ttl@example.com',
            'password' => 'password',
        ])->assertStatus(429)
            ->assertJson(['message' => 'LOGIN_LOCKED_OUT']);

        Carbon::setTestNow('2026-07-24 10:15:01');

        $this->postJson('/login', [
            'email' => 'ttl@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJson(['message' => 'LOGIN_SUCCESS']);

        $this->assertAuthenticated();
    }

    public function test_lockout_is_separated_per_ip(): void
    {
        User::factory()->create([
            'email' => 'ip@example.com',
            'password' => 'password',
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/login', [
                    'email' => 'ip@example.com',
                    'password' => 'bad-password',
                ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/login', [
                'email' => 'ip@example.com',
                'password' => 'password',
            ])->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/login', [
                'email' => 'ip@example.com',
                'password' => 'password',
            ])->assertOk()
            ->assertJson(['message' => 'LOGIN_SUCCESS']);
    }

    public function test_successful_login_clears_lockout_counter(): void
    {
        $user = User::factory()->create([
            'email' => 'clear@example.com',
            'password' => 'password',
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/login', [
                'email' => 'clear@example.com',
                'password' => 'bad',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => 'clear@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', [
                'email' => 'clear@example.com',
                'password' => 'bad',
            ])->assertStatus(422);
        }

        $this->postJson('/login', [
            'email' => 'clear@example.com',
            'password' => 'bad',
        ])->assertStatus(429);
    }

    public function test_password_policy_rejects_weak_passwords_and_accepts_three_of_four_classes(): void
    {
        $user = User::factory()->create([
            'email' => 'pwd@example.com',
            'password' => 'OldPassword1!',
            'must_change_password' => true,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($user);

        $this->postJson('/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
        ])->assertStatus(422);

        $this->postJson('/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'alllowercase1', // lower + digit only = 2 classes
            'password_confirmation' => 'alllowercase1',
        ])->assertStatus(422);

        // 6 graphemes / 12 UTF-8 bytes — must not pass via byte-length
        $this->postJson('/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'Aa1あいう',
            'password_confirmation' => 'Aa1あいう',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // exactly 12 characters, 3 of 4 classes (upper/lower/digit)
        $this->postJson('/user/password', [
            'current_password' => 'OldPassword1!',
            'password' => 'ValidPass123',
            'password_confirmation' => 'ValidPass123',
        ])->assertOk()
            ->assertJson(['message' => 'PASSWORD_CHANGED']);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('ValidPass123', $user->password));

        $audit = AuditLog::query()->sole();
        $this->assertSame(ActionType::PASSWORD_CHANGED, $audit->action_type);
        $this->assertSame($user->getKey(), $audit->detail['user_id']);
        $this->assertTrue($audit->detail['forced']);
    }

    public function test_must_change_password_blocks_other_routes_until_password_updated(): void
    {
        $user = User::factory()->create([
            'email' => 'force@example.com',
            'password' => 'TempPassword1!',
            'must_change_password' => true,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($user);

        $this->getJson('/auth/session')
            ->assertForbidden()
            ->assertJson(['message' => 'MUST_CHANGE_PASSWORD']);

        $this->postJson('/user/password', [
            'current_password' => 'TempPassword1!',
            'password' => 'ChangedPass12',
            'password_confirmation' => 'ChangedPass12',
        ])->assertOk();

        $this->getJson('/auth/session')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);
    }

    public function test_global_middleware_blocks_non_auth_routes_for_inactive_and_must_change(): void
    {
        Route::middleware(['web', 'auth'])->get('/__test/internal', function () {
            return response()->json(['ok' => true]);
        });

        $inactive = User::factory()->create([
            'must_change_password' => false,
            'status_akun' => 'Nonaktif',
        ]);

        $this->actingAs($inactive)
            ->getJson('/__test/internal')
            ->assertForbidden()
            ->assertJson(['message' => 'LOGIN_INACTIVE']);

        $this->assertGuest();

        $forced = User::factory()->create([
            'password' => 'TempPassword1!',
            'must_change_password' => true,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($forced)
            ->getJson('/__test/internal')
            ->assertForbidden()
            ->assertJson(['message' => 'MUST_CHANGE_PASSWORD']);

        $this->actingAs($forced)
            ->postJson('/user/password', [
                'current_password' => 'TempPassword1!',
                'password' => 'ChangedPass12',
                'password_confirmation' => 'ChangedPass12',
            ])->assertOk()
            ->assertJson(['message' => 'PASSWORD_CHANGED']);

        $stillForced = User::factory()->create([
            'password' => 'TempPassword1!',
            'must_change_password' => true,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($stillForced)
            ->postJson('/logout')
            ->assertOk()
            ->assertJson(['message' => 'LOGOUT']);

        $this->assertGuest();
    }

    public function test_guest_is_denied_with_401_on_internal_routes(): void
    {
        Route::middleware(['web', 'auth'])->get('/__test/internal', function () {
            return response()->json(['ok' => true]);
        });

        $this->getJson('/__test/internal')->assertUnauthorized();
        $this->getJson('/auth/session')->assertUnauthorized();
    }

    public function test_deactivated_user_session_is_rejected_with_403(): void
    {
        $user = User::factory()->create([
            'email' => 'mid@example.com',
            'password' => 'password',
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($user);

        $user->forceFill(['status_akun' => 'Nonaktif'])->save();

        $this->getJson('/auth/session')
            ->assertForbidden()
            ->assertJson(['message' => 'LOGIN_INACTIVE']);

        $this->assertGuest();
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'status_akun' => 'Aktif',
        ]);

        $this->actingAs($user);

        $this->postJson('/logout')
            ->assertOk()
            ->assertJson(['message' => 'LOGOUT']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::LOGOUT->value,
            'actor_id' => $user->getKey(),
            'entity_id' => $user->getKey(),
        ]);
    }

    public function test_bcrypt_cost_is_twelve_when_configured_explicitly(): void
    {
        config(['hashing.bcrypt.rounds' => 12]);

        $hash = Hash::make('ExplicitCost12!');

        $this->assertSame(12, Hash::info($hash)['options']['cost'] ?? null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow();
        RateLimiter::clear('login:lock@example.com|127.0.0.1');
        RateLimiter::clear('login:clear@example.com|127.0.0.1');
        RateLimiter::clear('login:ttl@example.com|127.0.0.1');
        RateLimiter::clear('login:ip@example.com|10.0.0.1');
        RateLimiter::clear('login:ip@example.com|10.0.0.2');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
