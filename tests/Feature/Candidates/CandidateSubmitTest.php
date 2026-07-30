<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Exceptions\SimilarityConfirmationRequired;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class CandidateSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_submit_assigns_jst_nik_pending_status_and_notifies_approver(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 12:00:00', 'Asia/Tokyo'));

        $staff = $this->staffInput();
        $approver = $this->candidateApprover();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $service = app(CandidateSubmitService::class);
        $draft = app(CandidateDraftService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Budi Santoso'));

        Queue::fake();
        $submitted = $service->submit($staff, (int) $created->id, ['version' => 0]);

        $this->assertSame('K-2027-00001', $submitted->nomor_induk);
        $this->assertSame(CandidateApprovalStatus::MenungguTinjauanBaru->value, $submitted->status_approval);
        $this->assertSame(1, (int) $submitted->version);
        $this->assertFalse(app(CandidateDraftService::class)->isOperational($submitted));

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => 'K-2027-00001',
            'status_approval' => 'Menunggu Tinjauan-BARU',
            'version' => 1,
        ]);
        $this->assertDatabaseHas('nik_counter', [
            'year' => 2027,
            'last_value' => 1,
        ]);
        $this->assertDatabaseHas('pending_request', [
            'type' => PendingType::CANDIDATE_NEW->value,
            'target_type' => 'candidate',
            'target_id' => $created->id,
            'requested_by' => $staff->getKey(),
            'status' => PendingStatus::PENDING->value,
        ]);
        $this->assertSame(1, DB::table('pending_request')->where('target_id', $created->id)->count());

        $submitAudit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_SUBMITTED)->sole();
        $this->assertSame($staff->getKey(), $submitAudit->actor_id);
        $this->assertSame((int) $created->id, (int) $submitAudit->entity_id);
        $this->assertSame('K-2027-00001', $submitAudit->detail['nomor_induk']);
        $this->assertSame(2027, $submitAudit->detail['jst_year']);

        $this->assertDatabaseHas('notifications', [
            'type' => ActionType::CANDIDATE_SUBMITTED->value,
            'notifiable_id' => $approver->getKey(),
        ]);

        Carbon::setTestNow();
    }

    public function test_sequential_submits_increment_nik_per_jst_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:30:00', 'Asia/Tokyo'));

        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $a = $draft->createDraft($staff, $this->basePayload($country, 'Alpha One'));
        $b = $draft->createDraft($staff, $this->basePayload($country, 'Beta Two'));

        $first = $submit->submit($staff, (int) $a->id, ['version' => 0]);
        $second = $submit->submit($staff, (int) $b->id, ['version' => 0]);

        $this->assertSame('K-2026-00001', $first->nomor_induk);
        $this->assertSame('K-2026-00002', $second->nomor_induk);
        $this->assertDatabaseHas('nik_counter', ['year' => 2026, 'last_value' => 2]);

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:30:00', 'Asia/Tokyo'));
        $c = $draft->createDraft($staff, $this->basePayload($country, 'Gamma Three'));
        $third = $submit->submit($staff, (int) $c->id, ['version' => 0]);

        $this->assertSame('K-2027-00001', $third->nomor_induk);
        $this->assertDatabaseHas('nik_counter', ['year' => 2027, 'last_value' => 1]);

        Carbon::setTestNow();
    }

    public function test_similarity_soft_warning_blocks_until_confirmed_then_audits_override(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $existing = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Budi Santoso'),
            'nama_katakana' => 'ブディサントソ',
            'tanggal_lahir' => '1998-05-10',
        ]);
        // Second draft with near-identical identity fields.
        $twin = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Budi Santoso'),
            'nama_katakana' => 'ブディサントソ',
            'tanggal_lahir' => '1998-05-10',
        ]);

        try {
            $submit->submit($staff, (int) $twin->id, ['version' => 0]);
            $this->fail('Expected SimilarityConfirmationRequired.');
        } catch (SimilarityConfirmationRequired $exception) {
            $this->assertSame('DUP_WARN', $exception->getMessage());
            $ids = array_column($exception->matches, 'candidate_id');
            $this->assertContains((int) $existing->id, $ids);
            foreach ($exception->matches as $match) {
                $this->assertGreaterThanOrEqual(0.4, $match['score']);
            }
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $twin->id,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('pending_request')->where('target_id', $twin->id)->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::SIMILARITY_MATCH_SHOWN)->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_SUBMITTED)->count());

        $submitted = $submit->submit($staff, (int) $twin->id, [
            'version' => 0,
            'confirm_similarity' => true,
        ]);

        $this->assertNotNull($submitted->nomor_induk);
        $this->assertSame('Menunggu Tinjauan-BARU', $submitted->status_approval);
        $this->assertSame(1, DB::table('pending_request')->where('target_id', $twin->id)->count());

        $similarityAudit = AuditLog::query()->where('action_type', ActionType::SIMILARITY_MATCH_SHOWN)->sole();
        $this->assertSame((int) $twin->id, (int) $similarityAudit->detail['candidate_draft_id']);
        $this->assertSame(0.4, $similarityAudit->detail['threshold']);
        $this->assertNotEmpty($similarityAudit->detail['matches']);
        $this->assertContains(
            (int) $existing->id,
            array_column($similarityAudit->detail['matches'], 'candidate_id'),
        );
    }

    public function test_similarity_excludes_anonymized_and_self(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $anon = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Same Person'),
            'tanggal_lahir' => '2001-01-01',
        ]);
        DB::table('candidate')->where('id', $anon->id)->update([
            'pii_anonymized_at' => now(),
        ]);

        $target = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Same Person'),
            'tanggal_lahir' => '2001-01-01',
        ]);

        $submitted = $submit->submit($staff, (int) $target->id, ['version' => 0]);
        $this->assertNotNull($submitted->nomor_induk);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::SIMILARITY_MATCH_SHOWN)->count());
    }

    public function test_similarity_latin_only_at_threshold_triggers_warning(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $existing = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Budi Santoso'),
            'tanggal_lahir' => '1990-04-04',
        ]);
        $twin = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Budi Santoso'),
            'tanggal_lahir' => '1990-04-04',
        ]);

        try {
            $submit->submit($staff, (int) $twin->id, ['version' => 0]);
            $this->fail('Expected latin-only similarity warning.');
        } catch (SimilarityConfirmationRequired $exception) {
            $this->assertContains((int) $existing->id, array_column($exception->matches, 'candidate_id'));
            foreach ($exception->matches as $match) {
                $this->assertGreaterThanOrEqual(0.4, $match['score']);
            }
        }

        $this->assertNull(DB::table('candidate')->where('id', $twin->id)->value('nomor_induk'));
    }

    public function test_similarity_katakana_only_at_threshold_triggers_warning(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $existing = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'ABCDEFGH'),
            'nama_katakana' => 'タナカタロウ',
            'tanggal_lahir' => '1991-06-06',
        ]);
        $twin = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'WXYZPQRS'),
            'nama_katakana' => 'タナカタロウ',
            'tanggal_lahir' => '1991-06-06',
        ]);

        try {
            $submit->submit($staff, (int) $twin->id, ['version' => 0]);
            $this->fail('Expected katakana-only similarity warning.');
        } catch (SimilarityConfirmationRequired $exception) {
            $this->assertContains((int) $existing->id, array_column($exception->matches, 'candidate_id'));
            foreach ($exception->matches as $match) {
                $this->assertGreaterThanOrEqual(0.4, $match['score']);
            }
        }

        $this->assertNull(DB::table('candidate')->where('id', $twin->id)->value('nomor_induk'));
        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_similarity_below_threshold_does_not_warn(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Alice Wonderland'),
            'tanggal_lahir' => '1992-07-07',
        ]);
        $other = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Bob Builder'),
            'tanggal_lahir' => '1992-07-07',
        ]);

        $submitted = $submit->submit($staff, (int) $other->id, ['version' => 0]);
        $this->assertNotNull($submitted->nomor_induk);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::SIMILARITY_MATCH_SHOWN)->count());
    }

    public function test_similarity_requires_matching_dob_and_nationality(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $countryA = $this->seedCountry('ID');
        $countryB = $this->seedCountry('JP', 'Jepang', '日本');
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $draft->createDraft($staff, [
            ...$this->basePayload($countryA, 'Budi Santoso'),
            'tanggal_lahir' => '1993-08-08',
        ]);

        $differentDob = $draft->createDraft($staff, [
            ...$this->basePayload($countryA, 'Budi Santoso'),
            'tanggal_lahir' => '1994-08-08',
        ]);
        $submittedDob = $submit->submit($staff, (int) $differentDob->id, ['version' => 0]);
        $this->assertNotNull($submittedDob->nomor_induk);

        $differentNat = $draft->createDraft($staff, [
            ...$this->basePayload($countryB, 'Budi Santoso'),
            'tanggal_lahir' => '1993-08-08',
        ]);
        $submittedNat = $submit->submit($staff, (int) $differentNat->id, ['version' => 0]);
        $this->assertNotNull($submittedNat->nomor_induk);

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::SIMILARITY_MATCH_SHOWN)->count());
        $this->assertSame(2, DB::table('pending_request')->count());
    }

    public function test_nik_unique_collision_returns_nik_dup_and_rolls_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2028-05-01 09:00:00', 'Asia/Tokyo'));

        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        // Occupy the first NIK of the JST year so allocateNik() collides on unique.
        // Use BARU (not Disetujui) to avoid candidate_maker_checker without inventing approve flow.
        DB::table('candidate')->insert([
            'nama_alphabet' => 'Preoccupied NIK',
            'tanggal_lahir' => '1980-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
            'nomor_induk' => 'K-2028-00001',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => CandidateApprovalStatus::MenungguTinjauanBaru->value,
            'version' => 0,
            'created_by' => $staff->getKey(),
            'approved_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Collision Target'));

        try {
            $submit->submit($staff, (int) $created->id, ['version' => 0]);
            $this->fail('Expected NIK_DUP conflict.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('NIK_DUP', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('pending_request')->where('target_id', $created->id)->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_SUBMITTED)->count());
        // Counter bump is in the same transaction and must roll back with NIK_DUP.
        $this->assertSame(0, DB::table('nik_counter')->count());

        Carbon::setTestNow();
    }

    public function test_draft_path_still_never_assigns_nik_or_pending(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Draft Gate'));
        $updated = $draft->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'nama_alphabet' => 'Draft Gate Updated',
        ]);

        $this->assertNull($updated->nomor_induk);
        $this->assertSame('Draft', $updated->status_approval);
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
    }

    public function test_double_submit_is_rejected_without_second_pending_or_nik(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Once Only'));
        $first = $submit->submit($staff, (int) $created->id, ['version' => 0]);

        $this->assertValidationCode(
            fn () => $submit->submit($staff, (int) $created->id, ['version' => 1]),
            'CANDIDATE_NOT_DRAFT',
        );

        $this->assertSame(1, DB::table('pending_request')->where('target_id', $created->id)->count());
        $this->assertSame($first->nomor_induk, DB::table('candidate')->where('id', $created->id)->value('nomor_induk'));
        $this->assertSame(1, (int) DB::table('nik_counter')->value('last_value'));
    }

    public function test_stale_version_submit_returns_conflict(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Stale Submit'));
        $draft->updateDraft($staff, (int) $created->id, [
            'version' => 0,
            'phone' => '08111',
        ]);

        try {
            $submit->submit($staff, (int) $created->id, ['version' => 0]);
            $this->fail('Expected CONFLICT.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
            'version' => 1,
        ]);
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
    }

    public function test_approver_cannot_submit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $country = $this->seedCountry();
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, $this->basePayload($country, 'No Approver Submit'));

        $this->actingAs($approver);
        $this->expectException(AuthorizationException::class);
        app(CandidateSubmitService::class)->submit($approver, (int) $created->id, ['version' => 0]);
    }

    public function test_audit_failure_rolls_back_nik_pending_and_counter_gap_allowed(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $created = $draft->createDraft($staff, $this->basePayload($country, 'Rollback Submit'));

        AuditLog::creating(function ($model): void {
            if ($model->action_type === ActionType::CANDIDATE_SUBMITTED) {
                throw new \RuntimeException('submit audit failed');
            }
        });

        try {
            $submit->submit($staff, (int) $created->id, ['version' => 0]);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('submit audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $created->id,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
            'version' => 0,
        ]);
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_SUBMITTED)->count());
        // Gap allowed if counter advanced then rolled back — either 0 rows or last_value unused is OK.
        // Our UPSERT is in the same transaction so counter row must also roll back.
        $this->assertSame(0, DB::table('nik_counter')->count());
    }

    public function test_similarity_audit_failure_rolls_back_before_nik(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $draft = app(CandidateDraftService::class);
        $submit = app(CandidateSubmitService::class);

        $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Clone Name'),
            'tanggal_lahir' => '1995-05-05',
        ]);
        $twin = $draft->createDraft($staff, [
            ...$this->basePayload($country, 'Clone Name'),
            'tanggal_lahir' => '1995-05-05',
        ]);

        AuditLog::creating(function ($model): void {
            if ($model->action_type === ActionType::SIMILARITY_MATCH_SHOWN) {
                throw new \RuntimeException('similarity audit failed');
            }
        });

        try {
            $submit->submit($staff, (int) $twin->id, [
                'version' => 0,
                'confirm_similarity' => true,
            ]);
            $this->fail('Expected similarity audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('similarity audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('candidate', [
            'id' => $twin->id,
            'nomor_induk' => null,
            'status_approval' => 'Draft',
        ]);
        $this->assertSame(0, DB::table('pending_request')->count());
        $this->assertSame(0, DB::table('nik_counter')->count());
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

    private function seedCountry(string $code = 'ID', string $labelId = 'Indonesia', string $labelJa = 'インドネシア'): int
    {
        return DB::table('negara')->insertGetId([
            'code' => $code,
            'label_id' => $labelId,
            'label_ja' => $labelJa,
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

    private function assertValidationCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected validation failure containing [{$code}].");
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $blob = collect($errors)->map(
                static fn (array $messages, string $field): string => $field.' '.implode(' ', $messages)
            )->implode(' | ');
            $this->assertStringContainsString($code, $blob);
        }
    }
}
