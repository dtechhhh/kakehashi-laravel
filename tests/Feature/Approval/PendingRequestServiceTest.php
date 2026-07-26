<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Auth\Rbac;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PendingRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private PendingRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(PendingRequestService::class);
    }

    public function test_submit_creates_single_pending_with_audit(): void
    {
        $maker = $this->maker();

        $request = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 11,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
            reasonMaker: 'kontainer batch Juli',
        );

        $this->assertSame(PendingStatus::PENDING, $request->status);
        $this->assertSame(PendingType::IC_CREATE, $request->type);
        $this->assertSame($maker->getKey(), $request->requested_by);
        $this->assertNull($request->checker_id);
        $this->assertNull($request->decided_at);

        $this->assertDatabaseHas('pending_request', [
            'id' => $request->getKey(),
            'status' => PendingStatus::PENDING->value,
            'target_type' => 'interview_container',
            'target_id' => 11,
        ]);

        $audit = AuditLog::query()->where('action_type', ActionType::IC_SUBMITTED->value)->sole();
        $this->assertSame($maker->getKey(), $audit->actor_id);
        $this->assertSame(Rbac::ASSISTANT_MANAGER, $audit->actor_role_snapshot);
        $this->assertSame('interview_container', $audit->entity_type);
        $this->assertSame(11, $audit->entity_id);
        $this->assertSame($request->getKey(), $audit->detail['pending_request_id']);
        $this->assertSame('IC_CREATE', $audit->detail['pending_type']);
    }

    public function test_submit_rejects_second_active_pending_with_conflict(): void
    {
        $maker = $this->maker();

        $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 12,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        try {
            $this->service->submit(
                type: PendingType::IC_CREATE,
                targetType: 'interview_container',
                targetId: 12,
                requestedBy: $maker->getKey(),
                auditAction: ActionType::IC_SUBMITTED,
            );
            $this->fail('duplicate active pending must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame('APV_DUPLICATE', $e->getMessage());
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, PendingRequest::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_submit_requires_payload_snapshot_for_snapshot_types(): void
    {
        $maker = $this->maker();

        try {
            $this->service->submit(
                type: PendingType::PLACEMENT_BATCH,
                targetType: 'placement_container',
                targetId: 5,
                requestedBy: $maker->getKey(),
                auditAction: ActionType::PC_SUBMITTED,
            );
            $this->fail('payload snapshot must be required');
        } catch (ValidationException $e) {
            $this->assertSame(['payload' => ['APV_PAYLOAD']], $e->errors());
        }

        $this->assertSame(0, PendingRequest::query()->count());

        $ok = $this->service->submit(
            type: PendingType::PLACEMENT_BATCH,
            targetType: 'placement_container',
            targetId: 5,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::PC_SUBMITTED,
            payload: ['candidate_ids' => [1, 2, 3]],
        );

        $this->assertSame(['candidate_ids' => [1, 2, 3]], $ok->payload);
    }

    public function test_approve_marks_decision_and_writes_audit(): void
    {
        [$maker, $checker, $request] = $this->pendingFixture();

        $approved = $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            auditAction: ActionType::IC_APPROVED,
            note: '  disetujui  ',
        );

        $this->assertSame(PendingStatus::APPROVED, $approved->status);
        $this->assertSame($checker->getKey(), $approved->checker_id);
        $this->assertSame('disetujui', $approved->note_checker);
        $this->assertNotNull($approved->decided_at);
        $this->assertSame($maker->getKey(), $approved->requested_by);

        $audit = AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->sole();
        $this->assertSame($checker->getKey(), $audit->actor_id);
        $this->assertSame(Rbac::JOB_MANAGER, $audit->actor_role_snapshot);
        $this->assertSame('approved', $audit->detail['decision']);
        $this->assertSame($request->getKey(), $audit->detail['pending_request_id']);
    }

    public function test_reject_requires_non_empty_note(): void
    {
        [, $checker, $request] = $this->pendingFixture();

        foreach (['', '   ', "\n\t "] as $blank) {
            try {
                $this->service->reject(
                    requestId: $request->getKey(),
                    checkerId: $checker->getKey(),
                    note: $blank,
                    auditAction: ActionType::IC_REJECTED,
                );
                $this->fail('blank note must be rejected');
            } catch (ValidationException $e) {
                $this->assertSame(['note_checker' => ['APV_NOTE']], $e->errors());
            }
        }

        $this->assertSame(
            PendingStatus::PENDING,
            $request->fresh()->status,
            'failed reject must leave the request pending'
        );
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IC_REJECTED->value)->count());

        $rejected = $this->service->reject(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            note: 'tanggal wawancara bentrok',
            auditAction: ActionType::IC_REJECTED,
        );

        $this->assertSame(PendingStatus::REJECTED, $rejected->status);
        $this->assertSame('tanggal wawancara bentrok', $rejected->note_checker);
    }

    public function test_maker_cannot_decide_own_request(): void
    {
        [$maker, , $request] = $this->pendingFixture();

        try {
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $maker->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );
            $this->fail('self approval must be rejected server-side');
        } catch (ValidationException $e) {
            $this->assertSame(['checker_id' => ['APV_SELF']], $e->errors());
        }

        try {
            $this->service->reject(
                requestId: $request->getKey(),
                checkerId: $maker->getKey(),
                note: 'batal saja',
                auditAction: ActionType::IC_REJECTED,
            );
            $this->fail('self rejection must be rejected server-side');
        } catch (ValidationException $e) {
            $this->assertSame(['checker_id' => ['APV_SELF']], $e->errors());
        }

        $this->assertSame(PendingStatus::PENDING, $request->fresh()->status);
        $this->assertNull($request->fresh()->checker_id);
        $this->assertSame(1, AuditLog::query()->count(), 'only the submit audit row may exist');
    }

    public function test_second_decision_conflicts_and_keeps_first_outcome(): void
    {
        [, $checker, $request] = $this->pendingFixture();
        $secondChecker = $this->checker();

        $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            auditAction: ActionType::IC_APPROVED,
        );

        try {
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $secondChecker->getKey(),
                auditAction: ActionType::IC_APPROVED,
            );
            $this->fail('double approval must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame('APV_DONE', $e->getMessage());
            $this->assertSame(409, $e->getStatusCode());
        }

        try {
            $this->service->reject(
                requestId: $request->getKey(),
                checkerId: $secondChecker->getKey(),
                note: 'terlambat',
                auditAction: ActionType::IC_REJECTED,
            );
            $this->fail('reject after approval must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame('APV_DONE', $e->getMessage());
        }

        $fresh = $request->fresh();
        $this->assertSame(PendingStatus::APPROVED, $fresh->status);
        $this->assertSame($checker->getKey(), $fresh->checker_id, 'first checker must remain the decider');
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count());
    }

    public function test_failed_audit_rolls_back_the_decision(): void
    {
        [, $checker, $request] = $this->pendingFixture();

        try {
            $this->service->approve(
                requestId: $request->getKey(),
                checkerId: $checker->getKey(),
                auditAction: ActionType::IC_APPROVED,
                auditDetail: ['password' => 'SuperSecret1!'],
            );
            $this->fail('forbidden audit detail must abort the decision');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }

        $fresh = $request->fresh();
        $this->assertSame(PendingStatus::PENDING, $fresh->status);
        $this->assertNull($fresh->checker_id);
        $this->assertNull($fresh->decided_at);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IC_APPROVED->value)->count());
    }

    public function test_audit_detail_excludes_maker_reason_and_checker_note(): void
    {
        $maker = $this->maker();
        $checker = $this->checker();

        $request = $this->service->submit(
            type: PendingType::IC_CLOSE,
            targetType: 'interview_container',
            targetId: 42,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_CLOSE_REQUESTED,
            reasonMaker: 'alasan maker rahasia',
        );

        $this->service->reject(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            note: 'catatan checker rahasia',
            auditAction: ActionType::IC_REJECTED,
        );

        $json = json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('alasan maker rahasia', $json);
        $this->assertStringNotContainsString('catatan checker rahasia', $json);
        // Free text tetap tersimpan di pending_request untuk kebutuhan review.
        $this->assertSame('catatan checker rahasia', $request->fresh()->note_checker);
    }

    public function test_decision_columns_are_not_mass_assignable(): void
    {
        [$maker, $checker, $request] = $this->pendingFixture();

        // Mass assignment tidak boleh menjadi jalan pintas keputusan: kolom
        // keputusan diabaikan diam-diam karena tidak fillable (BR-APV-01/07).
        $smuggled = PendingRequest::query()->create([
            'type' => PendingType::IC_CREATE,
            'target_type' => 'interview_container',
            'target_id' => 99,
            'requested_by' => $maker->getKey(),
            'status' => PendingStatus::PENDING,
            'checker_id' => $maker->getKey(),
            'note_checker' => 'diselundupkan lewat create',
            'decided_at' => now(),
        ]);

        $this->assertNull($smuggled->checker_id);
        $this->assertNull($smuggled->note_checker);
        $this->assertNull($smuggled->decided_at);
        $this->assertDatabaseHas('pending_request', [
            'id' => $smuggled->getKey(),
            'checker_id' => null,
            'note_checker' => null,
            'decided_at' => null,
        ]);

        $request->update([
            'checker_id' => $maker->getKey(),
            'note_checker' => 'diselundupkan lewat update',
            'decided_at' => now(),
        ]);

        $fresh = $request->fresh();
        $this->assertNull($fresh->checker_id);
        $this->assertNull($fresh->note_checker);
        $this->assertNull($fresh->decided_at);
        $this->assertSame(PendingStatus::PENDING, $fresh->status);

        // Jalur sah lewat service tetap menulis kolom keputusan.
        $approved = $this->service->approve(
            requestId: $request->getKey(),
            checkerId: $checker->getKey(),
            auditAction: ActionType::IC_APPROVED,
        );

        $this->assertSame($checker->getKey(), $approved->checker_id);
        $this->assertNotNull($approved->decided_at);
    }

    /**
     * @return array{User, User, PendingRequest}
     */
    private function pendingFixture(): array
    {
        $maker = $this->maker();
        $checker = $this->checker();

        $request = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 21,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        return [$maker, $checker, $request];
    }

    private function maker(): User
    {
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);

        return $maker;
    }

    private function checker(): User
    {
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);

        return $checker;
    }
}
