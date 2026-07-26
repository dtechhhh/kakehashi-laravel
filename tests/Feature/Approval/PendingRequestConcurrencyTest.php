<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * BR-APV-07 — dua Checker memutus pending yang sama nyaris bersamaan:
 * tepat satu sukses, satu 409. Revalidasi terjadi di dalam transaksi.
 */
class PendingRequestConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_concurrent_approval_yields_one_success_and_one_conflict(): void
    {
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);

        $checkerA = User::factory()->active()->create();
        $checkerA->assignRole(Rbac::JOB_MANAGER);
        $checkerB = User::factory()->active()->create();
        $checkerB->assignRole(Rbac::JOB_MANAGER);

        $request = app(PendingRequestService::class)->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 31,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $startAt = microtime(true) + 0.5;

        $pids = [
            $this->forkApproval($request->getKey(), $checkerA->getKey(), $startAt),
            $this->forkApproval($request->getKey(), $checkerB->getKey(), $startAt),
        ];

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        sort($exitCodes);

        DB::purge('pgsql');

        $this->assertSame([0, 10], $exitCodes, 'exactly one approval succeeds, one gets APV_DONE (409)');

        $fresh = PendingRequest::query()->findOrFail($request->getKey());

        $this->assertSame(PendingStatus::APPROVED, $fresh->status);
        $this->assertContains($fresh->checker_id, [$checkerA->getKey(), $checkerB->getKey()]);
        $this->assertNotNull($fresh->decided_at);

        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count(),
            'the losing decision must not leave an audit trail'
        );
    }

    private function forkApproval(int $requestId, int $checkerId, float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(PendingRequestService::class)->approve(
                requestId: $requestId,
                checkerId: $checkerId,
                auditAction: ActionType::IC_APPROVED,
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'APV_DONE' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    /**
     * audit_log is append-only for the runtime role; the owner truncates it
     * (TRUNCATE bypasses the row-level immutability trigger by design).
     */
    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');

        $this->assertNotSame(
            '',
            $migrator,
            'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).'
        );

        DB::connection('pgsql_migrator')->statement('TRUNCATE audit_log, pending_request RESTART IDENTITY');

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
