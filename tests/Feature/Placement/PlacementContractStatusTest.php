<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\StepUpAction;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Placement\Services\PlacementParticipationService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementContractStatusTest extends TestCase
{
    use PlacementFixture;
    use RefreshDatabase;

    private PlacementParticipationService $participation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlacementUsers();
        $this->seedPlacementReferences();
        $this->participation = app(PlacementParticipationService::class);
    }

    public function test_complete_contract_is_direct_terminal_releases_candidate_and_audits(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);

        $completed = $this->participation->completeContract($this->maker, $participantId, ['version' => 0]);

        $this->assertSame('Selesai Kontrak', $completed->status_penempatan);
        $this->assertNotNull($completed->tanggal_status_final);
        $this->assertNull($completed->catatan_alasan);
        $this->assertSame(1, (int) $completed->version);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PLACEMENT_STATUS_CHANGED->value,
            'entity_type' => 'placement_participants',
            'entity_id' => $participantId,
        ]);
        // One terminal row -> container archived (checked after the batch).
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Arsip',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::CONTAINER_ARCHIVED->value,
            'entity_type' => 'placement_container',
            'entity_id' => $container->id,
        ]);
    }

    public function test_resign_request_creates_pending_and_approval_is_routine(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);

        try {
            $this->participation->requestResign($this->maker, $participantId, '   ', ['version' => 0]);
            $this->fail('Resign reason is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PLACEMENT_REASON_REQUIRED'], $exception->errors()['reason_maker'] ?? []);
        }

        $this->participation->requestResign($this->maker, $participantId, 'Keluarga pindah negara', ['version' => 0]);
        $pending = DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_RESIGN->value)
            ->where('target_type', 'placement_participants')
            ->where('target_id', $participantId)
            ->where('status', PendingStatus::PENDING->value)
            ->first();

        $this->assertNotNull($pending);
        $this->assertSame('Keluarga pindah negara', $pending->reason_maker);
        $this->assertDatabaseHas('placement_participants', [
            'id' => $participantId,
            'status_penempatan' => 'Bekerja',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::RESIGN_REQUESTED->value,
            'entity_type' => 'placement_participants',
        ]);

        // No step-up token: routine approval succeeds.
        $pendingId = (int) $pending->id;
        $this->actingAs($this->checker);
        $approved = $this->participation->approveResign($this->checker, $pendingId, ['version' => 0]);

        $this->assertSame('Mengundurkan Diri', $approved->status_penempatan);
        $this->assertSame('Keluarga pindah negara', $approved->catatan_alasan);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::RESIGN_APPROVED->value,
            'entity_type' => 'placement_participants',
        ]);
    }

    public function test_resign_rejection_requires_note_and_preserves_participant(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);
        $this->participation->requestResign($this->maker, $participantId, 'Alasan resign', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_RESIGN->value)
            ->value('id');

        $this->actingAs($this->checker);
        try {
            $this->participation->rejectResign($this->checker, $pendingId, '   ', ['version' => 0]);
            $this->fail('A rejection note is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        $this->participation->rejectResign($this->checker, $pendingId, 'Perusahaan keberatan', ['version' => 0]);

        $this->assertDatabaseHas('placement_participants', [
            'id' => $participantId,
            'status_penempatan' => 'Bekerja',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::RESIGN_REJECTED->value,
            'entity_type' => 'placement_participants',
        ]);
    }

    public function test_expel_requires_stepup_and_two_layer_reason(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);
        $this->participation->requestExpel($this->maker, $participantId, 'Pelanggaran kontrak', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_EXPEL->value)
            ->value('id');

        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PLACEMENT_EXPEL_REQUESTED->value,
            'entity_type' => 'placement_participants',
        ]);

        $this->actingAs($this->checker);
        try {
            $this->participation->approveExpel($this->checker, $pendingId, 'Catatan checker');
            $this->fail('Expel approval without step-up must be rejected.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }

        try {
            $this->participation->approveExpel($this->checker, $pendingId, '   ');
            $this->fail('Expel approval needs a checker justification.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_CANDIDATE_EXPEL.'.placement_participants.'.$participantId => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
        $approved = $this->participation->approveExpel($this->checker, $pendingId, 'Disetujui setelah verifikasi');

        $this->assertSame('Dikeluarkan', $approved->status_penempatan);
        $this->assertSame('Pelanggaran kontrak', $approved->catatan_alasan);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
            'note_checker' => 'Disetujui setelah verifikasi',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PLACEMENT_EXPEL_APPROVED->value,
            'entity_type' => 'placement_participants',
        ]);
    }

    public function test_expel_rejection_preserves_participant(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->readyCandidate();
        $participantId = $this->workingParticipant($container, $candidate);
        $this->participation->requestExpel($this->maker, $participantId, 'Alasan expel', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_EXPEL->value)
            ->value('id');

        $this->actingAs($this->checker);
        $this->participation->rejectExpel($this->checker, $pendingId, 'Bukti tidak cukup', ['version' => 0]);

        $this->assertDatabaseHas('placement_participants', [
            'id' => $participantId,
            'status_penempatan' => 'Bekerja',
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate['candidate_id'],
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 0,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action_type' => ActionType::PLACEMENT_EXPEL_REJECTED->value,
            'entity_type' => 'placement_participants',
        ]);
    }

    public function test_terminal_transition_is_impossible_from_non_working_and_double_decision_conflicts(): void
    {
        $container = $this->activePlacementContainer();
        $first = $this->readyCandidate();
        $second = $this->readyCandidate();
        $firstId = $this->workingParticipant($container, $first);
        $secondId = $this->workingParticipant($container, $second);

        $this->participation->completeContract($this->maker, $firstId, ['version' => 0]);
        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Aktif', // not archived while another Bekerja remains
        ]);

        try {
            $this->participation->completeContract($this->maker, $firstId, ['version' => 1]);
            $this->fail('A terminal row cannot transition again.');
        } catch (ValidationException $exception) {
            $this->assertSame(['PLACEMENT_NOT_WORKING'], $exception->errors()['status_penempatan'] ?? []);
        }

        $this->participation->requestResign($this->maker, $secondId, 'Alasan', ['version' => 0]);
        $pendingId = (int) DB::table('pending_request')
            ->where('type', PendingType::PLACEMENT_RESIGN->value)
            ->value('id');

        $this->actingAs($this->checker);
        $this->participation->approveResign($this->checker, $pendingId, ['version' => 0]);
        try {
            $this->participation->approveResign($this->checker, $pendingId, ['version' => 0]);
            $this->fail('A second decision must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }

        $this->assertDatabaseHas('placement_container', [
            'id' => $container->id,
            'status' => 'Arsip', // last Bekerja reached terminal -> auto archive
        ]);
    }

    /**
     * @param  array{candidate_id: int, participation_id: int}  $candidate
     */
    private function workingParticipant(object $container, array $candidate): int
    {
        return (int) DB::table('placement_participants')->insertGetId([
            'placement_container_id' => $container->id,
            'candidate_id' => $candidate['candidate_id'],
            'source_participation_id' => $candidate['participation_id'],
            'kategori_force_majeur_id' => null,
            'alasan_force_majeur' => null,
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => 'Bekerja',
            'version' => 0,
            'disetujui_oleh' => $this->checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
