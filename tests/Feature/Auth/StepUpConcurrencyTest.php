<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Modules\Auth\Public\StepUpService;
use Modules\Auth\StepUpAction;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;
use Throwable;

/**
 * Proves single-use step-up under concurrent same-session HTTP with Redis
 * session blocking (StartSession lock covers load → require → mutate → save).
 *
 * Workers are separate PHP processes (not pcntl forks of this process) so Redis
 * session + lock are truly shared without inherited connection/auth state.
 */
class StepUpConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    private string $mutationKey = '';

    protected function setUp(): void
    {
        $this->forceRedisSessionEnv();

        parent::setUp();

        $this->forceRedisSessionConfig();
        $this->assertSame('redis', config('session.driver'));
        $this->assertTrue((bool) config('session.block'));
        $this->assertSame('redis', config('session.block_store'));
        $this->assertSame(30, (int) config('session.block_lock_seconds'));
        $this->assertSame(10, (int) config('session.block_wait_seconds'));

        $this->mutationKey = 'test:stepup:mutate:'.Str::uuid()->toString();

        $this->cleanState();
    }

    protected function tearDown(): void
    {
        $this->cleanState();
        // Restore phpunit defaults so later tests don't inherit Redis session/cache.
        $this->restorePhpunitEnv();
        parent::tearDown();
    }

    public function test_parallel_same_session_step_up_allows_exactly_one_mutation(): void
    {
        $user = $this->userWithConfirmedTwoFactor([
            'email' => 'stepup-concurrent@example.com',
            'password' => 'ValidPass123',
        ]);

        // Real HTTP elevation (password + TOTP).
        $this->postJson('/login', [
            'email' => 'stepup-concurrent@example.com',
            'password' => 'ValidPass123',
        ])->assertOk()->assertJsonPath('message', 'TWOFA_REQUIRED');

        $this->postJson('/two-factor-challenge', [
            'code' => $this->currentTotp($user),
        ])->assertOk()->assertJsonPath('message', 'LOGIN_SUCCESS');

        $this->postJson('/user/step-up', [
            'password' => 'ValidPass123',
            'code' => $this->currentTotp($user),
            'action' => StepUpAction::ANONYMIZE_PII,
            'entity_type' => 'candidate',
            'entity_id' => 42,
        ])->assertOk()->assertJsonPath('message', 'STEPUP_OK');

        $this->assertTrue(
            app(StepUpService::class)->hasValidElevation(
                StepUpAction::ANONYMIZE_PII,
                'candidate',
                42,
            )
        );

        $sessionId = session()->getId();
        $this->assertNotEmpty($sessionId);
        session()->save();

        $raw = $this->app['session']->driver('redis')->getHandler()->read($sessionId);
        $this->assertNotSame('', $raw);
        $this->assertStringContainsString('ANONYMIZE_PII', (string) $raw);

        Cache::store('redis')->forever($this->mutationKey, 0);

        $startAt = microtime(true) + 0.5;
        $worker = base_path('tests/workers/stepup_consume.php');
        $php = PHP_BINARY;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processes = [];
        $pipes = [];
        foreach ([0, 1] as $i) {
            $cmd = sprintf(
                '%s %s %s %s %d %s',
                escapeshellarg($php),
                escapeshellarg($worker),
                escapeshellarg($sessionId),
                escapeshellarg($this->mutationKey),
                42,
                escapeshellarg((string) $startAt),
            );
            $processes[$i] = proc_open($cmd, $descriptors, $pipes[$i], base_path());
            $this->assertIsResource($processes[$i]);
            fclose($pipes[$i][0]);
        }

        $statuses = [];
        $stderr = [];
        foreach ($processes as $i => $process) {
            $stderr[$i] = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $statuses[] = proc_close($process);
        }

        sort($statuses);
        $this->assertSame(
            [0, 1],
            $statuses,
            'Exactly one concurrent consume must succeed. statuses='.json_encode($statuses).' stderr='.json_encode($stderr)
        );

        $this->assertSame(1, (int) Cache::store('redis')->get($this->mutationKey));
    }

    private function forceRedisSessionEnv(): void
    {
        foreach ([
            'SESSION_DRIVER' => 'redis',
            'CACHE_STORE' => 'redis',
            'SESSION_BLOCK' => 'true',
            'SESSION_BLOCK_STORE' => 'redis',
            'SESSION_BLOCK_LOCK_SECONDS' => '30',
            'SESSION_BLOCK_WAIT_SECONDS' => '10',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function restorePhpunitEnv(): void
    {
        foreach ([
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_BLOCK' => 'false',
            'SESSION_BLOCK_STORE' => 'redis',
            'SESSION_BLOCK_LOCK_SECONDS' => '30',
            'SESSION_BLOCK_WAIT_SECONDS' => '10',
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function forceRedisSessionConfig(): void
    {
        config([
            'session.driver' => 'redis',
            'session.block' => true,
            'session.block_store' => 'redis',
            'session.block_lock_seconds' => 30,
            'session.block_wait_seconds' => 10,
            'cache.default' => 'redis',
        ]);

        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance(SessionManager::class);

        if ($this->app->bound('session')) {
            $this->app['session']->setDefaultDriver('redis');
        }
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
        Cache::store('redis')->forget('fortify.2fa_codes.'.md5($code));
        Cache::forget('fortify.2fa_codes.'.md5($code));

        return $code;
    }

    private function cleanState(): void
    {
        if ($this->mutationKey !== '') {
            try {
                Cache::store('redis')->forget($this->mutationKey);
            } catch (Throwable) {
                //
            }
        }

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
