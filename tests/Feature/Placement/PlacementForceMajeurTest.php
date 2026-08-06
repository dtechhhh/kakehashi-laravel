<?php

namespace Tests\Feature\Placement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Placement\Services\PlacementForceMajeurService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PlacementForceMajeurTest extends TestCase
{
    use PlacementFixture;
    use RefreshDatabase;

    private PlacementForceMajeurService $forceMajeur;

    private int $forceMajeurId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupPlacementUsers();
        $this->seedPlacementReferences();
        $this->forceMajeur = app(PlacementForceMajeurService::class);
        $this->forceMajeurId = (int) DB::table('kategori_force_majeur')->insertGetId([
            'code' => 'SAKIT_BERAT',
            'label_id' => 'Sakit Berat',
            'label_ja' => '重病',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_request_creates_pending_snapshot_without_mutating_candidate(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();

        $result = $this->forceMajeur->requestForceMajeur($this->maker, (int) $container->id, [
            'candidate_id' => $candidate,
            'kategori_force_majeur_id' => $this->forceMajeurId,
            'alasan_force_majeur' => 'Kandidat sakit berat menjelang keberangkatan',
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
        ], ['version' => 0]);

        $pending = DB::table('pending_request')
            ->where('type', PendingType::FORCE_MAJEUR->value)
            ->where('target_type', 'placement_container')
            ->where('target_id', $container->id)
            ->where('status', PendingStatus::PENDING->value)
            ->first();

        $this->assertNotNull($pending);
        $this->assertSame((int) $pending->id, $result->pending_request_id);
        $snapshot = is_string($pending->payload)
            ? json_decode($pending->payload, true, 512, JSON_THROW_ON_ERROR)['snapshot']
            : (array) $pending->payload['snapshot'];
        $this->assertSame($candidate, $snapshot['candidate_id']);
        $this->assertSame(0, $snapshot['candidate_version']);
        $this->assertSame($this->forceMajeurId, $snapshot['kategori_force_majeur_id']);
        $this->assertSame('Kandidat sakit berat menjelang keberangkatan', $snapshot['alasan_force_majeur']);
        $this->assertSame('2027-08-31', $snapshot['tanggal_berakhir_kontrak']);

        $this->assertDatabaseHas('candidate', [
            'id' => $candidate,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('placement_participants')->count());
    }

    public function test_request_requires_tersedia_approved_candidate_and_reason(): void
    {
        $container = $this->activePlacementContainer();
        $inUse = $this->fmCandidate(CandidateAvailability::SedangDipakai->value);

        try {
            $this->forceMajeur->requestForceMajeur($this->maker, (int) $container->id, [
                'candidate_id' => $inUse,
                'kategori_force_majeur_id' => $this->forceMajeurId,
                'alasan_force_majeur' => 'Alasan',
                'jenis_visa_id' => $this->visaId,
                'tanggal_mulai_kerja' => '2026-09-01',
                'durasi_kontrak_bulan' => 12,
            ], ['version' => 0]);
            $this->fail('Force-Majeur must start from Tersedia + Disetujui.');
        } catch (ValidationException $exception) {
            $this->assertSame(['FM_STATE'], $exception->errors()['candidate'] ?? []);
        }

        try {
            $this->forceMajeur->requestForceMajeur($this->maker, (int) $container->id, [
                'candidate_id' => $this->fmCandidate(),
                'kategori_force_majeur_id' => $this->forceMajeurId,
                'alasan_force_majeur' => '   ',
                'jenis_visa_id' => $this->visaId,
                'tanggal_mulai_kerja' => '2026-09-01',
                'durasi_kontrak_bulan' => 12,
            ], ['version' => 0]);
            $this->fail('A blank Force-Majeur reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(['FM_REASON'], $exception->errors()['alasan_force_majeur'] ?? []);
        }

        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_approve_is_routine_without_stepup_marks_in_use_and_audits(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();
        $pendingId = $this->request($container, $candidate);

        // No step-up token present — a routine approval must still succeed.
        $this->actingAs($this->checker);
        $participant = $this->forceMajeur->approveForceMajeur($this->checker, $pendingId, ['version' => 0]);

        $this->assertSame('Bekerja', $participant->status_penempatan);
        $this->assertNull($participant->source_participation_id);
        $this->assertSame($this->forceMajeurId, (int) $participant->kategori_force_majeur_id);
        $this->assertSame('Kandidat sakit berat menjelang keberangkatan', $participant->alasan_force_majeur);
        $this->assertSame('2027-08-31', $participant->tanggal_berakhir_kontrak);
        $this->assertSame($this->checker->id, (int) $participant->disetujui_oleh);

        $this->assertDatabaseHas('candidate', [
            'id' => $candidate,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::APPROVED->value,
        ]);
        $audit = DB::table('audit_log')
            ->where('action_type', ActionType::FORCE_MAJEUR_ADDED->value)
            ->where('entity_type', 'placement_container')
            ->first();
        $this->assertNotNull($audit);
        $detail = is_string($audit->detail) ? json_decode($audit->detail, true) : (array) $audit->detail;
        $this->assertSame('Kandidat sakit berat menjelang keberangkatan', $detail['fm_alasan_recorded']);
    }

    public function test_maker_cannot_self_approve_and_double_approval_conflicts(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();
        $pendingId = $this->request($container, $candidate);

        $this->maker->givePermissionTo('placement.review');
        try {
            $this->forceMajeur->approveForceMajeur($this->maker, $pendingId, ['version' => 0]);
            $this->fail('The maker must not approve their own Force-Majeur.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame('APV_SELF', $exception->getMessage());
        }

        $this->actingAs($this->checker);
        $this->forceMajeur->approveForceMajeur($this->checker, $pendingId, ['version' => 0]);
        try {
            $this->forceMajeur->approveForceMajeur($this->checker, $pendingId, ['version' => 0]);
            $this->fail('A second approval must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }
    }

    public function test_rejection_records_canonical_fm_rejected_and_leaves_candidate_untouched(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();
        $pendingId = $this->request($container, $candidate);

        $this->actingAs($this->checker);
        try {
            $this->forceMajeur->rejectForceMajeur($this->checker, $pendingId, '   ', ['version' => 0]);
            $this->fail('A rejection note is required.');
        } catch (ValidationException $exception) {
            $this->assertSame(['APV_NOTE'], $exception->errors()['note_checker'] ?? []);
        }

        $this->forceMajeur->rejectForceMajeur($this->checker, $pendingId, 'Bukti belum lengkap', ['version' => 0]);

        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::REJECTED->value,
            'note_checker' => 'Bukti belum lengkap',
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidate,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('placement_participants')->count());
        $this->assertSame(1, DB::table('audit_log')
            ->where('action_type', ActionType::FM_REJECTED->value)
            ->count());
    }

    public function test_approve_rolls_back_when_candidate_is_no_longer_tersedia(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();
        $pendingId = $this->request($container, $candidate);

        // Another flow consumes the candidate before the FM decision.
        DB::table('candidate')->where('id', $candidate)->update([
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->checker);
        try {
            $this->forceMajeur->approveForceMajeur($this->checker, $pendingId, ['version' => 0]);
            $this->fail('Approving an FM for a no-longer-Tersedia candidate must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['CANDIDATE_NOT_AVAILABLE'], $exception->errors()['status_ketersediaan'] ?? []);
        }

        $this->assertSame(0, DB::table('placement_participants')->count());
        $this->assertDatabaseHas('pending_request', [
            'id' => $pendingId,
            'status' => PendingStatus::PENDING->value,
        ]);
    }

    public function test_approve_stale_candidate_version_conflicts(): void
    {
        $container = $this->activePlacementContainer();
        $candidate = $this->fmCandidate();
        $pendingId = $this->request($container, $candidate);

        DB::table('candidate')->where('id', $candidate)->update([
            'version' => 1,
            'updated_at' => now(),
        ]);

        $this->actingAs($this->checker);
        try {
            $this->forceMajeur->approveForceMajeur($this->checker, $pendingId, ['version' => 0]);
            $this->fail('A stale candidate version must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }
    }

    private function request(object $container, int $candidateId): int
    {
        $result = $this->forceMajeur->requestForceMajeur($this->maker, (int) $container->id, [
            'candidate_id' => $candidateId,
            'kategori_force_majeur_id' => $this->forceMajeurId,
            'alasan_force_majeur' => 'Kandidat sakit berat menjelang keberangkatan',
            'jenis_visa_id' => $this->visaId,
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
        ], ['version' => 0]);

        return $result->pending_request_id;
    }

    private function fmCandidate(string $availability = CandidateAvailability::Tersedia->value): int
    {
        $this->candidateSequence++;

        return (int) DB::table('candidate')->insertGetId([
            'nomor_induk' => sprintf('K-2026-%05d', $this->candidateSequence + 100),
            'nama_alphabet' => 'W5 FM Candidate '.$this->candidateSequence,
            'tanggal_lahir' => '1999-05-05',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'F',
            'status_ketersediaan' => $availability,
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker->id,
            'approved_by' => $this->checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
