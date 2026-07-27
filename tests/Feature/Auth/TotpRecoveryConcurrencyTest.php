<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Modules\Auth\Http\Controllers\TwoFactorChallengeController;
use PragmaRX\Google2FA\Google2FA;
use ReflectionMethod;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;
use Throwable;

/**
 * Recovery-code concurrency needs real PostgreSQL commits (row locks).
 */
class TotpRecoveryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUsers();
    }

    protected function tearDown(): void
    {
        $this->cleanUsers();
        parent::tearDown();
    }

    public function test_concurrent_recovery_code_consumption_allows_exactly_one_success(): void
    {
        $user = $this->userWithConfirmedTwoFactor();
        $code = $user->recoveryCodes()[0];
        $userId = (int) $user->getKey();
        $startAt = microtime(true) + 0.4;

        $pids = [
            $this->forkRecoveryConsume($userId, $code, $startAt),
            $this->forkRecoveryConsume($userId, $code, $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);
        $this->assertSame([0, 1], $exitCodes, 'Exactly one consumer must succeed under row lock.');

        $user->refresh();
        $this->assertFalse(in_array($code, $user->recoveryCodes(), true));
        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::TWOFA_RECOVERY_USED)->count(),
        );
    }

    private function forkRecoveryConsume(int $userId, string $code, float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            $controller = app(TwoFactorChallengeController::class);
            $method = new ReflectionMethod($controller, 'consumeRecoveryCode');
            $ok = $method->invoke($controller, $userId, $code);

            exit($ok ? 0 : 1);
        } catch (Throwable) {
            exit(2);
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

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        Cache::forget('fortify.2fa_codes.'.md5($code));
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function cleanUsers(): void
    {
        DB::connection('pgsql_migrator')->statement('TRUNCATE audit_log RESTART IDENTITY');
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
