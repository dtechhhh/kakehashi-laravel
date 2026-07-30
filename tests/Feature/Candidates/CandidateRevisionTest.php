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
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\Candidates\Services\CandidateSubmitService;
use RuntimeException;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * W3-T5 / FIX1 — one active revision; atomic merge; fingerprint + parent_version gates.
 */
class CandidateRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_revision_is_draft_without_nik_and_main_stays_approved(): void
    {
        [$staff, , $mainId, , , $nik, $mainVersion, $educationLevel] = $this->approvedMainFixture();
        $this->actingAs($staff);

        $mainBefore = DB::table('candidate')->where('id', $mainId)->first();

        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );

        $this->assertSame(CandidateApprovalStatus::Draft->value, $revision->status_approval);
        $this->assertNull($revision->nomor_induk);
        $this->assertSame($mainId, (int) $revision->parent_candidate_id);
        $this->assertSame(0, (int) $revision->version);

        $this->assertDatabaseHas('candidate_education', [
            'candidate_id' => $revision->id,
            'nama_institusi' => 'SMA Asal',
            'tingkat_pendidikan_id' => $educationLevel,
        ]);
        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $revision->id,
            'object_key' => 'candidates/main/photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $mainAfter = DB::table('candidate')->where('id', $mainId)->first();
        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $mainAfter->status_approval);
        $this->assertSame($nik, $mainAfter->nomor_induk);
        $this->assertSame($mainBefore->status_ketersediaan, $mainAfter->status_ketersediaan);
        $this->assertSame((int) $mainBefore->version, (int) $mainAfter->version);
        $this->assertTrue(app(CandidateDraftService::class)->isOperational($mainAfter));
    }

    public function test_second_active_revision_is_blocked_with_409(): void
    {
        [$staff, , $mainId, , , , $mainVersion] = $this->approvedMainFixture();
        $this->actingAs($staff);
        $service = app(CandidateRevisionService::class);

        $service->createRevision($staff, $mainId, ['version' => $mainVersion]);

        try {
            $service->createRevision($staff, $mainId, ['version' => $mainVersion]);
            $this->fail('second open revision must conflict 409');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CANDIDATE_REVISION_ACTIVE', $exception->getMessage());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(
            1,
            DB::table('candidate')->where('parent_candidate_id', $mainId)->count(),
        );
    }

    public function test_submit_without_change_from_main_is_rejected_and_submit_with_change_stores_payload(): void
    {
        [$staff, , $mainId, , , $nik, $mainVersion] = $this->approvedMainFixture();
        $this->actingAs($staff);
        $revisions = app(CandidateRevisionService::class);
        $drafts = app(CandidateDraftService::class);

        $revision = $revisions->createRevision($staff, $mainId, ['version' => $mainVersion]);

        try {
            $revisions->submitRevision($staff, (int) $revision->id, ['version' => 0]);
            $this->fail('unchanged vs main must not submit');
        } catch (ValidationException $exception) {
            $this->assertSame(['revision' => ['CANDIDATE_NO_CHANGE']], $exception->errors());
        }

        Queue::fake();
        $updated = $drafts->updateDraft($staff, (int) $revision->id, [
            'version' => 0,
            'nama_alphabet' => 'Revision Name',
            'phone' => '08111111111',
        ]);

        $submitted = $revisions->submitRevision($staff, (int) $updated->id, [
            'version' => (int) $updated->version,
        ]);

        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $submitted->status_approval);

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $payload = $pending->payload;
        $this->assertIsArray($payload);
        $this->assertSame($mainId, (int) $payload['parent_candidate_id']);
        $this->assertSame($mainVersion, (int) $payload['parent_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payload['aggregate_fingerprint']);
        $this->assertSame(
            $revisions->aggregateFingerprint((int) $revision->id),
            $payload['aggregate_fingerprint'],
        );

        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'version' => $mainVersion,
        ]);
    }

    public function test_resubmit_ditolak_without_edit_is_rejected_and_after_real_change_succeeds(): void
    {
        [$staff, $approver, $mainId, , , $nik, $mainVersion] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            $pendingId,
            'tolak dulu',
            ['version' => $revisionVersion],
        );

        $rejected = DB::table('candidate')->where('id', $revisionId)->first();
        $this->assertSame('Ditolak', $rejected->status_approval);

        $this->actingAs($staff);
        try {
            app(CandidateRevisionService::class)->submitRevision(
                $staff,
                $revisionId,
                ['version' => (int) $rejected->version],
            );
            $this->fail('resubmit without edit after Ditolak must fail');
        } catch (ValidationException $exception) {
            $this->assertSame(['revision' => ['CANDIDATE_NO_CHANGE']], $exception->errors());
        }

        $this->assertSame(
            0,
            PendingRequest::query()
                ->where('type', PendingType::CANDIDATE_REVISION)
                ->where('target_id', $revisionId)
                ->where('status', PendingStatus::PENDING->value)
                ->count(),
        );

        $edited = app(CandidateDraftService::class)->updateDraft($staff, $revisionId, [
            'version' => (int) $rejected->version,
            'nama_alphabet' => 'Second Pass Name',
        ]);

        $resubmitted = app(CandidateRevisionService::class)->submitRevision(
            $staff,
            $revisionId,
            ['version' => (int) $edited->version],
        );

        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $resubmitted->status_approval);
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'nama_alphabet' => 'Approved Main',
            'version' => $mainVersion,
        ]);

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revisionId)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();
        $this->assertSame(
            app(CandidateRevisionService::class)->aggregateFingerprint($revisionId),
            $pending->payload['aggregate_fingerprint'],
        );
        $this->assertNotSame(
            PendingRequest::query()
                ->where('type', PendingType::CANDIDATE_REVISION)
                ->where('target_id', $revisionId)
                ->where('status', PendingStatus::REJECTED->value)
                ->orderByDesc('id')
                ->value('payload')['aggregate_fingerprint'] ?? null,
            $pending->payload['aggregate_fingerprint'],
        );
    }

    public function test_approve_merges_mutable_children_and_photo_without_changing_nik_or_availability(): void
    {
        [$staff, $approver, $mainId, , , $nik, $mainVersion, $educationLevel] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        DB::table('candidate')->where('id', $mainId)->update([
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
        ]);
        // Bump version after availability flip so payload parent_version is stale unless we
        // re-align payload — simulate in-use without changing submit-time parent_version by
        // only flipping availability while keeping the same version column value used at submit.
        // Restore version so parent_version still matches (availability is not versioned here).
        DB::table('candidate')->where('id', $mainId)->update([
            'version' => $mainVersion,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
        ]);

        $this->actingAs($approver);
        Queue::fake();

        $merged = app(CandidateApprovalService::class)->approve(
            $approver,
            $pendingId,
            ['version' => $revisionVersion],
        );

        $this->assertSame($mainId, (int) $merged->id);
        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $merged->status_approval);
        $this->assertSame($nik, $merged->nomor_induk);
        $this->assertSame(CandidateAvailability::SedangDipakai->value, $merged->status_ketersediaan);
        $this->assertSame('Revision Name', $merged->nama_alphabet);
        $this->assertSame($mainVersion + 1, (int) $merged->version);
        $this->assertTrue(app(CandidateDraftService::class)->isOperational($merged));

        $this->assertDatabaseHas('candidate', [
            'id' => $revisionId,
            'status_approval' => 'Diterapkan',
            'nomor_induk' => null,
        ]);
        $this->assertDatabaseHas('candidate_education', [
            'candidate_id' => $mainId,
            'nama_institusi' => 'SMA Revisi',
            'tingkat_pendidikan_id' => $educationLevel,
        ]);
        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $mainId,
            'object_key' => 'candidates/revision/photo.jpg',
            'mime_type' => 'image/png',
        ]);
        $this->assertSame(
            0,
            DB::table('candidate_photo')
                ->where('candidate_id', $mainId)
                ->where('object_key', 'candidates/main/photo.jpg')
                ->count(),
        );

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_APPROVED->value,
            'notifiable_id' => $staff->getKey(),
        ]);
    }

    public function test_stale_parent_version_conflicts_without_side_effects(): void
    {
        [, $approver, $mainId, , , $nik, $mainVersion] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $mainName = DB::table('candidate')->where('id', $mainId)->value('nama_alphabet');
        $mainPhoto = DB::table('candidate_photo')->where('candidate_id', $mainId)->value('object_key');
        $eduBefore = DB::table('candidate_education')->where('candidate_id', $mainId)->pluck('nama_institusi')->all();
        $auditBefore = AuditLog::query()->count();
        $notifBefore = DB::table('notifications')->count();

        // Main changed after submit → parent_version in payload is stale.
        DB::table('candidate')->where('id', $mainId)->update([
            'version' => $mainVersion + 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($approver);
        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $revisionVersion],
            );
            $this->fail('stale parent_version must 409');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $revisionId,
            'status_approval' => 'Menunggu Tinjauan-REVISI',
            'version' => $revisionVersion,
            'nomor_induk' => null,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'nama_alphabet' => $mainName,
            'version' => $mainVersion + 1,
        ]);
        $this->assertSame(
            $eduBefore,
            DB::table('candidate_education')->where('candidate_id', $mainId)->pluck('nama_institusi')->all(),
        );
        $this->assertSame(
            $mainPhoto,
            DB::table('candidate_photo')->where('candidate_id', $mainId)->value('object_key'),
        );
        $this->assertSame($auditBefore, AuditLog::query()->count());
        $this->assertSame($notifBefore, DB::table('notifications')->count());
    }

    public function test_in_app_failure_after_merge_rolls_back_main_children_photo_revision_pending_and_audit(): void
    {
        [$staff, $approver, $mainId, , , $nik, $mainVersion] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $mainName = DB::table('candidate')->where('id', $mainId)->value('nama_alphabet');
        $mainPhoto = DB::table('candidate_photo')->where('candidate_id', $mainId)->value('object_key');
        $eduBefore = DB::table('candidate_education')->where('candidate_id', $mainId)->pluck('nama_institusi')->all();
        $notifBefore = DB::table('notifications')->count();
        $revisionApproveAuditsBefore = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_APPROVED)
            ->where('detail->pending_type', PendingType::CANDIDATE_REVISION->value)
            ->count();

        $this->actingAs($approver);
        Queue::fake();

        Notification::shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('database notification failed after merge'));

        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $revisionVersion],
            );
            $this->fail('in-app failure after merge must abort');
        } catch (RuntimeException $exception) {
            $this->assertSame('database notification failed after merge', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'nama_alphabet' => $mainName,
            'version' => $mainVersion,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $revisionId,
            'status_approval' => 'Menunggu Tinjauan-REVISI',
            'version' => $revisionVersion,
            'nomor_induk' => null,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertSame(
            $eduBefore,
            DB::table('candidate_education')->where('candidate_id', $mainId)->pluck('nama_institusi')->all(),
        );
        $this->assertSame(
            $mainPhoto,
            DB::table('candidate_photo')->where('candidate_id', $mainId)->value('object_key'),
        );
        $this->assertSame(
            $revisionApproveAuditsBefore,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_APPROVED)
                ->where('detail->pending_type', PendingType::CANDIDATE_REVISION->value)
                ->count(),
        );
        // Prior CANDIDATE_NEW approve may already have notified maker; decision notif must not add.
        $this->assertSame($notifBefore, DB::table('notifications')->count());
    }

    public function test_reject_leaves_main_unchanged(): void
    {
        [$staff, $approver, $mainId, , , $nik, $mainVersion] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $mainSnapshot = (array) DB::table('candidate')->where('id', $mainId)->first();

        $this->actingAs($approver);
        $rejected = app(CandidateApprovalService::class)->reject(
            $approver,
            $pendingId,
            'perlu perbaikan data alamat',
            ['version' => $revisionVersion],
        );

        $this->assertSame($revisionId, (int) $rejected->id);
        $this->assertSame(CandidateApprovalStatus::Ditolak->value, $rejected->status_approval);

        $mainAfter = DB::table('candidate')->where('id', $mainId)->first();
        $this->assertSame($mainSnapshot['status_approval'], $mainAfter->status_approval);
        $this->assertSame($nik, $mainAfter->nomor_induk);
        $this->assertSame($mainSnapshot['nama_alphabet'], $mainAfter->nama_alphabet);
        $this->assertSame((int) $mainSnapshot['version'], (int) $mainAfter->version);
        $this->assertSame($mainVersion, (int) $mainAfter->version);

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_REJECTED->value,
            'notifiable_id' => $staff->getKey(),
        ]);
    }

    public function test_maker_cannot_self_approve_revision(): void
    {
        [$staff, , $mainId] = $this->approvedMainFixtureWithPendingRevision();
        [, , $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $staff->givePermissionTo('candidate.review');
        $this->actingAs($staff);

        try {
            app(CandidateApprovalService::class)->approve(
                $staff,
                $pendingId,
                ['version' => $revisionVersion],
            );
            $this->fail('Maker self-approve revision must be denied');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
            'checker_id' => null,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
        ]);
    }

    public function test_anonymized_main_or_revision_cannot_be_processed(): void
    {
        [$staff, $approver, $mainId, , , , $mainVersion] = $this->approvedMainFixture();
        $this->actingAs($staff);

        DB::table('candidate')->where('id', $mainId)->update([
            'pii_anonymized_at' => now(),
        ]);

        try {
            app(CandidateRevisionService::class)->createRevision(
                $staff,
                $mainId,
                ['version' => $mainVersion],
            );
            $this->fail('anonymized main must not create revision');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_REVISABLE']], $exception->errors());
        }

        DB::table('candidate')->where('id', $mainId)->update([
            'pii_anonymized_at' => null,
        ]);

        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );
        app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => 0,
            'nama_alphabet' => 'Anon Path',
        ]);

        DB::table('candidate')->where('id', $revision->id)->update([
            'pii_anonymized_at' => now(),
        ]);

        try {
            app(CandidateRevisionService::class)->submitRevision(
                $staff,
                (int) $revision->id,
                ['version' => 1],
            );
            $this->fail('anonymized revision must not submit');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_SUBMITTABLE']], $exception->errors());
        }

        // Reset and get a waiting revision, then anonymize before approve.
        DB::table('candidate')->where('id', $revision->id)->update([
            'pii_anonymized_at' => null,
        ]);
        $waiting = app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $revision->id,
            ['version' => 1],
        );
        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        DB::table('candidate')->where('id', $revision->id)->update([
            'pii_anonymized_at' => now(),
        ]);

        $this->actingAs($approver);
        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                (int) $pending->getKey(),
                ['version' => (int) $waiting->version],
            );
            $this->fail('anonymized revision must not approve');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_APPROVABLE']], $exception->errors());
        }

        $this->assertDatabaseHas('pending_request', [
            'id' => $pending->getKey(),
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
        ]);
    }

    public function test_stale_revision_version_conflicts_without_decision(): void
    {
        [, $approver, $mainId, , , $nik, $mainVersion] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $this->actingAs($approver);
        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                $pendingId,
                ['version' => $revisionVersion - 1],
            );
            $this->fail('Expected CONFLICT');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $revisionId,
            'status_approval' => 'Menunggu Tinjauan-REVISI',
            'version' => $revisionVersion,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_approval' => 'Disetujui',
            'nomor_induk' => $nik,
            'version' => $mainVersion,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_double_approve_revision_yields_one_success_and_one_conflict(): void
    {
        [, $approverA, $mainId, , , $nik] = $this->approvedMainFixtureWithPendingRevision();
        [, $revisionId, $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $approverB = User::factory()->active()->create();
        $approverB->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($approverA);
        $first = app(CandidateApprovalService::class)->approve(
            $approverA,
            $pendingId,
            ['version' => $revisionVersion],
        );
        $this->assertSame('Disetujui', $first->status_approval);
        $this->assertSame($nik, $first->nomor_induk);

        $this->actingAs($approverB);
        try {
            app(CandidateApprovalService::class)->approve(
                $approverB,
                $pendingId,
                ['version' => $revisionVersion],
            );
            $this->fail('second approve must conflict');
        } catch (ConflictHttpException $exception) {
            $this->assertContains($exception->getMessage(), ['APV_DONE', 'CONFLICT']);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $revisionId,
            'status_approval' => 'Diterapkan',
        ]);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action_type', ActionType::CANDIDATE_APPROVED)
                ->where('detail->pending_type', PendingType::CANDIDATE_REVISION->value)
                ->count(),
        );
    }

    public function test_wrong_role_cannot_approve_revision(): void
    {
        [, , $mainId] = $this->approvedMainFixtureWithPendingRevision();
        [, , $pendingId, $revisionVersion] = $this->lastRevisionContext($mainId);

        $jobManager = User::factory()->active()->create();
        $jobManager->assignRole(Rbac::JOB_MANAGER);
        $this->actingAs($jobManager);

        $this->expectException(AuthorizationException::class);
        app(CandidateApprovalService::class)->approve(
            $jobManager,
            $pendingId,
            ['version' => $revisionVersion],
        );
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: string, 6: int, 7: int}
     */
    private function approvedMainFixture(): array
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
        $educationLevel = DB::table('tingkat_pendidikan')->insertGetId([
            'code' => 'SMA',
            'label_id' => 'SMA',
            'label_ja' => '高校',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Approved Main',
            'tanggal_lahir' => '2000-02-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
            'education' => [
                ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'SMA Asal'],
            ],
        ]);

        DB::table('candidate_photo')->insert([
            'candidate_id' => $created->id,
            'object_key' => 'candidates/main/photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'uploaded_by' => $staff->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => 0],
        );

        $pending = PendingRequest::query()
            ->where('target_type', 'candidate')
            ->where('target_id', $created->id)
            ->where('type', PendingType::CANDIDATE_NEW)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pending->getKey(),
            ['version' => (int) $submitted->version],
        );

        return [
            $staff,
            $approver,
            (int) $created->id,
            (int) $pending->getKey(),
            (int) $submitted->version,
            (string) $approved->nomor_induk,
            (int) $approved->version,
            $educationLevel,
        ];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int, 4: int, 5: string, 6: int, 7: int}
     */
    private function approvedMainFixtureWithPendingRevision(): array
    {
        $fixture = $this->approvedMainFixture();
        [$staff, , $mainId, , , , $mainVersion, $educationLevel] = $fixture;

        $this->actingAs($staff);
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );

        $updated = app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => 0,
            'nama_alphabet' => 'Revision Name',
            'phone' => '08111111111',
            'education' => [
                ['tingkat_pendidikan_id' => $educationLevel, 'nama_institusi' => 'SMA Revisi'],
            ],
        ]);

        // Photo change on revision (metadata only).
        DB::table('candidate_photo')->where('candidate_id', $revision->id)->delete();
        DB::table('candidate_photo')->insert([
            'candidate_id' => $revision->id,
            'object_key' => 'candidates/revision/photo.jpg',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'uploaded_by' => $staff->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $updated->id,
            ['version' => (int) $updated->version],
        );

        return $fixture;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} mainId, revisionId, pendingId, revisionVersion
     */
    private function lastRevisionContext(int $mainId): array
    {
        $revision = DB::table('candidate')
            ->where('parent_candidate_id', $mainId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($revision);

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        return [
            $mainId,
            (int) $revision->id,
            (int) $pending->getKey(),
            (int) $revision->version,
        ];
    }
}
