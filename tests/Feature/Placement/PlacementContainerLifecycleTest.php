<?php

namespace Tests\Feature\Placement;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Placement\Services\PlacementContainerService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementContainerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private PlacementContainerService $service;

    private User $maker;

    private User $checker;

    private int $companyId;

    private int $companyIdAlt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(PlacementContainerService::class);
        $this->maker = User::factory()->active()->create();
        $this->maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->checker = User::factory()->active()->create();
        $this->checker->assignRole(Rbac::JOB_MANAGER);

        $this->companyId = $this->seedCompany('W5 Placement Co');
        $this->companyIdAlt = $this->seedCompany('W5 Placement Alt Co');

        $this->actingAs($this->maker);
    }

    public function test_create_starts_draft_without_code_or_pending(): void
    {
        $row = $this->service->createDraft($this->maker, $this->payload());

        $this->assertSame('Draft', $row->status);
        $this->assertNull($row->kode_kontainer);
        $this->assertSame(0, (int) $row->version);
        $this->assertDatabaseMissing('pending_request', [
            'target_type' => 'placement_container',
            'target_id' => $row->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PC_CREATED->value,
            'entity_type' => 'placement_container',
            'entity_id' => $row->id,
        ]);
    }

    public function test_first_submit_assigns_p_code_and_creates_pending_atomically(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => $draft->version]);

        $this->assertMatchesRegularExpression('/^P-\d{4}-00001$/', $submitted->kode_kontainer);
        $this->assertSame('Menunggu Approval', $submitted->status);
        $this->assertSame(1, (int) $submitted->version);
        $this->assertDatabaseHas('pending_request', [
            'type' => PendingType::PC_CREATE->value,
            'target_type' => 'placement_container',
            'target_id' => $draft->id,
            'requested_by' => $this->maker->id,
            'status' => PendingStatus::PENDING->value,
        ]);

        $second = $this->service->createDraft($this->maker, $this->payload(['nama' => 'W5 Kedua']));
        $secondSubmitted = $this->service->submit($this->maker, (int) $second->id, ['version' => 0]);
        $this->assertSame('P-'.date('Y').'-00002', $secondSubmitted->kode_kontainer);
    }

    public function test_checker_approval_activates_container_and_reject_returns_to_draft(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = $this->pendingId($draft->id);

        $this->actingAs($this->checker);
        $approved = $this->service->approve($this->checker, $pendingId, ['version' => $submitted->version]);

        $this->assertSame('Aktif', $approved->status);
        $this->assertSame($this->checker->id, (int) $approved->disetujui_oleh);
        $this->assertSame(2, (int) $approved->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
        ]);

        $this->actingAs($this->maker);
        $secondDraft = $this->service->createDraft($this->maker, $this->payload(['nama' => 'W5 Ditolak']));
        $secondSubmitted = $this->service->submit($this->maker, (int) $secondDraft->id, ['version' => 0]);
        $secondPendingId = $this->pendingId($secondDraft->id);

        $this->actingAs($this->checker);
        $rejected = $this->service->reject($this->checker, $secondPendingId, 'Lengkapi syarat W5', ['version' => $secondSubmitted->version]);

        $this->assertSame('Draft', $rejected->status);
        $this->assertNull($rejected->disetujui_oleh);
        $this->assertSame(2, (int) $rejected->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $secondPendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Lengkapi syarat W5',
        ]);
    }

    public function test_maker_cannot_approve_and_checker_reject_requires_note(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = $this->pendingId($draft->id);

        $this->maker->givePermissionTo('placement.review');
        try {
            $this->service->approve($this->maker, $pendingId, ['version' => $submitted->version]);
            $this->fail('Maker must not approve their own request.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }

        $this->actingAs($this->checker);
        try {
            $this->service->reject($this->checker, $pendingId, '   ', ['version' => $submitted->version]);
            $this->fail('Rejecting without a note must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }
    }

    public function test_rejected_draft_requires_a_change_before_resubmit_and_keeps_code(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = $this->pendingId($draft->id);

        $this->actingAs($this->checker);
        $rejected = $this->service->reject($this->checker, $pendingId, 'Perbaiki data', ['version' => 1]);

        $this->actingAs($this->maker);
        try {
            $this->service->submit($this->maker, (int) $rejected->id, ['version' => $rejected->version]);
            $this->fail('Unchanged rejected draft must not resubmit.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NO_CHANGE'], $exception->errors()['container'] ?? []);
        }

        $changed = $this->service->updateDraft($this->maker, (int) $rejected->id, [
            'nama' => 'W5 Revisi',
            'version' => $rejected->version,
        ]);
        $resubmitted = $this->service->submit($this->maker, (int) $changed->id, ['version' => $changed->version]);

        $this->assertSame($submitted->kode_kontainer, $resubmitted->kode_kontainer);
        $this->assertSame('Menunggu Approval', $resubmitted->status);
    }

    public function test_cancel_is_only_pre_active(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $cancelledDraft = $this->service->cancel($this->maker, (int) $draft->id, ['version' => 0]);

        $this->assertSame('Dibatalkan', $cancelledDraft->status);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PC_CANCELLED->value,
            'entity_id' => $draft->id,
        ]);

        $pending = $this->service->createDraft($this->maker, $this->payload(['nama' => 'W5 Pending']));
        $this->service->submit($this->maker, (int) $pending->id, ['version' => 0]);
        $pendingId = $this->pendingId($pending->id);
        $cancelledPending = $this->service->cancel($this->maker, (int) $pending->id, ['version' => 1]);

        $this->assertSame('Dibatalkan', $cancelledPending->status);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'checker_id' => null,
            'note_checker' => 'IC_CANCELLED_BY_MAKER',
        ]);

        $active = $this->activeContainer();
        try {
            $this->service->cancel($this->maker, (int) $active->id, ['version' => $active->version]);
            $this->fail('An active container must not be cancelled.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_NOT_CANCELLABLE'], $exception->errors()['status'] ?? []);
        }

        $this->assertDatabaseHas('placement_container', [
            'id' => $active->id,
            'status' => 'Aktif',
        ]);
    }

    public function test_perusahaan_is_immutable_after_creation(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());

        try {
            $this->service->updateDraft($this->maker, (int) $draft->id, [
                'perusahaan_id' => $this->companyIdAlt,
                'version' => 0,
            ]);
            $this->fail('Changing perusahaan must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PC_COMPANY_IMMUTABLE'], $exception->errors()['perusahaan_id'] ?? []);
        }

        $this->assertDatabaseHas('placement_container', [
            'id' => $draft->id,
            'perusahaan_id' => $this->companyId,
        ]);
    }

    public function test_stale_version_conflicts_without_mutation(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());

        try {
            $this->service->submit($this->maker, (int) $draft->id, ['version' => 5]);
            $this->fail('A stale version must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('placement_container', [
            'id' => $draft->id,
            'status' => 'Draft',
            'version' => 0,
        ]);
    }

    private function activeContainer(): object
    {
        $draft = $this->service->createDraft($this->maker, $this->payload(['nama' => 'W5 Aktif']));
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $this->actingAs($this->checker);
        $approved = $this->service->approve($this->checker, $this->pendingId($draft->id), ['version' => 1]);
        $this->actingAs($this->maker);

        return $approved;
    }

    private function pendingId(int $containerId): int
    {
        return (int) DB::table('pending_request')
            ->where('type', PendingType::PC_CREATE->value)
            ->where('target_type', 'placement_container')
            ->where('target_id', $containerId)
            ->where('status', PendingStatus::PENDING->value)
            ->value('id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nama' => 'W5 Kontainer Penempatan',
            'perusahaan_id' => $this->companyId,
        ], $overrides);
    }

    private function seedCompany(string $namaJa): int
    {
        return (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => $namaJa,
            'nama_romaji' => $namaJa,
            'nama_id' => $namaJa,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
