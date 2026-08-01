<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\Candidates\Services\CandidateSubmitService;
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
 * W3-R4 — formal proof of the full resubmit-main lifecycle (BLOCKER-1):
 * Draft → submit → reject → fix → resubmitMain → approve (Bukti Sukses Minimum #1),
 * NIK preserved, nik_counter never bumps, no-change gate, authorization, real
 * PostgreSQL concurrency (two resubmits → one 409), and repeatable reject cycles.
 *
 * Concurrency test #7 uses a deterministic test-only advisory-lock barrier
 * (trigger on candidate), mirroring CandidateRevisionConcurrencyTest.
 */
class CandidateResubmitMainTest extends TestCase
{
    use RefreshDatabase;

    /** Forked workers need committed rows visible outside the parent transaction. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_resubmit_rejected_main_keeps_nik_and_does_not_bump_counter(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Resubmit Main'));

        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan identitas',
            ['version' => (int) $submitted->version],
        );

        $nikBeforeReject = DB::table('candidate')->where('id', $created->id)->value('nomor_induk');
        $counterBefore = (int) DB::table('nik_counter')->where('year', 2027)->value('last_value');
        $this->assertSame(1, $counterBefore);

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Resubmit Main Edited',
        ]);

        $resubmitted = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $updated->version,
        ]);

        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $resubmitted->status_approval);
        $this->assertSame(4, (int) $resubmitted->version);
        $this->assertSame($nikBeforeReject, $resubmitted->nomor_induk);

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => $nikBeforeReject,
            'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            'version' => 4,
        ]);

        $this->assertSame($counterBefore, (int) DB::table('nik_counter')->where('year', 2027)->value('last_value'));

        $newPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();
        $this->assertSame((int) $staff->getKey(), (int) $newPending->requested_by);

        $payload = $this->payloadArray($newPending);
        $this->assertArrayHasKey('aggregate_fingerprint', $payload);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['aggregate_fingerprint']);
        $this->assertSame(
            app(CandidateRevisionService::class)->aggregateFingerprint((int) $created->id),
            $payload['aggregate_fingerprint'],
        );

        $resubmitAudit = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_REVISION_SUBMITTED)
            ->where('entity_id', (int) $created->id)
            ->sole();
        $this->assertSame($staff->getKey(), $resubmitAudit->actor_id);
        $this->assertArrayNotHasKey('nomor_induk', $resubmitAudit->detail);
        $this->assertSame(
            CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            $resubmitAudit->detail['status_approval'],
        );

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_REVISION_SUBMITTED->value,
            'notifiable_id' => $approver->getKey(),
        ]);
    }

    public function test_resubmit_rejected_main_full_cycle_to_approved(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Full Cycle Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan identitas',
            ['version' => (int) $submitted->version],
        );

        $nikBeforeReject = (string) DB::table('candidate')->where('id', $created->id)->value('nomor_induk');
        $counterBefore = (int) DB::table('nik_counter')->where('year', 2027)->value('last_value');
        $this->assertSame(1, $counterBefore);

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Full Cycle Edited',
        ]);

        $resubmitted = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $updated->version,
        ]);
        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $resubmitted->status_approval);
        $this->assertSame($nikBeforeReject, (string) $resubmitted->nomor_induk);
        $this->assertSame(4, (int) $resubmitted->version);

        $resubmitPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $resubmitPending->id,
            ['version' => (int) $resubmitted->version],
        );

        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $approved->status_approval);
        $this->assertSame(5, (int) $approved->version);
        $this->assertSame($nikBeforeReject, (string) $approved->nomor_induk);
        $this->assertSame((int) $approver->getKey(), (int) $approved->approved_by);
        $this->assertNull($approved->catatan_penolakan_terakhir);

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => $nikBeforeReject,
            'status_approval' => CandidateApprovalStatus::Disetujui->value,
            'approved_by' => $approver->getKey(),
            'version' => 5,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $resubmitPending->id,
            'status' => PendingStatus::APPROVED->value,
        ]);
        $this->assertSame(
            $counterBefore,
            (int) DB::table('nik_counter')->where('year', 2027)->value('last_value'),
        );

        $approveAudit = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_APPROVED)
            ->where('entity_type', 'candidate')
            ->where('entity_id', (int) $created->id)
            ->sole();
        $this->assertSame($approver->getKey(), $approveAudit->actor_id);
    }

    public function test_resubmit_without_changes_returns_no_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'No Change Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan',
            ['version' => (int) $submitted->version],
        );

        $this->actingAs($staff);
        try {
            $submit->resubmitMain($staff, (int) $created->id, ['version' => 2]);
            $this->fail('Resubmit without changes must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NO_CHANGE']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'version' => 2,
        ]);
        $this->assertSame(
            0,
            DB::table('pending_request')
                ->where('target_id', $created->id)
                ->where('status', PendingStatus::PENDING->value)
                ->count(),
        );
    }

    public function test_resubmit_rejects_wrong_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $draftRow = $draft->createDraft($staff, $this->basePayload($country, 'Draft Status Main'));
        try {
            $submit->resubmitMain($staff, (int) $draftRow->id, ['version' => 0]);
            $this->fail('Draft main must not be resubmittable.');
        } catch (ValidationException $exception) {
            $this->assertSame(['status_approval' => ['CANDIDATE_NOT_REJECTED']], $exception->errors());
        }

        $approvedRow = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Approved Status Main'),
            'tanggal_lahir' => '1995-05-05',
        ]);
        $submitted = $submit->submit($staff, (int) $approvedRow->id, ['version' => 0]);
        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $approvedRow->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pendingRow->id,
            ['version' => (int) $submitted->version],
        );

        $this->actingAs($staff);
        try {
            $submit->resubmitMain($staff, (int) $approvedRow->id, ['version' => 2]);
            $this->fail('Approved main must not be resubmittable.');
        } catch (ValidationException $exception) {
            $this->assertSame(['status_approval' => ['CANDIDATE_NOT_REJECTED']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $approvedRow->id,
            'status_approval' => CandidateApprovalStatus::Disetujui->value,
            'nomor_induk' => $submitted->nomor_induk,
        ]);
    }

    public function test_resubmit_with_active_pending_returns_duplicate(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Duplicate Pending Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);
        $nik = (string) $submitted->nomor_induk;

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan',
            ['version' => (int) $submitted->version],
        );

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Duplicate Pending Edited',
        ]);

        $fingerprint = app(CandidateRevisionService::class)->aggregateFingerprint((int) $created->id);
        DB::table('pending_request')->insert([
            'type' => PendingType::CANDIDATE_NEW->value,
            'target_type' => 'candidate',
            'target_id' => $created->id,
            'requested_by' => $staff->getKey(),
            'payload' => json_encode(['aggregate_fingerprint' => $fingerprint]),
            'status' => PendingStatus::PENDING->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $submit->resubmitMain($staff, (int) $created->id, [
                'version' => (int) $updated->version,
            ]);
            $this->fail('Resubmit with an active pending must be a duplicate.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DUPLICATE', $exception->getMessage());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'version' => 3,
            'nomor_induk' => $nik,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'type' => PendingType::CANDIDATE_NEW->value,
            'target_id' => $created->id,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_resubmit_with_stale_version_returns_conflict(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Stale Version Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);
        $nik = (string) $submitted->nomor_induk;

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan',
            ['version' => (int) $submitted->version],
        );

        $this->actingAs($staff);
        try {
            $submit->resubmitMain($staff, (int) $created->id, ['version' => 1]);
            $this->fail('Stale version must be a conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'version' => 2,
            'nomor_induk' => $nik,
        ]);
        $this->assertSame(
            0,
            DB::table('pending_request')
                ->where('target_id', $created->id)
                ->where('status', PendingStatus::PENDING->value)
                ->count(),
        );
    }

    public function test_resubmit_requires_submit_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $created = app(CandidateDraftService::class)->createDraft($staff, $this->basePayload($country, 'Auth Main'));

        $this->actingAs($approver);
        $this->expectException(AuthorizationException::class);
        app(CandidateSubmitService::class)->resubmitMain($approver, (int) $created->id, ['version' => 0]);
    }

    public function test_maker_cannot_approve_own_resubmitted_main(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Self Approve Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan',
            ['version' => (int) $submitted->version],
        );

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Self Approve Edited',
        ]);
        $resubmitted = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $updated->version,
        ]);

        $resubmitPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        // Give Maker the checker permission so failure is SoD, not missing role.
        $staff->givePermissionTo('candidate.review');
        $this->actingAs($staff);

        try {
            app(CandidateApprovalService::class)->approve(
                $staff,
                (int) $resubmitPending->id,
                ['version' => (int) $resubmitted->version],
            );
            $this->fail('Maker self-approve of own resubmitted main must be denied.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            'approved_by' => null,
            'version' => 4,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $resubmitPending->id,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
    }

    public function test_concurrent_resubmits_yield_one_success_and_one_conflict(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Concurrent Resubmit'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan',
            ['version' => (int) $submitted->version],
        );

        $nik = (string) DB::table('candidate')->where('id', $created->id)->value('nomor_induk');
        $counterBefore = (int) DB::table('nik_counter')->where('year', 2027)->value('last_value');

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Concurrent Resubmit Edited',
        ]);
        $resubmitVersion = (int) $updated->version;

        // Barrier keys must stay in int32 for pg_locks classid/objid.
        $lockClass = 913_377;
        $lockObj = 3;

        $this->installResubmitBarrier($lockClass, $lockObj);

        try {
            // Hold barrier on migrator session so fork/purge of runtime pgsql never drops it.
            $barrier = DB::connection('pgsql_migrator');
            $barrier->select('SELECT pg_advisory_lock(?, ?)', [$lockClass, $lockObj]);

            $startAt = microtime(true) + 0.3;
            $pids = [
                $this->forkResubmit((int) $staff->getKey(), (int) $created->id, $resubmitVersion, $startAt),
                $this->forkResubmit((int) $staff->getKey(), (int) $created->id, $resubmitVersion, $startAt),
            ];

            // Drop inherited runtime socket after fork; barrier stays on migrator.
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $this->waitForBothBlockers($lockClass, $lockObj);

            $barrier->select('SELECT pg_advisory_unlock(?, ?)', [$lockClass, $lockObj]);

            $exitCodes = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wexitstatus($status);
            }
            sort($exitCodes);

            $this->assertSame(
                [0, 10],
                $exitCodes,
                'exactly one resubmit succeeds, one gets CONFLICT (409)',
            );

            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $fresh = DB::table('candidate')->where('id', $created->id)->first();
            $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $fresh->status_approval);
            $this->assertSame($resubmitVersion + 1, (int) $fresh->version);
            $this->assertSame($nik, $fresh->nomor_induk);
            $this->assertSame(
                1,
                DB::table('pending_request')
                    ->where('type', PendingType::CANDIDATE_NEW->value)
                    ->where('target_id', $created->id)
                    ->where('status', PendingStatus::PENDING->value)
                    ->count(),
            );
            $this->assertSame(
                $counterBefore,
                (int) DB::table('nik_counter')->where('year', 2027)->value('last_value'),
            );
        } finally {
            try {
                DB::connection('pgsql_migrator')->select(
                    'SELECT pg_advisory_unlock(?, ?)',
                    [$lockClass, $lockObj],
                );
            } catch (Throwable) {
                // already unlocked or connection reset
            }
            $this->dropResubmitBarrier();
        }
    }

    public function test_rejected_main_can_be_resubmitted_multiple_cycles(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));
        Queue::fake();

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Double Cycle Main'));
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);
        $nik = (string) $submitted->nomor_induk;
        $counterBefore = (int) DB::table('nik_counter')->where('year', 2027)->value('last_value');
        $this->assertSame(1, $counterBefore);

        $reject = function (int $version, string $note) use ($approver, $created): void {
            $this->actingAs($approver);
            $pending = DB::table('pending_request')
                ->where('type', PendingType::CANDIDATE_NEW->value)
                ->where('target_id', $created->id)
                ->where('status', PendingStatus::PENDING->value)
                ->sole();
            app(CandidateApprovalService::class)->reject(
                $approver,
                (int) $pending->id,
                $note,
                ['version' => $version],
            );
        };

        $reject((int) $submitted->version, 'perlu perbaikan pertama');
        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'version' => 2,
        ]);

        $this->actingAs($staff);
        $firstFix = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Double Cycle Fix 1',
        ]);
        $firstResubmit = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $firstFix->version,
        ]);
        $this->assertSame(4, (int) $firstResubmit->version);
        $this->assertSame($nik, (string) $firstResubmit->nomor_induk);

        $firstPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();
        $firstPayload = $this->payloadArray($firstPending);

        $reject(4, 'masih perlu perbaikan kedua');
        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'status_approval' => CandidateApprovalStatus::Ditolak->value,
            'version' => 5,
        ]);

        $rejectedFirst = DB::table('pending_request')->where('id', $firstPending->id)->sole();
        $this->assertSame(PendingStatus::REJECTED->value, $rejectedFirst->status);
        $rejectedPayload = $this->payloadArray($rejectedFirst);
        $this->assertSame($firstPayload['aggregate_fingerprint'], $rejectedPayload['aggregate_fingerprint']);

        $this->actingAs($staff);
        $secondFix = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 5,
            'nama_alphabet' => 'Double Cycle Fix 2',
        ]);
        $secondResubmit = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $secondFix->version,
        ]);
        $this->assertSame(7, (int) $secondResubmit->version);
        $this->assertSame($nik, (string) $secondResubmit->nomor_induk);

        $secondPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();
        $secondPayload = $this->payloadArray($secondPending);
        $this->assertNotSame(
            $firstPayload['aggregate_fingerprint'],
            $secondPayload['aggregate_fingerprint'],
            'fingerprint baseline must refresh after each fix',
        );
        $this->assertSame(
            app(CandidateRevisionService::class)->aggregateFingerprint((int) $created->id),
            $secondPayload['aggregate_fingerprint'],
        );

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $secondPending->id,
            ['version' => (int) $secondResubmit->version],
        );
        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $approved->status_approval);
        $this->assertSame(8, (int) $approved->version);
        $this->assertSame($nik, (string) $approved->nomor_induk);
        $this->assertSame(
            $counterBefore,
            (int) DB::table('nik_counter')->where('year', 2027)->value('last_value'),
        );
        $this->assertSame(
            2,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_REVISION_SUBMITTED)
                ->where('entity_id', (int) $created->id)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_APPROVED)
                ->where('entity_id', (int) $created->id)
                ->count(),
        );
    }

    private function staffInput(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        return $staff;
    }

    private function candidateApprover(): User
    {
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        return $approver;
    }

    private function seedCountry(): int
    {
        return DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $country, string $name): array
    {
        return [
            'nama_alphabet' => $name,
            'tanggal_lahir' => '2000-02-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadArray(object $pendingRow): array
    {
        $payload = $pendingRow->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $this->assertIsArray($payload);

        return $payload;
    }

    private function forkResubmit(int $staffId, int $candidateId, int $version, float $startAt): int
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

            // Child process must use the same frozen JST year as the parent fixture.
            Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));

            app(CandidateSubmitService::class)->resubmitMain($staff, $candidateId, [
                'version' => $version,
            ]);

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (ValidationException) {
            exit(21);
        } catch (Throwable) {
            exit(20);
        }
    }

    /**
     * Deterministic race: both workers read Ditolak + same version, then the first
     * UPDATE blocks in a test-only AFTER UPDATE trigger on candidate that takes the
     * advisory lock (held by the migrator session); the second worker blocks on the
     * first one's row lock. On unlock the first commits and the second's CAS
     * re-evaluates → 0 rows → ConflictHttpException('CONFLICT').
     */
    /**
     * Both workers must be in position before the barrier is released: worker A
     * blocked in the trigger on the advisory lock, worker B blocked on A's row
     * lock (transactionid/tuple wait). Otherwise B may read the row only after A
     * commits and fail the wrong gate.
     */
    private function waitForBothBlockers(int $lockClass, int $lockObj, int $timeoutMs = 10_000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        $conn = DB::connection('pgsql_migrator');

        while (microtime(true) < $deadline) {
            $advisory = $conn->selectOne(
                'SELECT 1 AS ok FROM pg_locks
                 WHERE locktype = ? AND classid = ? AND objid = ? AND NOT granted
                 LIMIT 1',
                ['advisory', $lockClass, $lockObj],
            );
            $rowWait = $conn->selectOne(
                'SELECT 1 AS ok FROM pg_locks
                 WHERE locktype IN (?, ?) AND NOT granted
                 LIMIT 1',
                ['transactionid', 'tuple'],
            );

            if ($advisory !== null && $rowWait !== null) {
                return;
            }

            usleep(5_000);
        }

        $this->fail('second resubmit worker never blocked on the first worker row lock');
    }

    private function installResubmitBarrier(int $lockClass, int $lockObj): void
    {
        $migrator = DB::connection('pgsql_migrator');
        $migrator->unprepared(sprintf(<<<'SQL'
            CREATE OR REPLACE FUNCTION w3_r4_resubmit_barrier()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $func$
            BEGIN
                IF OLD.status_approval = '%s' AND NEW.status_approval = '%s' THEN
                    PERFORM pg_advisory_lock(%d, %d);
                    PERFORM pg_advisory_unlock(%d, %d);
                END IF;
                RETURN NEW;
            END;
            $func$;

            DROP TRIGGER IF EXISTS trg_w3_r4_resubmit_barrier ON candidate;
            CREATE TRIGGER trg_w3_r4_resubmit_barrier
                AFTER UPDATE ON candidate
                FOR EACH ROW
                EXECUTE FUNCTION w3_r4_resubmit_barrier();
            SQL,
            CandidateApprovalStatus::Ditolak->value,
            CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            $lockClass,
            $lockObj,
            $lockClass,
            $lockObj,
        ));
    }

    private function dropResubmitBarrier(): void
    {
        $migrator = DB::connection('pgsql_migrator');
        $migrator->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_w3_r4_resubmit_barrier ON candidate;
            DROP FUNCTION IF EXISTS w3_r4_resubmit_barrier();
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

        $this->fail('resubmit worker never blocked on advisory barrier lock');
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
