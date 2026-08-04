<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Jobs\Services\InterviewContainerService;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class InterviewContainerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private InterviewContainerService $service;

    private User $maker;

    private User $checker;

    private int $companyId;

    private int $positionId;

    private int $visaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(InterviewContainerService::class);
        $this->maker = User::factory()->active()->create();
        $this->maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->checker = User::factory()->active()->create();
        $this->checker->assignRole(Rbac::JOB_MANAGER);

        $this->companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'R3 面接会社',
            'nama_romaji' => 'R3 Mensetsu Kaisha',
            'nama_id' => 'Perusahaan W4',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->positionId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W4_ENGINEER',
            'label_id' => 'Teknisi W4',
            'label_ja' => 'W4技術者',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W4_SSW',
            'label_id' => 'Visa W4',
            'label_ja' => 'W4ビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->maker);
    }

    public function test_create_starts_draft_without_code_or_pending(): void
    {
        $row = $this->service->createDraft($this->maker, $this->payload());

        $this->assertSame('Draft', $row->status);
        $this->assertNull($row->kode_kontainer);
        $this->assertSame(0, (int) $row->version);
        $this->assertDatabaseMissing('pending_request', [
            'target_type' => 'interview_container',
            'target_id' => $row->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::IC_CREATED->value,
            'entity_type' => 'interview_container',
            'entity_id' => $row->id,
        ]);
    }

    public function test_first_submit_assigns_w_code_and_creates_pending_atomically(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => $draft->version]);

        $this->assertMatchesRegularExpression('/^W-\d{4}-00001$/', $submitted->kode_kontainer);
        $this->assertSame('Menunggu Approval', $submitted->status);
        $this->assertSame(1, (int) $submitted->version);
        $this->assertDatabaseHas('pending_request', [
            'type' => PendingType::IC_CREATE->value,
            'target_type' => 'interview_container',
            'target_id' => $draft->id,
            'requested_by' => $this->maker->id,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_checker_approval_activates_container_and_reject_returns_to_draft(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('target_id', $draft->id)
            ->where('status', PendingStatus::PENDING->value)
            ->value('id');

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
        $secondDraft = $this->service->createDraft($this->maker, $this->payload(['judul' => 'W4 Ditolak']));
        $secondSubmitted = $this->service->submit($this->maker, (int) $secondDraft->id, ['version' => 0]);
        $secondPendingId = (int) DB::table('pending_request')
            ->where('target_id', $secondDraft->id)
            ->where('status', PendingStatus::PENDING->value)
            ->value('id');
        $this->actingAs($this->checker);
        $rejected = $this->service->reject($this->checker, $secondPendingId, 'Lengkapi syarat W4', ['version' => $secondSubmitted->version]);

        $this->assertSame('Draft', $rejected->status);
        $this->assertNull($rejected->disetujui_oleh);
        $this->assertSame(2, (int) $rejected->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $secondPendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Lengkapi syarat W4',
        ]);
    }

    public function test_maker_cannot_approve_and_checker_cannot_reject_without_note(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')->where('target_id', $draft->id)->value('id');

        $this->maker->givePermissionTo('jobs.review');
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
        $pendingId = (int) DB::table('pending_request')->where('target_id', $draft->id)->value('id');

        $this->actingAs($this->checker);
        $rejected = $this->service->reject($this->checker, $pendingId, 'Perbaiki data', ['version' => 1]);

        $this->actingAs($this->maker);
        try {
            $this->service->submit($this->maker, (int) $rejected->id, ['version' => $rejected->version]);
            $this->fail('Unchanged rejected draft must not resubmit.');
        } catch (ValidationException $exception) {
            $this->assertSame(['IC_NO_CHANGE'], $exception->errors()['container'] ?? []);
        }

        $changed = $this->service->updateDraft($this->maker, (int) $rejected->id, [
            'version' => $rejected->version,
            'judul' => 'W4 Diubah',
        ]);
        $resubmitted = $this->service->submit($this->maker, (int) $changed->id, ['version' => $changed->version]);

        $this->assertSame('Menunggu Approval', $resubmitted->status);
        $this->assertSame($submitted->kode_kontainer, $resubmitted->kode_kontainer);
        $this->assertSame(1, DB::table('pending_request')
            ->where('target_id', $draft->id)
            ->where('status', PendingStatus::PENDING->value)
            ->count());
    }

    public function test_cancel_draft_and_pending_are_terminal_and_active_cannot_cancel(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $cancelled = $this->service->cancel($this->maker, (int) $draft->id, ['version' => 0]);

        $this->assertSame('Dibatalkan', $cancelled->status);
        $this->assertSame(1, (int) $cancelled->version);

        $waiting = $this->service->createDraft($this->maker, $this->payload(['judul' => 'W4 Pending Cancel']));
        $submitted = $this->service->submit($this->maker, (int) $waiting->id, ['version' => 0]);
        $cancelledWaiting = $this->service->cancel($this->maker, (int) $waiting->id, ['version' => $submitted->version]);
        $this->assertSame('Dibatalkan', $cancelledWaiting->status);
        $this->assertDatabaseHas('pending_request', [
            'target_id' => $waiting->id,
            'status' => PendingStatus::REJECTED->value,
            'checker_id' => null,
            'note_checker' => PendingRequestService::MAKER_CANCELLED_NOTE,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::IC_CANCELLED->value,
            'entity_type' => 'interview_container',
            'entity_id' => $waiting->id,
            'actor_id' => $this->maker->id,
        ]);

        $activeDraft = $this->service->createDraft($this->maker, $this->payload(['judul' => 'W4 Active']));
        $activeSubmitted = $this->service->submit($this->maker, (int) $activeDraft->id, ['version' => 0]);
        $activePendingId = (int) DB::table('pending_request')->where('target_id', $activeDraft->id)->value('id');
        $this->actingAs($this->checker);
        $active = $this->service->approve($this->checker, $activePendingId, ['version' => $activeSubmitted->version]);

        $this->actingAs($this->maker);
        try {
            $this->service->cancel($this->maker, (int) $active->id, ['version' => $active->version]);
            $this->fail('Active container must not be cancellable.');
        } catch (ValidationException $exception) {
            $this->assertSame(['IC_NOT_CANCELLABLE'], $exception->errors()['status'] ?? []);
        }
    }

    public function test_stale_versions_and_duplicate_pending_are_rejected(): void
    {
        $draft = $this->service->createDraft($this->maker, $this->payload());
        $updated = $this->service->updateDraft($this->maker, (int) $draft->id, [
            'version' => 0,
            'judul' => 'W4 Baru',
        ]);

        try {
            $this->service->updateDraft($this->maker, (int) $draft->id, [
                'version' => 0,
                'judul' => 'W4 Stale',
            ]);
            $this->fail('Stale update must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $submitted = $this->service->submit($this->maker, (int) $updated->id, ['version' => $updated->version]);
        try {
            $this->service->submit($this->maker, (int) $updated->id, ['version' => $updated->version]);
            $this->fail('Second submit from pending must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['IC_INVALID_TRANSITION'], $exception->errors()['status'] ?? []);
        }

        $this->assertSame('Menunggu Approval', $submitted->status);
        $this->assertSame(1, DB::table('pending_request')->where('target_id', $draft->id)->where('status', 'pending')->count());
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'judul' => 'W4 Interview Container',
            'perusahaan_id' => $this->companyId,
            'posisi_pekerjaan_id' => $this->positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $this->visaId,
            'tanggal_wawancara' => '2026-09-01',
            'target_peserta_diterima' => 3,
            'deskripsi' => 'Synthetic W4 container',
            'syarat' => 'Japanese N3',
        ], $overrides);
    }
}
