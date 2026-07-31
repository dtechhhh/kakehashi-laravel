<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class CandidateResubmitMainTest extends TestCase
{
    use RefreshDatabase;

    public function test_resubmit_rejected_main_keeps_nik_and_does_not_bump_counter(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Resubmit Main'));

        Queue::fake();
        $submitted = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $pendingRow = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pendingRow->id,
            'perlu perbaikan identitas',
            ['version' => (int) $submitted->version],
        );

        $nikBeforeReject = DB::table('candidate')->where('id', $created->id)->value('nomor_induk');
        $counterBefore = (int) DB::table('nik_counter')->where('year', 2027)->value('last_value');
        $this->assertSame(1, $counterBefore);

        $this->actingAs($staff);
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 2,
            'nama_alphabet' => 'Resubmit Main Edited',
        ]);

        $resubmitted = $submit->resubmitMain($staff, (int) $created->id, [
            'version' => (int) $updated->version,
        ]);

        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanRevisi->value, $resubmitted->status_approval);
        $this->assertSame(4, (int) $resubmitted->version);
        $this->assertSame($nikBeforeReject, $resubmitted->nomor_induk);

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => $nikBeforeReject,
            'status_approval' => CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            'version' => 4,
        ]);

        $this->assertSame($counterBefore, (int) DB::table('nik_counter')->where('year', 2027)->value('last_value'));

        $newPending = DB::table('pending_request')
            ->where('type', PendingType::CANDIDATE_NEW->value)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();
        $this->assertSame((int) $staff->getKey(), (int) $newPending->requested_by);

        $payload = $newPending->payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('aggregate_fingerprint', $payload);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['aggregate_fingerprint']);
        $this->assertSame(
            app(CandidateRevisionService::class)->aggregateFingerprint((int) $created->id),
            $payload['aggregate_fingerprint'],
        );

        $resubmitAudit = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_REVISION_SUBMITTED)
            ->where('entity_id', (int) $created->id)
            ->sole();
        $this->assertSame($staff->getKey(), $resubmitAudit->actor_id);
        $this->assertArrayNotHasKey('nomor_induk', $resubmitAudit->detail);
        $this->assertSame(
            CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            $resubmitAudit->detail['status_approval'],
        );

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_REVISION_SUBMITTED->value,
            'notifiable_id' => $approver->getKey(),
        ]);

        Carbon::setTestNow();
    }

    private function staffInput(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        return $staff;
    }

    private function candidateApprover(): User
    {
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        return $approver;
    }

    private function seedCountry(): int
    {
        return DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(int $country, string $name): array
    {
        return [
            'nama_alphabet' => $name,
            'tanggal_lahir' => '2000-02-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ];
    }
}
