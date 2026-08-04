<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Modules\Jobs\Services\GuestLinkService;
use Modules\Jobs\Services\InterviewContainerService;
use Modules\Jobs\Services\InterviewParticipationService;
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

    private GuestLinkService $guestLinks;

    private User $maker;

    private User $checker;

    private int $companyId;

    private int $positionId;

    private int $visaId;

    private int $countryId;

    private int $candidateSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(InterviewContainerService::class);
        $this->guestLinks = app(GuestLinkService::class);
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
        $this->countryId = $this->seedCountry();

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

    public function test_close_request_creates_pending_overlay_and_keeps_active_container(): void
    {
        $active = $this->activeContainer();
        $requester = User::factory()->active()->create();
        $requester->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->actingAs($requester);

        $requested = $this->service->requestClose(
            $requester,
            (int) $active->id,
            'Jadwal wawancara selesai',
            ['version' => $active->version],
        );

        $pending = DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->first();

        $this->assertSame('Aktif', $requested->status);
        $this->assertSame((int) $active->version, (int) $requested->version);
        $this->assertNotNull($pending);
        $this->assertSame(PendingStatus::PENDING->value, $pending->status);
        $this->assertSame($requester->id, (int) $pending->requested_by);
        $this->assertSame('Jadwal wawancara selesai', $pending->reason_maker);
        $payload = is_string($pending->payload)
            ? json_decode($pending->payload, true, 512, JSON_THROW_ON_ERROR)
            : (array) $pending->payload;
        $this->assertSame((int) $active->id, $payload['snapshot']['interview_container_id']);
        $this->assertSame('Aktif', $payload['snapshot']['status']);
        $this->assertSame((int) $active->version, $payload['snapshot']['version']);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::IC_CLOSE_REQUESTED->value,
            'entity_type' => 'interview_container',
            'entity_id' => $active->id,
        ]);
    }

    public function test_close_approval_requires_stepup_and_maker_cannot_self_approve(): void
    {
        $active = $this->activeContainer();
        $this->service->requestClose($this->maker, (int) $active->id, 'Tutup setelah selesai', ['version' => $active->version]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->value('id');

        $this->actingAs($this->checker);
        try {
            $this->service->approveClose($this->checker, $pendingId, 'Disetujui');
            $this->fail('Close approval without step-up must be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }

        $this->maker->givePermissionTo('jobs.review');
        $this->actingAs($this->maker);
        try {
            $this->service->approveClose($this->maker, $pendingId, 'Tidak boleh self approve');
            $this->fail('The maker must not approve their own close request.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }

        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_close_approval_freezes_participations_and_releases_candidates(): void
    {
        $active = $this->activeContainer();
        $first = $this->approvedCandidate();
        $second = $this->approvedCandidate();
        $participationService = app(InterviewParticipationService::class);
        $participations = $participationService->pull($this->maker, (int) $active->id, [$first, $second]);

        $this->service->requestClose($this->maker, (int) $active->id, 'Wawancara ditutup', ['version' => $active->version]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->value('id');

        $this->actingAs($this->checker);
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$active->id => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
        $closed = $this->service->approveClose($this->checker, $pendingId, 'Semua sesi selesai');

        $this->assertSame('Ditutup', $closed->status);
        $this->assertSame((int) $active->version + 1, (int) $closed->version);
        $this->assertNotNull($closed->closed_at);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
            'note_checker' => 'Semua sesi selesai',
        ]);

        foreach ($participations as $participation) {
            $this->assertDatabaseHas('participation', [
                'id' => $participation->id,
                'status_wawancara' => InterviewParticipationStatus::WAITING->value,
                'version' => 0,
            ]);
        }
        foreach ([$first, $second] as $candidateId) {
            $this->assertDatabaseHas('candidate', [
                'id' => $candidateId,
                'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                'version' => 2,
            ]);
        }
        $this->assertSame(1, DB::table('audit_log')->where('action_type', ActionType::IC_CLOSE_REQUESTED->value)->count());
        $this->assertSame(1, DB::table('audit_log')->where('action_type', ActionType::IC_CLOSED->value)->count());

        $this->actingAs($this->maker);
        try {
            $participationService->updateStatus(
                $this->maker,
                (int) $participations[0]->id,
                InterviewParticipationStatus::PASSED,
                ['version' => 0],
            );
            $this->fail('A closed container must freeze participation transitions.');
        } catch (ValidationException $exception) {
            $this->assertSame(['IC_NOT_ACTIVE'], $exception->errors()['container'] ?? []);
        }
    }

    public function test_close_rejection_requires_note_and_preserves_active_state(): void
    {
        $active = $this->activeContainer();
        $candidateId = $this->approvedCandidate();
        app(InterviewParticipationService::class)->pull($this->maker, (int) $active->id, [$candidateId]);
        $this->service->requestClose($this->maker, (int) $active->id, 'Tutup', ['version' => $active->version]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->value('id');

        $this->actingAs($this->checker);
        try {
            $this->service->rejectClose($this->checker, $pendingId, '   ');
            $this->fail('A checker rejection note is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        $rejected = $this->service->rejectClose($this->checker, $pendingId, 'Jadwal perlu dipertahankan');

        $this->assertSame('Aktif', $rejected->status);
        $this->assertSame((int) $active->version, (int) $rejected->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Jadwal perlu dipertahankan',
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 1,
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action_type', ActionType::IC_REJECTED->value)->count());
    }

    public function test_close_double_approval_is_rejected_after_pending_decision(): void
    {
        $active = $this->activeContainer();
        $this->service->requestClose($this->maker, (int) $active->id, 'Selesai', ['version' => $active->version]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->value('id');

        $this->actingAs($this->checker);
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$active->id => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
        $this->service->approveClose($this->checker, $pendingId, 'Setuju');

        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$active->id => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
        try {
            $this->service->approveClose($this->checker, $pendingId, 'Setuju lagi');
            $this->fail('A second close approval must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }
    }

    public function test_stale_close_cannot_approve_but_can_be_rejected(): void
    {
        $active = $this->activeContainer();
        $this->service->requestClose($this->maker, (int) $active->id, 'Tutup', ['version' => $active->version]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $active->id)
            ->value('id');
        DB::table('interview_container')->where('id', $active->id)->update([
            'version' => (int) $active->version + 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->checker);
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$active->id => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
        try {
            $this->service->approveClose($this->checker, $pendingId, 'Setuju');
            $this->fail('A stale close snapshot must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $rejected = $this->service->rejectClose(
            $this->checker,
            $pendingId,
            'Versi kontainer sudah berubah',
            ['version' => (int) $active->version + 1],
        );
        $this->assertSame('Aktif', $rejected->status);
        $this->assertSame((int) $active->version + 1, (int) $rejected->version);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
        ]);
    }

    public function test_guest_link_request_keeps_token_absent_until_checker_approval(): void
    {
        $active = $this->activeContainer();
        $expiresAt = now()->addDays(2)->toISOString();

        $pending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'Interview September 2026',
            'tanggal_kadaluarsa' => $expiresAt,
            'kode_tambahan' => 'wa-secret',
        ]);

        $this->assertSame(PendingType::GUEST_LINK, $pending->type);
        $this->assertSame(PendingStatus::PENDING, $pending->status);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pending->id,
            'type' => PendingType::GUEST_LINK->value,
            'target_type' => 'interview_container',
            'target_id' => $active->id,
            'status' => PendingStatus::PENDING->value,
        ]);
        $payload = $pending->payload['snapshot'];
        $this->assertSame('Interview September 2026', $payload['label']);
        $this->assertNotSame('wa-secret', $payload['kode_tambahan_hash']);
        $this->assertSame(hash('sha256', 'wa-secret'), $payload['kode_tambahan_hash']);
        $this->assertDatabaseMissing('guest_link', [
            'interview_container_id' => $active->id,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::GUEST_LINK_REQUESTED->value,
            'entity_type' => 'interview_container',
            'entity_id' => $active->id,
        ]);
    }

    public function test_guest_link_approval_generates_token_and_allows_multiple_active_links(): void
    {
        $active = $this->activeContainer();
        $pending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'First link',
            'tanggal_kadaluarsa' => now()->addDays(2)->toISOString(),
        ]);

        $this->actingAs($this->checker);
        $approved = $this->guestLinks->approveGuestLink($this->checker, (int) $pending->id);

        $this->assertSame('Aktif', $approved->status_link);
        $this->assertIsString($approved->token);
        $this->assertSame(64, strlen($approved->token));
        $this->assertNotSame($approved->token, $approved->token_hash);
        $this->assertDatabaseHas('guest_link', [
            'id' => $approved->id,
            'label' => 'First link',
            'interview_container_id' => $active->id,
            'status_link' => 'Aktif',
            'dibuat_oleh' => $this->maker->id,
            'disetujui_oleh' => $this->checker->id,
        ]);
        $this->assertSame(
            $approved->token_hash,
            hash('sha256', $approved->token),
        );
        $this->assertDatabaseHas('pending_request', [
            'id' => $pending->id,
            'status' => PendingStatus::APPROVED->value,
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action_type', ActionType::GUEST_LINK_APPROVED->value)->count());

        try {
            $this->guestLinks->approveGuestLink($this->checker, (int) $pending->id);
            $this->fail('A second guest-link approval must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('guest_link')->where('interview_container_id', $active->id)->count());

        $this->actingAs($this->maker);
        $secondPending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'Second link',
            'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
        ]);
        $this->actingAs($this->checker);
        $second = $this->guestLinks->approveGuestLink($this->checker, (int) $secondPending->id);

        $this->assertNotSame($approved->token, $second->token);
        $this->assertSame(2, DB::table('guest_link')->where('interview_container_id', $active->id)->count());
    }

    public function test_guest_link_rejection_requires_note_and_creates_no_link(): void
    {
        $active = $this->activeContainer();
        $pending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'Rejected link',
            'tanggal_kadaluarsa' => now()->addDay()->toISOString(),
        ]);

        $this->actingAs($this->checker);
        try {
            $this->guestLinks->rejectGuestLink($this->checker, (int) $pending->id, '   ');
            $this->fail('A checker rejection note is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        $rejected = $this->guestLinks->rejectGuestLink($this->checker, (int) $pending->id, 'Link belum diperlukan');

        $this->assertSame(PendingStatus::REJECTED, $rejected->status);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pending->id,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Link belum diperlukan',
        ]);
        $this->assertDatabaseMissing('guest_link', [
            'interview_container_id' => $active->id,
        ]);
        $this->assertSame(1, DB::table('audit_log')->where('action_type', ActionType::GUEST_LINK_REJECTED->value)->count());
    }

    public function test_guest_link_maker_cannot_self_approve_and_closed_container_cannot_request(): void
    {
        $active = $this->activeContainer();
        $pending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'Self approval',
            'tanggal_kadaluarsa' => now()->addDay()->toISOString(),
        ]);

        $this->maker->givePermissionTo('jobs.review');
        $this->actingAs($this->maker);
        try {
            $this->guestLinks->approveGuestLink($this->maker, (int) $pending->id);
            $this->fail('The maker must not approve their own guest link request.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }

        $closed = $this->activeContainer(['judul' => 'Closed guest source']);
        DB::table('interview_container')->where('id', $closed->id)->update(['status' => 'Ditutup']);
        try {
            $this->guestLinks->requestGuestLink($this->maker, (int) $closed->id, [
                'version' => $closed->version,
                'label' => 'Cannot request',
                'tanggal_kadaluarsa' => now()->addDay()->toISOString(),
            ]);
            $this->fail('A closed container must not receive guest-link requests.');
        } catch (ValidationException $exception) {
            $this->assertSame(['GUEST_CONTAINER_NOT_ACTIVE'], $exception->errors()['container'] ?? []);
        }
    }

    public function test_stale_guest_link_pending_can_be_rejected_without_creating_a_link(): void
    {
        $active = $this->activeContainer();
        $pending = $this->guestLinks->requestGuestLink($this->maker, (int) $active->id, [
            'version' => $active->version,
            'label' => 'Stale guest link',
            'tanggal_kadaluarsa' => now()->addDay()->toISOString(),
        ]);
        DB::table('interview_container')->where('id', $active->id)->update([
            'version' => (int) $active->version + 1,
            'status' => 'Ditutup',
        ]);

        $this->actingAs($this->checker);
        try {
            $this->guestLinks->approveGuestLink($this->checker, (int) $pending->id);
            $this->fail('A closed container guest-link approval must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }
        $rejected = $this->guestLinks->rejectGuestLink(
            $this->checker,
            (int) $pending->id,
            'Kontainer sudah ditutup',
            ['version' => (int) $active->version + 1],
        );

        $this->assertSame(PendingStatus::REJECTED, $rejected->status);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pending->id,
            'status' => PendingStatus::REJECTED->value,
        ]);
        $this->assertDatabaseMissing('guest_link', [
            'interview_container_id' => $active->id,
        ]);
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

    private function activeContainer(array $overrides = []): object
    {
        $this->actingAs($this->maker);
        $draft = $this->service->createDraft($this->maker, $this->payload($overrides));
        $submitted = $this->service->submit($this->maker, (int) $draft->id, ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::IC_CREATE->value)
            ->where('target_id', $draft->id)
            ->where('status', PendingStatus::PENDING->value)
            ->value('id');

        $this->actingAs($this->checker);
        $active = $this->service->approve($this->checker, $pendingId, ['version' => $submitted->version]);
        $this->actingAs($this->maker);

        return $active;
    }

    private function approvedCandidate(array $overrides = []): int
    {
        $this->candidateSequence++;

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => sprintf('K-2026-%05d', $this->candidateSequence),
            'nama_alphabet' => 'W4 Close Candidate '.$this->candidateSequence,
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker->id,
            'approved_by' => $this->checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedCountry(): int
    {
        return (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
