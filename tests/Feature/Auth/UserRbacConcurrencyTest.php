<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\UserRbacService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

class UserRbacConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUsers();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanUsers();

        parent::tearDown();
    }

    public function test_concurrent_cross_deactivation_keeps_one_active_super_admin(): void
    {
        $adminA = User::factory()->create();
        $adminA->assignRole(Rbac::SUPER_ADMIN);
        $adminB = User::factory()->create();
        $adminB->assignRole(Rbac::SUPER_ADMIN);
        $startAt = microtime(true) + 0.5;

        $pids = [
            $this->forkDeactivation($adminA->getKey(), $adminB->getKey(), $startAt),
            $this->forkDeactivation($adminB->getKey(), $adminA->getKey(), $startAt),
        ];

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);

        $this->assertSame([0, 10], $exitCodes);
        $this->assertSame(
            1,
            User::query()
                ->where('status_akun', 'Aktif')
                ->whereHas('roles', fn ($query) => $query->where('name', Rbac::SUPER_ADMIN))
                ->count()
        );
        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::USER_DEACTIVATED)->count()
        );
    }

    private function forkDeactivation(int $actorId, int $targetId, float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor = User::query()->findOrFail($actorId);
            Auth::login($actor);
            session([
                'stepup.tokens' => [
                    StepUpAction::USER_ROLE_OR_DEACTIVATE.'.user.'.$targetId => now()->addMinutes(5)->getTimestamp(),
                ],
            ]);

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(UserRbacService::class)->deactivateUser(
                $actor,
                User::query()->findOrFail($targetId)
            );

            exit(0);
        } catch (AuthorizationException $exception) {
            exit($exception->getMessage() === 'USR_ADMIN_ONLY' ? 10 : 20);
        } catch (ValidationException $exception) {
            $errors = json_encode($exception->errors());

            exit(str_contains((string) $errors, 'USR_LAST_SUPERADMIN') ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function cleanUsers(): void
    {
        DB::connection('pgsql_migrator')->statement('TRUNCATE audit_log RESTART IDENTITY');
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
