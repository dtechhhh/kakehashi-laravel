<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Public\CandidateAvailabilityService;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * W3-T6 — public CandidateAvailabilityService; no cross-module direct status_ketersediaan writes.
 */
class CandidateAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_available_and_approved_true_only_for_disetujui_tersedia_main(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        $this->assertTrue($service->isAvailableAndApproved($id));
        $this->assertFalse($service->isAvailableAndApproved(999_999));

        $service->markInUse($id, $version);
        $this->assertFalse($service->isAvailableAndApproved($id));
    }

    public function test_mark_in_use_and_mark_available_round_trip_bumps_version(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        $service->markInUse($id, $version);

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => $version + 1,
        ]);

        $service->assertInUse($id, $version + 1);
        $service->markAvailable($id, $version + 1);

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version + 2,
        ]);
        $this->assertTrue($service->isAvailableAndApproved($id));
    }

    public function test_mark_in_use_rejects_stale_version_with_409(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        try {
            $service->markInUse($id, $version + 1);
            $this->fail('stale version must conflict');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version,
        ]);
    }

    public function test_mark_in_use_rejects_non_available_or_non_approved_with_422(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        $service->markInUse($id, $version);

        try {
            $service->markInUse($id, $version + 1);
            $this->fail('already in use must 422');
        } catch (ValidationException $exception) {
            $this->assertSame(['status_ketersediaan' => ['CANDIDATE_NOT_AVAILABLE']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => $version + 1,
        ]);
    }

    public function test_assert_in_use_rejects_tersedia_with_422_and_does_not_mutate(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        try {
            $service->assertInUse($id, $version);
            $this->fail('tersedia must not assert in use');
        } catch (ValidationException $exception) {
            $this->assertSame(['status_ketersediaan' => ['CANDIDATE_NOT_IN_USE']], $exception->errors());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version,
        ]);
    }

    public function test_mark_available_rejects_stale_version_with_409(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);
        $service->markInUse($id, $version);

        try {
            $service->markAvailable($id, $version);
            $this->fail('stale version must conflict');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => $version + 1,
        ]);
    }

    public function test_mutations_reject_anonymized_inside_transaction_with_422(): void
    {
        [$id, $version] = $this->approvedFixture();

        DB::table('candidate')->where('id', $id)->update([
            'pii_anonymized_at' => now(),
        ]);

        $service = app(CandidateAvailabilityService::class);

        try {
            $service->markInUse($id, $version);
            $this->fail('anonymized must block markInUse');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_ANONYMIZED']], $exception->errors());
        }

        $this->assertFalse($service->isAvailableAndApproved($id));
        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version,
        ]);
    }

    public function test_mutations_reject_revision_row_with_422(): void
    {
        [$mainId, $mainVersion] = $this->approvedFixture();

        $revisionId = DB::table('candidate')->insertGetId([
            'nama_alphabet' => 'Revision Row',
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => DB::table('candidate')->where('id', $mainId)->value('kewarganegaraan_id'),
            'jenis_kelamin' => 'M',
            'nomor_induk' => null,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => CandidateApprovalStatus::Draft->value,
            'parent_candidate_id' => $mainId,
            'version' => 0,
            'created_by' => DB::table('candidate')->where('id', $mainId)->value('created_by'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(CandidateAvailabilityService::class);

        try {
            $service->markInUse($revisionId, 0);
            $this->fail('revision row must not be marked in use');
        } catch (ValidationException $exception) {
            $this->assertSame(['candidate' => ['CANDIDATE_NOT_MAIN']], $exception->errors());
        }

        $this->assertFalse($service->isAvailableAndApproved($revisionId));
        $this->assertDatabaseHas('candidate', [
            'id' => $mainId,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $mainVersion,
        ]);
    }

    public function test_mark_in_use_rejects_draft_with_422(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $country = $this->seedCountry();

        $this->actingAs($staff);
        $draft = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Draft Only',
            'tanggal_lahir' => '1999-09-09',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);

        $service = app(CandidateAvailabilityService::class);

        try {
            $service->markInUse((int) $draft->id, 0);
            $this->fail('draft must not mark in use');
        } catch (ValidationException $exception) {
            $this->assertSame(['status_ketersediaan' => ['CANDIDATE_NOT_AVAILABLE']], $exception->errors());
        }

        $this->assertFalse($service->isAvailableAndApproved((int) $draft->id));
    }

    public function test_mark_available_on_tersedia_is_noop_without_version_bump(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        $before = DB::table('candidate')->where('id', $id)->first(['version', 'updated_at', 'status_ketersediaan']);
        $this->assertSame(CandidateAvailability::Tersedia->value, $before->status_ketersediaan);
        $this->assertSame($version, (int) $before->version);

        $service->markAvailable($id, $version);

        $after = DB::table('candidate')->where('id', $id)->first(['version', 'updated_at', 'status_ketersediaan']);
        $this->assertSame(CandidateAvailability::Tersedia->value, $after->status_ketersediaan);
        $this->assertSame($version, (int) $after->version);
        $this->assertSame((string) $before->updated_at, (string) $after->updated_at);
        $this->assertTrue($service->isAvailableAndApproved($id));
    }

    public function test_mark_available_stale_version_still_conflicts_when_already_tersedia(): void
    {
        [$id, $version] = $this->approvedFixture();
        $service = app(CandidateAvailabilityService::class);

        try {
            $service->markAvailable($id, $version + 1);
            $this->fail('stale version must conflict even on tersedia no-op path');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $id,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => $version,
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function approvedFixture(): array
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        $country = $this->seedCountry();

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Availability Target',
            'tanggal_lahir' => '2000-03-03',
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

        $this->actingAs($approver);
        $approved = app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pending->getKey(),
            ['version' => (int) $submitted->version],
        );

        $this->assertSame(CandidateApprovalStatus::Disetujui->value, $approved->status_approval);
        $this->assertSame(CandidateAvailability::Tersedia->value, $approved->status_ketersediaan);

        return [(int) $approved->id, (int) $approved->version];
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
