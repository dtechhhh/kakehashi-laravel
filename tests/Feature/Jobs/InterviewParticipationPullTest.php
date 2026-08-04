<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Jobs\Enums\InterviewParticipationStatus;
use Modules\Jobs\Services\InterviewParticipationService;
use Shared\Audit\ActionType;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class InterviewParticipationPullTest extends TestCase
{
    use RefreshDatabase;

    private InterviewParticipationService $service;

    private User $actor;

    private User $checker;

    private int $containerId;

    private int $countryId;

    private int $candidateSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->active()->create();
        $this->actor->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->checker = User::factory()->active()->create();
        $this->checker->assignRole(Rbac::JOB_MANAGER);
        $this->countryId = $this->seedCountry();
        $this->containerId = $this->seedContainer('Aktif');
        $this->service = app(InterviewParticipationService::class);

        $this->actingAs($this->actor);
    }

    public function test_pull_creates_waiting_participation_marks_candidates_in_use_and_audits(): void
    {
        $first = $this->approvedCandidate();
        $second = $this->approvedCandidate();

        $rows = $this->service->pull($this->actor, $this->containerId, [$second, $first]);

        $this->assertCount(2, $rows);
        $this->assertSame([$first, $second], array_map(
            static fn (object $row): int => (int) $row->candidate_id,
            $rows,
        ));
        $this->assertSame(2, DB::table('participation')->count());
        $this->assertSame(2, DB::table('audit_log')
            ->where('action_type', ActionType::CANDIDATE_PULLED->value)
            ->count());

        foreach ([$first, $second] as $candidateId) {
            $this->assertDatabaseHas('participation', [
                'interview_container_id' => $this->containerId,
                'candidate_id' => $candidateId,
                'status_wawancara' => 'Menunggu Wawancara',
                'version' => 0,
            ]);
            $this->assertDatabaseHas('candidate', [
                'id' => $candidateId,
                'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
                'version' => 1,
            ]);
        }
    }

    public function test_one_ineligible_candidate_rolls_back_the_whole_bulk_pull(): void
    {
        $eligible = $this->approvedCandidate();
        $unavailable = $this->approvedCandidate([
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
        ]);

        try {
            $this->service->pull($this->actor, $this->containerId, [$eligible, $unavailable]);
            $this->fail('An ineligible candidate must roll back the whole pull.');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_AVAILABLE']], $exception->errors());
        }

        $this->assertSame(0, DB::table('participation')->count());
        $this->assertDatabaseHas('candidate', [
            'id' => $eligible,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
    }

    public function test_non_active_container_cannot_receive_candidates(): void
    {
        DB::table('interview_container')->where('id', $this->containerId)->update([
            'status' => 'Draft',
        ]);
        $candidateId = $this->approvedCandidate();

        try {
            $this->service->pull($this->actor, $this->containerId, [$candidateId]);
            $this->fail('A non-active container must reject pull.');
        } catch (ValidationException $exception) {
            $this->assertSame(['container' => ['IC_NOT_ACTIVE']], $exception->errors());
        }

        $this->assertSame(0, DB::table('participation')->count());
        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
    }

    public function test_existing_active_participation_blocks_a_second_pull(): void
    {
        $candidateId = $this->approvedCandidate();
        DB::table('participation')->insert([
            'interview_container_id' => $this->containerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Menunggu Wawancara',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->service->pull($this->actor, $this->containerId, [$candidateId]);
            $this->fail('A candidate with an active participation must not be pulled again.');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_ALREADY_IN_INTERVIEW']], $exception->errors());
        }

        $this->assertSame(1, DB::table('participation')->count());
        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
    }

    public function test_missing_container_is_not_found_before_candidate_mutation(): void
    {
        $candidateId = $this->approvedCandidate();

        try {
            $this->service->pull($this->actor, 999999, [$candidateId]);
            $this->fail('A missing container must be reported as not found.');
        } catch (NotFoundHttpException $exception) {
            $this->assertSame('IC_NOT_FOUND', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('participation')->count());
        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
        ]);
    }

    public function test_natural_statuses_advance_strictly_to_ready_for_placement(): void
    {
        $candidateId = $this->approvedCandidate();
        $participation = $this->service->pull($this->actor, $this->containerId, [$candidateId])[0];

        foreach ([
            InterviewParticipationStatus::PASSED,
            InterviewParticipationStatus::DOCUMENT_PROCESS,
            InterviewParticipationStatus::READY_FOR_PLACEMENT,
        ] as $version => $next) {
            $participation = $this->service->updateStatus(
                $this->actor,
                (int) $participation->id,
                $next,
                ['version' => $version],
            );
        }

        $this->assertSame(InterviewParticipationStatus::READY_FOR_PLACEMENT->value, $participation->status_wawancara);
        $this->assertSame(3, (int) $participation->version);
        $this->assertSame(3, DB::table('audit_log')
            ->where('action_type', ActionType::PARTICIPATION_STATUS_CHANGED->value)
            ->count());
    }

    public function test_rollback_jump_and_manual_sent_are_rejected(): void
    {
        $candidateId = $this->approvedCandidate();
        $participation = $this->service->pull($this->actor, $this->containerId, [$candidateId])[0];

        foreach ([
            InterviewParticipationStatus::DOCUMENT_PROCESS,
            InterviewParticipationStatus::WAITING,
            InterviewParticipationStatus::SENT,
        ] as $next) {
            try {
                $this->service->updateStatus(
                    $this->actor,
                    (int) $participation->id,
                    $next,
                    ['version' => 0],
                );
                $this->fail('Invalid natural transition must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    ['status_wawancara' => ['PARTICIPATION_INVALID_TRANSITION']],
                    $exception->errors(),
                );
            }
        }

        $this->assertDatabaseHas('participation', [
            'id' => $participation->id,
            'status_wawancara' => InterviewParticipationStatus::WAITING->value,
            'version' => 0,
        ]);
    }

    public function test_natural_terminal_statuses_release_candidate_availability(): void
    {
        foreach ([InterviewParticipationStatus::FAILED, InterviewParticipationStatus::WITHDRAWN] as $next) {
            $candidateId = $this->approvedCandidate();
            $participation = $this->service->pull($this->actor, $this->containerId, [$candidateId])[0];

            $updated = $this->service->updateStatus(
                $this->actor,
                (int) $participation->id,
                $next,
                ['version' => 0],
            );

            $this->assertSame($next->value, $updated->status_wawancara);
            $this->assertDatabaseHas('candidate', [
                'id' => $candidateId,
                'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                'version' => 2,
            ]);
        }
    }

    public function test_closed_container_freezes_natural_status_update(): void
    {
        $candidateId = $this->approvedCandidate();
        $participation = $this->service->pull($this->actor, $this->containerId, [$candidateId])[0];
        DB::table('interview_container')->where('id', $this->containerId)->update(['status' => 'Ditutup']);

        try {
            $this->service->updateStatus(
                $this->actor,
                (int) $participation->id,
                InterviewParticipationStatus::PASSED,
                ['version' => 0],
            );
            $this->fail('Closed containers must freeze participation status.');
        } catch (ValidationException $exception) {
            $this->assertSame(['container' => ['IC_NOT_ACTIVE']], $exception->errors());
        }

        $this->assertDatabaseHas('participation', [
            'id' => $participation->id,
            'status_wawancara' => InterviewParticipationStatus::WAITING->value,
            'version' => 0,
        ]);
        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 1,
        ]);
    }

    public function test_stale_participation_version_conflicts_without_mutation(): void
    {
        $candidateId = $this->approvedCandidate();
        $participation = $this->service->pull($this->actor, $this->containerId, [$candidateId])[0];

        try {
            $this->service->updateStatus(
                $this->actor,
                (int) $participation->id,
                InterviewParticipationStatus::PASSED,
                ['version' => 1],
            );
            $this->fail('A stale participation version must conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('participation', [
            'id' => $participation->id,
            'status_wawancara' => InterviewParticipationStatus::WAITING->value,
            'version' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function approvedCandidate(array $overrides = []): int
    {
        $this->candidateSequence++;

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => sprintf('K-2026-%05d', $this->candidateSequence),
            'nama_alphabet' => 'W4 Pull Candidate '.$this->candidateSequence,
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->actor->id,
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

    private function seedContainer(string $status): int
    {
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W4 Pull Company',
            'nama_romaji' => 'W4 Pull',
            'nama_id' => 'Perusahaan Pull',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W4_PULL_POSITION',
            'label_id' => 'Posisi Pull',
            'label_ja' => '引抜ポジション',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W4_PULL_VISA',
            'label_id' => 'Visa Pull',
            'label_ja' => '引抜ビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W4 Pull Container',
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 2,
            'deskripsi' => 'Synthetic pull fixture',
            'syarat' => 'N3',
            'status' => $status,
            'dibuat_oleh' => $this->actor->id,
            'disetujui_oleh' => $status === 'Aktif' ? $this->checker->id : null,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => $status === 'Aktif' ? now() : null,
            'updated_at' => now(),
        ]);
    }
}
