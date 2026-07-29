<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateDraftService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * BR-CON-01 — dua update draft paralel dengan version yang sama:
 * tepat satu sukses, satu 409 CONFLICT (optimistic lock only).
 */
class CandidateDraftConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** Forked workers need committed rows visible outside the parent transaction. */
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

    public function test_parallel_draft_updates_yield_one_success_and_one_conflict(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);

        $country = DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Draft',
            'tanggal_lahir' => '2001-03-03',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkUpdate((int) $staff->getKey(), (int) $created->id, 0, 'Writer A', $startAt),
            $this->forkUpdate((int) $staff->getKey(), (int) $created->id, 0, 'Writer B', $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');

        $this->assertSame([0, 10], $exitCodes, 'exactly one update succeeds, one gets CONFLICT (409)');

        $fresh = DB::table('candidate')->where('id', $created->id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(1, (int) $fresh->version);
        $this->assertContains($fresh->nama_alphabet, ['Writer A', 'Writer B']);
        $this->assertNull($fresh->nomor_induk);
        $this->assertSame('Draft', $fresh->status_approval);
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::CANDIDATE_UPDATED)->count(),
        );
    }

    private function forkUpdate(int $actorId, int $candidateId, int $version, string $name, float $startAt): int
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

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(CandidateDraftService::class)->updateDraft($actor, $candidateId, [
                'version' => $version,
                'nama_alphabet' => $name,
            ]);

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame(
            '',
            $migrator,
            'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).'
        );

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, pending_request, candidate, negara, model_has_roles, model_has_permissions, users '
            .'RESTART IDENTITY CASCADE'
        );
    }
}
