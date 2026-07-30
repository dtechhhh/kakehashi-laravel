<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use RuntimeException;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * W3-T4 — Candidate approval via pending foundation; Maker cannot self-approve.
 */
class CandidateApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_sets_disetujui_pending_and_notifies_maker(): void
    {
        [$staff, $approver, $candidateId, $pendingId, $version, $nik] = $this->submittedFixture();

        Queue::fake();
        $this->actingAs($approver);

        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            $pendingId,
            ['version' => $version],
        );

        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $approved->status_approval);
        $this->assertSame($approver->getKey(), (int) $approved->approved_by);
        $this->assertSame($nik, $approved->nomor_induk);
        $this->assertNull($approved->catatan_penolakan_terakhir);
        $this->assertSame($version + 1, (int) $approved->version);
        $this->assertSame(CandidateAvailability::Tersedia->value, $approved->status_ketersediaan);
        $this->assertTrue(app(CandidateDraftService::class)->isOperational($approved));

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Disetujui',
            'approved_by' => $approver->getKey(),
            'nomor_induk' => $nik,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version + 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
            'checker_id' => $approver->getKey(),
        ]);

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->sole();
        $this->assertSame($approver->getKey(), $audit->actor_id);
        $this->assertSame($candidateId, (int) $audit->entity_id);
        $this->assertSame($pendingId, (int) $audit->detail['pending_request_id']);
        $this->assertSame('approved', $audit->detail['decision']);

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_APPROVED->value,
            'notifiable_id' => $staff->getKey(),
        ]);
    }

    public function test_reject_requires_note_and_sets_ditolak(): void
    {
        [$staff, $approver, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($approver);
        $service = app(CandidateApprovalService::class);

        foreach (['', '   ', "\n\t "] as $blank) {
            try {
                $service->reject($approver, $pendingId, $blank, ['version' => $version]);
                $this->fail('blank reject note must fail');
            } catch (ValidationException $exception) {
                $this->assertSame(['note_checker' => ['APV_NOTE']], $exception->errors());
            }
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'version' => $version,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_REJECTED)->count());

        Queue::fake();
        $rejected = $service->reject(
            $approver,
            $pendingId,
            '  data identitas kurang  ',
            ['version' => $version],
        );

        $this->assertSame(CandidateApprovalStatus::Ditolak->value, $rejected->status_approval);
        $this->assertSame('data identitas kurang', $rejected->catatan_penolakan_terakhir);
        $this->assertNull($rejected->approved_by);
        $this->assertSame($version + 1, (int) $rejected->version);
        $this->assertSame(CandidateAvailability::Tersedia->value, $rejected->status_ketersediaan);
        $this->assertFalse(app(CandidateDraftService::class)->isOperational($rejected));

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Ditolak',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version + 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'data identitas kurang',
            'checker_id' => $approver->getKey(),
        ]);

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_REJECTED)->sole();
        $this->assertSame($approver->getKey(), $audit->actor_id);
        $this->assertSame('rejected', $audit->detail['decision']);
        $this->assertSame($candidateId, (int) $audit->detail['candidate_id']);
        $this->assertSame('data identitas kurang', $audit->detail['reason']);

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_REJECTED->value,
            'notifiable_id' => $staff->getKey(),
        ]);
    }

    public function test_in_app_failure_after_candidate_update_rolls_back_decision(): void
    {
        [, $approver, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($approver);

        $queue = Queue::fake();

        Notification::shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('database notification failed'));

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $version],
            );
            $this->fail('in-app failure must abort after candidate update');
        } catch (RuntimeException $exception) {
            $this->assertSame('database notification failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'approved_by' => null,
            'version' => $version,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_REJECTED)->count());
        // Submit may have notified Approvers; decision in-app must not land.
        $this->assertSame(
            0,
            DB::table('notifications')
                ->whereIn('type', [
                    ActionType::CANDIDATE_APPROVED->value,
                    ActionType::CANDIDATE_REJECTED->value,
                ])
                ->count(),
        );
        $queue->assertNothingPushed();
    }

    public function test_anonymized_candidate_cannot_be_decided(): void
    {
        [, $approver, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($approver);

        DB::table('candidate')->where('id', $candidateId)->update([
            'pii_anonymized_at' => now(),
        ]);

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $version],
            );
            $this->fail('anonymized candidate must not be approved');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_APPROVABLE']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'approved_by' => null,
            'version' => $version,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
    }

    public function test_candidate_revision_pending_is_out_of_scope_without_touching_main(): void
    {
        [, $approver, $candidateId, $pendingId, $version, $nik] = $this->submittedFixture();
        $this->actingAs($approver);

        $main = app(CandidateApprovalService::class)->approve(
            $approver,
            $pendingId,
            ['version' => $version],
        );
        $this->assertSame('Disetujui', $main->status_approval);
        $mainVersion = (int) $main->version;

        $revisionPending = app(PendingRequestService::class)->submit(
            type: PendingType::CANDIDATE_REVISION,
            targetType: 'candidate',
            targetId: $candidateId,
            requestedBy: (int) $main->created_by,
            auditAction: ActionType::CANDIDATE_REVISION_SUBMITTED,
        );

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $revisionPending->getKey(),
                ['version' => $mainVersion],
            );
            $this->fail('CANDIDATE_REVISION must be out of scope for W3-T4');
        } catch (ValidationException $exception) {
            $this->assertSame(['type' => ['CANDIDATE_REVISION_OUT_OF_SCOPE']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'approved_by' => $approver->getKey(),
            'version' => $mainVersion,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $revisionPending->getKey(),
            'type' => PendingType::CANDIDATE_REVISION->value,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_APPROVED)
                ->where('detail->pending_type', PendingType::CANDIDATE_REVISION->value)
                ->count(),
        );
    }

    public function test_maker_cannot_self_approve(): void
    {
        [$staff, , $candidateId, $pendingId, $version] = $this->submittedFixture();

        // Give Maker the checker permission so failure is SoD, not missing role.
        $staff->givePermissionTo('candidate.review');
        $this->actingAs($staff);

        try {
            app(CandidateApprovalService::class)->approve(
                $staff,
                $pendingId,
                ['version' => $version],
            );
            $this->fail('Maker self-approve must be denied');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'approved_by' => null,
            'version' => $version,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
    }

    public function test_wrong_role_cannot_approve(): void
    {
        [, , $candidateId, $pendingId, $version] = $this->submittedFixture();
        $jobManager = User::factory()->active()->create();
        $jobManager->assignRole(Rbac::JOB_MANAGER);
        $this->actingAs($jobManager);

        $this->expectException(AuthorizationException::class);
        app(CandidateApprovalService::class)->approve(
            $jobManager,
            $pendingId,
            ['version' => $version],
        );
    }

    public function test_staff_without_review_cannot_reject(): void
    {
        [$staff, , , $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);
        app(CandidateApprovalService::class)->reject(
            $staff,
            $pendingId,
            'coba tolak',
            ['version' => $version],
        );
    }

    public function test_stale_version_returns_conflict_without_decision(): void
    {
        [, $approver, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($approver);

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $version - 1],
            );
            $this->fail('Expected CONFLICT');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'version' => $version,
            'approved_by' => null,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
    }

    public function test_double_approve_yields_one_success_and_one_conflict(): void
    {
        [, $approverA, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $approverB = User::factory()->active()->create();
        $approverB->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($approverA);
        $first = app(CandidateApprovalService::class)->approve(
            $approverA,
            $pendingId,
            ['version' => $version],
        );
        $this->assertSame('Disetujui', $first->status_approval);

        $this->actingAs($approverB);
        try {
            app(CandidateApprovalService::class)->approve(
                $approverB,
                $pendingId,
                ['version' => $version],
            );
            $this->fail('second approve must conflict');
        } catch (ConflictHttpException $exception) {
            $this->assertContains($exception->getMessage(), ['APV_DONE', 'CONFLICT']);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Disetujui',
            'approved_by' => $approverA->getKey(),
            'version' => $version + 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
            'checker_id' => $approverA->getKey(),
        ]);
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
    }

    public function test_audit_failure_rolls_back_pending_and_candidate(): void
    {
        [, $approver, $candidateId, $pendingId, $version] = $this->submittedFixture();
        $this->actingAs($approver);

        AuditLog::creating(function ($model): void {
            if ($model->action_type === ActionType::CANDIDATE_APPROVED) {
                throw new RuntimeException('approve audit failed');
            }
        });

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $version],
            );
            $this->fail('Expected audit failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('approve audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'approved_by' => null,
            'version' => $version,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_APPROVED)->count());
    }

    public function test_version_required(): void
    {
        [, $approver, , $pendingId] = $this->submittedFixture();
        $this->actingAs($approver);

        try {
            app(CandidateApprovalService::class)->approve($approver, $pendingId, []);
            $this->fail('version required');
        } catch (ValidationException $exception) {
            $this->assertSame(['version' => ['CANDIDATE_VERSION_REQUIRED']], $exception->errors());
        }
    }

    public function test_non_candidate_pending_type_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        $request = app(PendingRequestService::class)->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 99,
            requestedBy: $maker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $this->actingAs($approver);
        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $request->getKey(),
                ['version' => 0],
            );
            $this->fail('wrong pending type');
        } catch (ValidationException $exception) {
            $this->assertSame(['type' => ['CANDIDATE_PENDING_TYPE']], $exception->errors());
        }
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: string}
     */
    private function submittedFixture(): array
    {
        $this->seed(RolePermissionSeeder::class);

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
            'nama_alphabet' => 'Approval Target',
            'tanggal_lahir' => '2000-02-02',
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

        return [
            $staff,
            $approver,
            (int) $created->id,
            (int) $pending->getKey(),
            (int) $submitted->version,
            (string) $submitted->nomor_induk,
        ];
    }
}
