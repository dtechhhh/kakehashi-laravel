<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateRevisionService;
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
 * W3-T5 / FIX1 / W3-T6-FIX2 — concurrent revision create/approve + reject vs availability race.
 */
class CandidateRevisionConcurrencyTest extends TestCase
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

    public function test_concurrent_create_revision_yields_one_success_and_one_active_conflict(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

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
            'nama_alphabet' => 'Concurrent Create Main',
            'tanggal_lahir' => '1998-08-08',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'F',
        ]);
        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );
        $newPending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_NEW)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $newPending->getKey(),
            ['version' => (int) $submitted->version],
        );

        $mainId = (int) $created->id;
        $mainVersion = (int) $approved->version;
        $staffId = (int) $staff->getKey();
        $startAt = microtime(true) + 0.3;

        $pids = [
            $this->forkCreateRevision($mainId, $staffId, $mainVersion, $startAt),
            $this->forkCreateRevision($mainId, $staffId, $mainVersion, $startAt),
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
            'exactly one createRevision succeeds, one gets CANDIDATE_REVISION_ACTIVE (409)',
        );

        $this->assertSame(
            1,
            DB::table('candidate')
                ->where('parent_candidate_id', $mainId)
                ->whereIn('status_approval', ['Draft', 'Menunggu Tinjauan-REVISI', 'Ditolak'])
                ->count(),
        );
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'version' => $mainVersion,
        ]);
    }

    public function test_concurrent_revision_approve_yields_one_success_and_one_conflict(): void
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
            'nama_alphabet' => 'Concurrent Revision Main',
            'tanggal_lahir' => '1999-09-09',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);
        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );
        $newPending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_NEW)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approverA);
        $approved = app(CandidateApprovalService::class)->approve(
            $approverA,
            (int) $newPending->getKey(),
            ['version' => (int) $submitted->version],
        );
        $nik = (string) $approved->nomor_induk;
        $mainId = (int) $created->id;

        $this->actingAs($staff);
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => (int) $approved->version],
        );
        $updated = app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => 0,
            'nama_alphabet' => 'Concurrent Revision Name',
        ]);
        $waiting = app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $updated->id,
            ['version' => (int) $updated->version],
        );
        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $pendingId = (int) $pending->getKey();
        $revisionVersion = (int) $waiting->version;
        $revisionId = (int) $revision->id;
        $startAt = microtime(true) + 0.3;

        $pids = [
            $this->forkApprove($pendingId, (int) $approverA->getKey(), $revisionVersion, $startAt),
            $this->forkApprove($pendingId, (int) $approverB->getKey(), $revisionVersion, $startAt),
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
            'exactly one revision approval succeeds, one gets conflict (409)',
        );

        $freshMain = DB::table('candidate')->where('id', $mainId)->first();
        $this->assertSame('Disetujui', $freshMain->status_approval);
        $this->assertSame($nik, $freshMain->nomor_induk);
        $this->assertSame('Concurrent Revision Name', $freshMain->nama_alphabet);

        $freshRev = DB::table('candidate')->where('id', $revisionId)->first();
        $this->assertSame('Diterapkan', $freshRev->status_approval);
        $this->assertNull($freshRev->nomor_induk);

        $pendingFresh = PendingRequest::query()->findOrFail($pendingId);
        $this->assertSame(PendingStatus::APPROVED, $pendingFresh->status);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_APPROVED->value)
                ->where('detail->pending_type', PendingType::CANDIDATE_REVISION->value)
                ->count(),
        );
    }

    /**
     * Deterministic race: reject reads main snapshot, then markInUse commits before
     * reject final validation → ConflictHttpException(CONFLICT) + full rollback.
     *
     * Coordination uses a temporary test-only AFTER UPDATE barrier on pending_request
     * (advisory lock), not a production code hook.
     */
    public function test_reject_conflicts_when_mark_in_use_commits_before_final_validation(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

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
            'nama_alphabet' => 'Reject Race Main',
            'tanggal_lahir' => '1997-07-07',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);
        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );
        $newPending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_NEW)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $newPending->getKey(),
            ['version' => (int) $submitted->version],
        );
        $mainId = (int) $created->id;
        $nik = (string) $approved->nomor_induk;
        $mainVersion = (int) $approved->version;
        $mainName = (string) $approved->nama_alphabet;

        $this->actingAs($staff);
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );
        $updated = app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => 0,
            'nama_alphabet' => 'Reject Race Revision',
        ]);
        $waiting = app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $updated->id,
            ['version' => (int) $updated->version],
        );

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $pendingId = (int) $pending->getKey();
        $revisionId = (int) $revision->id;
        $revisionVersion = (int) $waiting->version;
        $approverId = (int) $approver->getKey();

        $auditBefore = AuditLog::query()->count();
        $rejectAuditsBefore = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_REJECTED->value)
            ->count();
        $notifBefore = DB::table('notifications')->count();

        // Barrier keys must stay in int32 for pg_locks classid/objid.
        $lockClass = 913_377;
        $lockObj = 2;

        $this->installRejectBarrier($lockClass, $lockObj);

        try {
            // Hold barrier on migrator session so fork/purge of runtime pgsql never drops it.
            $barrier = DB::connection('pgsql_migrator');
            $barrier->select('SELECT pg_advisory_lock(?, ?)', [$lockClass, $lockObj]);

            $pid = $this->forkReject($pendingId, $approverId, $revisionVersion);

            // Drop inherited runtime socket after fork; barrier stays on migrator.
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $this->waitForAdvisoryWaiter($lockClass, $lockObj);

            app(CandidateAvailabilityService::class)->markInUse($mainId, $mainVersion);

            $this->assertDatabaseHas('candidate', [
                'id' => $mainId,
                'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
                'version' => $mainVersion + 1,
                'nomor_induk' => $nik,
            ]);

            $barrier->select('SELECT pg_advisory_unlock(?, ?)', [$lockClass, $lockObj]);

            $status = 0;
            pcntl_waitpid($pid, $status);
            $exitCode = pcntl_wexitstatus($status);

            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $this->assertSame(
                10,
                $exitCode,
                'reject must yield CONFLICT (409) when availability drifts mid-transaction',
            );

            $this->assertDatabaseHas('pending_request', [
                'id' => $pendingId,
                'status' => PendingStatus::PENDING->value,
                'checker_id' => null,
            ]);
            $this->assertDatabaseHas('candidate', [
                'id' => $revisionId,
                'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
                'version' => $revisionVersion,
                'nomor_induk' => null,
            ]);
            $this->assertDatabaseHas('candidate', [
                'id' => $mainId,
                'status_approval' => CandidateApprovalStatus::Disetujui->value,
                'nama_alphabet' => $mainName,
                'nomor_induk' => $nik,
                'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
                'version' => $mainVersion + 1,
            ]);
            $this->assertSame($auditBefore, AuditLog::query()->count());
            $this->assertSame(
                $rejectAuditsBefore,
                AuditLog::query()->where('action_type', ActionType::CANDIDATE_REJECTED->value)->count(),
            );
            $this->assertSame($notifBefore, DB::table('notifications')->count());

            // Retry reject after operational drift succeeds; main stays untouched.
            $this->actingAs($approver);
            $rejected = app(CandidateApprovalService::class)->reject(
                $approver,
                $pendingId,
                'retry after availability race',
                ['version' => $revisionVersion],
            );

            $this->assertSame(CandidateApprovalStatus::Ditolak->value, $rejected->status_approval);
            $this->assertDatabaseHas('candidate', [
                'id' => $mainId,
                'nomor_induk' => $nik,
                'nama_alphabet' => $mainName,
                'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
                'version' => $mainVersion + 1,
            ]);
            $this->assertDatabaseHas('pending_request', [
                'id' => $pendingId,
                'status' => PendingStatus::REJECTED->value,
                'checker_id' => $approverId,
            ]);
            $this->assertDatabaseHas('candidate', [
                'id' => $revisionId,
                'status_approval' => CandidateApprovalStatus::Ditolak->value,
            ]);
        } finally {
            try {
                DB::connection('pgsql_migrator')->select(
                    'SELECT pg_advisory_unlock(?, ?)',
                    [$lockClass, $lockObj],
                );
            } catch (Throwable) {
                // already unlocked or connection reset
            }
            $this->dropRejectBarrier();
        }
    }

    private function installRejectBarrier(int $lockClass, int $lockObj): void
    {
        $migrator = DB::connection('pgsql_migrator');
        $migrator->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION w3_t6_fix2_reject_barrier()
            RETURNS trigger
            LANGUAGE plpgsql
            AS \$\$
            BEGIN
                IF OLD.status = 'pending' AND NEW.status = 'rejected' THEN
                    PERFORM pg_advisory_lock({$lockClass}, {$lockObj});
                    PERFORM pg_advisory_unlock({$lockClass}, {$lockObj});
                END IF;
                RETURN NEW;
            END;
            \$\$;

            DROP TRIGGER IF EXISTS trg_w3_t6_fix2_reject_barrier ON pending_request;
            CREATE TRIGGER trg_w3_t6_fix2_reject_barrier
                AFTER UPDATE ON pending_request
                FOR EACH ROW
                EXECUTE FUNCTION w3_t6_fix2_reject_barrier();
            SQL);
    }

    private function dropRejectBarrier(): void
    {
        $migrator = DB::connection('pgsql_migrator');
        $migrator->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_w3_t6_fix2_reject_barrier ON pending_request;
            DROP FUNCTION IF EXISTS w3_t6_fix2_reject_barrier();
            SQL);
    }

    private function waitForAdvisoryWaiter(int $lockClass, int $lockObj, int $timeoutMs = 10_000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $conn = DB::connection('pgsql_migrator');

        while (microtime(true) < $deadline) {
            $waiting = $conn->selectOne(
                'SELECT 1 AS ok FROM pg_locks
                 WHERE locktype = ? AND classid = ? AND objid = ? AND NOT granted
                 LIMIT 1',
                ['advisory', $lockClass, $lockObj],
            );

            if ($waiting !== null) {
                return;
            }

            usleep(5_000);
        }

        $this->fail('reject worker never blocked on advisory barrier lock');
    }

    private function forkReject(int $requestId, int $checkerId, int $version): int
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

            app(CandidateApprovalService::class)->reject(
                $checker,
                $requestId,
                'reject while availability races',
                ['version' => $version],
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function forkCreateRevision(int $mainId, int $staffId, int $mainVersion, float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $staff = User::query()->findOrFail($staffId);
            Auth::login($staff);

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(CandidateRevisionService::class)->createRevision(
                $staff,
                $mainId,
                ['version' => $mainVersion],
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CANDIDATE_REVISION_ACTIVE' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
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
            .'candidate_document, candidate_photo, candidate_immigration, candidate_family_contact, '
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
