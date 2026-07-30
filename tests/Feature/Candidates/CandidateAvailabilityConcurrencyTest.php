<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * W3-T6-FIX1 — dual markInUse on same candidate/version: one success, one 409.
 */
class CandidateAvailabilityConcurrencyTest extends TestCase
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

    public function test_parallel_mark_in_use_yields_one_success_and_one_conflict(): void
    {
        [$id, $version] = $this->approvedFixture();

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkMarkInUse($id, $version, $startAt),
            $this->forkMarkInUse($id, $version, $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');

        $this->assertSame([0, 10], $exitCodes, 'exactly one markInUse succeeds, one gets CONFLICT (409)');

        $fresh = DB::table('candidate')->where('id', $id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(CandidateAvailability::SedangDipakai->value, $fresh->status_ketersediaan);
        $this->assertSame($version + 1, (int) $fresh->version);
    }

    private function forkMarkInUse(int $candidateId, int $version, float $startAt): int
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

            app(CandidateAvailabilityService::class)->markInUse($candidateId, $version);

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function approvedFixture(): array
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        $country = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Availability',
            'tanggal_lahir' => '2000-04-04',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);

        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );

        $pending = PendingRequest::query()
            ->where('target_type', 'candidate')
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pending->getKey(),
            ['version' => (int) $submitted->version],
        );

        return [(int) $approved->id, (int) $approved->version];
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
            .'candidate_physical, candidate_photo, candidate, negara RESTART IDENTITY CASCADE',
        );

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
