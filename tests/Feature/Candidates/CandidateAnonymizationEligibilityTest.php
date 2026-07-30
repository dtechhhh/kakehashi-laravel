<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Services\CandidateAnonymizationEligibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CandidateAnonymizationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_action_and_all_external_probes_run_in_one_transaction(): void
    {
        [$candidateId] = $this->candidateFixture();
        $baseLevel = DB::connection()->transactionLevel();
        $levels = [];

        $result = app(CandidateAnonymizationEligibilityService::class)->run(
            $candidateId,
            function (int $id) use ($candidateId, &$levels): bool {
                $this->assertSame($candidateId, $id);
                $levels[] = DB::connection()->transactionLevel();

                return false;
            },
            function (int $id) use ($candidateId, &$levels): bool {
                $this->assertSame($candidateId, $id);
                $levels[] = DB::connection()->transactionLevel();

                return false;
            },
            function (int $id) use ($candidateId, &$levels): bool {
                $this->assertSame($candidateId, $id);
                $levels[] = DB::connection()->transactionLevel();

                return false;
            },
            function (object $candidate) use ($candidateId, &$levels): int {
                $this->assertSame($candidateId, (int) $candidate->id);
                $levels[] = DB::connection()->transactionLevel();

                return (int) $candidate->id;
            },
        );

        $this->assertSame($candidateId, $result);
        $this->assertCount(4, $levels);
        foreach ($levels as $level) {
            $this->assertSame($baseLevel + 1, $level);
        }
        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
    }

    #[DataProvider('blockerProvider')]
    public function test_each_required_guard_blocks_before_the_action(string $blocker): void
    {
        [$candidateId, $makerId, $countryId] = $this->candidateFixture();
        $participation = false;
        $placement = false;
        $pending = false;

        match ($blocker) {
            'availability' => DB::table('candidate')->where('id', $candidateId)->update([
                'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            ]),
            'participation' => $participation = true,
            'placement' => $placement = true,
            'pending' => $pending = true,
            'revision' => $this->insertRevision($candidateId, $makerId, $countryId),
        };

        $actionRan = false;

        try {
            app(CandidateAnonymizationEligibilityService::class)->run(
                $candidateId,
                fn (int $id): bool => $participation,
                fn (int $id): bool => $placement,
                fn (int $id): bool => $pending,
                function (object $candidate) use (&$actionRan): void {
                    $actionRan = true;
                },
            );
            $this->fail("{$blocker} must block anonymization eligibility");
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['PII_ACTIVE']], $exception->errors());
        }

        $this->assertFalse($actionRan);
        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
    }

    public static function blockerProvider(): array
    {
        return [
            'availability bukan Tersedia' => ['availability'],
            'participation aktif' => ['participation'],
            'placement Bekerja' => ['placement'],
            'pending terbuka' => ['pending'],
            'revision aktif' => ['revision'],
        ];
    }

    public function test_existing_pending_is_revalidated_by_the_pending_probe(): void
    {
        [$candidateId, $makerId] = $this->candidateFixture();

        DB::table('pending_request')->insert([
            'type' => 'CANDIDATE_NEW',
            'target_type' => 'candidate',
            'target_id' => $candidateId,
            'requested_by' => $makerId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('PII_ACTIVE');

        app(CandidateAnonymizationEligibilityService::class)->run(
            $candidateId,
            fn (int $id): bool => false,
            fn (int $id): bool => false,
            fn (int $id): bool => DB::table('pending_request')
                ->where('target_type', 'candidate')
                ->where('target_id', $id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first() !== null,
            fn (object $candidate): null => null,
        );
    }

    public function test_action_failure_rolls_back_every_write(): void
    {
        [$candidateId] = $this->candidateFixture();

        try {
            app(CandidateAnonymizationEligibilityService::class)->run(
                $candidateId,
                fn (int $id): bool => false,
                fn (int $id): bool => false,
                fn (int $id): bool => false,
                function (object $candidate): never {
                    DB::table('candidate')->where('id', $candidate->id)->update([
                        'catatan_tambahan' => 'must-roll-back',
                        'pii_anonymized_at' => now(),
                    ]);

                    throw new RuntimeException('forced rollback');
                },
            );
            $this->fail('eligible action failure must escape');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rollback', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $candidateId,
            'catatan_tambahan' => null,
            'pii_anonymized_at' => null,
        ]);
    }

    public function test_anonymized_or_revision_rows_are_frozen(): void
    {
        [$candidateId, $makerId, $countryId] = $this->candidateFixture();
        DB::table('candidate')->where('id', $candidateId)->update(['pii_anonymized_at' => now()]);

        $this->assertFrozen($candidateId);

        DB::table('candidate')->where('id', $candidateId)->update(['pii_anonymized_at' => null]);
        $revisionId = $this->insertRevision($candidateId, $makerId, $countryId);

        $this->assertFrozen($revisionId);
    }

    public function test_no_anonymization_route_or_pii_leakage_is_added(): void
    {
        [$candidateId] = $this->candidateFixture('Sensitive Candidate Name');

        $this->assertFalse(Route::has('candidates.anonymize'));
        $this->postJson("/candidates/{$candidateId}/anonymize")
            ->assertNotFound()
            ->assertDontSee('Sensitive Candidate Name');

        Route::post('/_test/w3-t8/{candidate}', function (int $candidate): void {
            app(CandidateAnonymizationEligibilityService::class)->run(
                $candidate,
                fn (int $id): bool => false,
                fn (int $id): bool => false,
                fn (int $id): bool => true,
                fn (object $candidate): null => null,
            );
        });

        $this->postJson("/_test/w3-t8/{$candidateId}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.candidate.0', 'PII_ACTIVE')
            ->assertDontSee('Sensitive Candidate Name');
    }

    private function assertFrozen(int $candidateId): void
    {
        try {
            app(CandidateAnonymizationEligibilityService::class)->run(
                $candidateId,
                fn (int $id): bool => false,
                fn (int $id): bool => false,
                fn (int $id): bool => false,
                fn (object $candidate): null => null,
            );
            $this->fail('frozen row must not be eligible');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['PII_FROZEN']], $exception->errors());
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function candidateFixture(string $name = 'Eligible Candidate'): array
    {
        $maker = User::factory()->active()->create();
        $approver = User::factory()->active()->create();
        $countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidateId = (int) DB::table('candidate')->insertGetId([
            'nomor_induk' => 'K-2026-00001',
            'nama_alphabet' => $name,
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => CandidateApprovalStatus::Disetujui->value,
            'version' => 1,
            'created_by' => $maker->getKey(),
            'approved_by' => $approver->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$candidateId, (int) $maker->getKey(), $countryId];
    }

    private function insertRevision(int $mainId, int $makerId, int $countryId): int
    {
        return (int) DB::table('candidate')->insertGetId([
            'nama_alphabet' => 'Active Revision',
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => CandidateApprovalStatus::Draft->value,
            'parent_candidate_id' => $mainId,
            'version' => 0,
            'created_by' => $makerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
