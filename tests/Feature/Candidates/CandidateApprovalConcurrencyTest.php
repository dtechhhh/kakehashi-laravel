<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * W3-T4 — two Approvers decide the same CANDIDATE_NEW pending concurrently:
 * exactly one success, one conflict; candidate ends Disetujui once.
 */
class CandidateApprovalConcurrencyTest extends TestCase
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

    public function test_concurrent_approve_yields_one_success_and_one_conflict(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approverA = User::factory()->active()->create();
        $approverA->assignRole(Rbac::CANDIDATE_APPROVER);
        $approverB = User::factory()->active()->create();
        $approverB->assignRole(Rbac::CANDIDATE_APPROVER);

        $country = DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Approve',
            'tanggal_lahir' => '1999-09-09',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);
        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkApprove(
                (int) $pending->getKey(),
                (int) $approverA->getKey(),
                (int) $submitted->version,
                $startAt,
            ),
            $this->forkApprove(
                (int) $pending->getKey(),
                (int) $approverB->getKey(),
                (int) $submitted->version,
                $startAt,
            ),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');

        $this->assertSame(
            [0, 10],
            $exitCodes,
            'exactly one approval succeeds, one gets conflict (409)',
        );

        $fresh = DB::table('candidate')->where('id', $created->id)->first();
        $this->assertSame('Disetujui', $fresh->status_approval);
        $this->assertContains((int) $fresh->approved_by, [
            (int) $approverA->getKey(),
            (int) $approverB->getKey(),
        ]);
        $this->assertNotSame((int) $staff->getKey(), (int) $fresh->approved_by);
        $this->assertSame((int) $submitted->version + 1, (int) $fresh->version);
        $this->assertSame($submitted->nomor_induk, $fresh->nomor_induk);

        $pendingFresh = PendingRequest::query()->findOrFail($pending->getKey());
        $this->assertSame(PendingStatus::APPROVED, $pendingFresh->status);
        $this->assertContains((int) $pendingFresh->checker_id, [
            (int) $approverA->getKey(),
            (int) $approverB->getKey(),
        ]);

        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED->value)->count(),
            'losing decision must not leave an approve audit',
        );
    }

    private function forkApprove(int $requestId, int $checkerId, int $version, float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $checker = User::query()->findOrFail($checkerId);
            Auth::login($checker);

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(CandidateApprovalService::class)->approve(
                $checker,
                $requestId,
                ['version' => $version],
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit(in_array($exception->getMessage(), ['APV_DONE', 'CONFLICT'], true) ? 10 : 20);
        } catch (AccessDeniedHttpException) {
            exit(20);
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
            .'(set -a; source .env.migrator; set +a — or inject it in CI).',
        );

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, notifications, pending_request, nik_counter, '
            .'candidate_document, candidate_immigration, candidate_family_contact, '
            .'candidate_family, candidate_self_promo, candidate_qual_other, '
            .'candidate_qual_driving, candidate_qual_ssw, candidate_qual_japanese, '
            .'candidate_qual_english, candidate_work, candidate_education, '
            .'candidate_physical, candidate, negara RESTART IDENTITY CASCADE',
        );

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
