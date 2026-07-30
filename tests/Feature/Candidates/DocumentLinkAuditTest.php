<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateDraftService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Files\DocumentLinkAuditService;
use Tests\TestCase;

/**
 * W3-T7 gate — IDENTITY_DOC_VIEWED before Drive URL is disclosed.
 */
class DocumentLinkAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_reveal_link_returns_drive_url_and_writes_identity_doc_viewed_audit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId, $url] = $this->seedCandidateWithDocument($staff);

        $revealed = app(DocumentLinkAuditService::class)->revealLink(
            $candidateId,
            $documentId,
            $staff->getKey(),
        );

        $this->assertSame($url, $revealed);

        $audit = AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->sole();
        $this->assertSame($staff->getKey(), $audit->actor_id);
        $this->assertSame('candidate', $audit->entity_type);
        $this->assertSame($candidateId, (int) $audit->entity_id);
        $this->assertSame($candidateId, $audit->detail['candidate_id']);
        $this->assertSame($documentId, $audit->detail['candidate_document_id']);
        $this->assertSame('KTP', $audit->detail['doc_type']);
        $this->assertSame(Rbac::STAFF_INPUT, $audit->detail['viewer_role']);
        $this->assertStringNotContainsString('drive.google.com', json_encode($audit->detail));
    }

    public function test_approver_can_reveal_with_viewer_role_snapshot(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId, $url] = $this->seedCandidateWithDocument($staff);

        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);
        $this->actingAs($approver);

        $revealed = app(DocumentLinkAuditService::class)->revealLink(
            $candidateId,
            $documentId,
            $approver->getKey(),
        );

        $this->assertSame($url, $revealed);
        $audit = AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->sole();
        $this->assertSame(Rbac::CANDIDATE_APPROVER, $audit->detail['viewer_role']);
        $this->assertSame($approver->getKey(), $audit->actor_id);
    }

    public function test_reveal_without_view_permission_is_forbidden_without_audit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId] = $this->seedCandidateWithDocument($staff);

        $outsider = User::factory()->active()->create();
        $outsider->assignRole(Rbac::JOB_MANAGER);
        $this->actingAs($outsider);

        try {
            app(DocumentLinkAuditService::class)->revealLink(
                $candidateId,
                $documentId,
                $outsider->getKey(),
            );
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->count());
    }

    public function test_reveal_actor_id_must_match_session(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId] = $this->seedCandidateWithDocument($staff);

        $outsider = User::factory()->active()->create();
        $outsider->assignRole(Rbac::JOB_MANAGER);
        $this->actingAs($outsider);

        try {
            app(DocumentLinkAuditService::class)->revealLink(
                $candidateId,
                $documentId,
                $staff->getKey(),
            );
            $this->fail('Expected actor mismatch.');
        } catch (AuthorizationException $e) {
            $this->assertSame('CANDIDATE_ACTOR_MISMATCH', $e->getMessage());
        }

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->count());
    }

    public function test_reveal_mismatched_document_does_not_leak_and_skips_audit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId] = $this->seedCandidateWithDocument($staff);
        $otherId = $this->createBareDraftId($staff);

        try {
            app(DocumentLinkAuditService::class)->revealLink(
                $otherId,
                $documentId,
                $staff->getKey(),
            );
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_DOCUMENT_NOT_FOUND'], $e->errors()['candidate_document'] ?? null);
        }

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->count());
    }

    public function test_reveal_missing_document_is_validation_error_without_audit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createBareDraftId($staff);

        try {
            app(DocumentLinkAuditService::class)->revealLink($candidateId, 999_999, $staff->getKey());
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_DOCUMENT_NOT_FOUND'], $e->errors()['candidate_document'] ?? null);
        }

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->count());
    }

    public function test_reveal_anonymized_candidate_does_not_leak_or_audit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        [$candidateId, $documentId] = $this->seedCandidateWithDocument($staff);
        DB::table('candidate')->where('id', $candidateId)->update(['pii_anonymized_at' => now()]);

        try {
            app(DocumentLinkAuditService::class)->revealLink($candidateId, $documentId, $staff->getKey());
            $this->fail('Expected inaccessible candidate validation.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_NOT_ACCESSIBLE'], $e->errors()['candidate'] ?? null);
        }

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED)->count());
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function seedCandidateWithDocument(User $staff): array
    {
        $country = $this->seedCountry();
        $docType = $this->seedLookup('jenis_dokumen', 'KTP');
        $url = 'https://drive.google.com/file/d/sensitive-ktp/view';

        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Doc Subject',
            'tanggal_lahir' => '1995-06-15',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'F',
            'documents' => [[
                'jenis_dokumen_id' => $docType,
                'url_dokumen' => $url,
                'nama_file' => 'ktp.pdf',
            ]],
        ]);

        $documentId = (int) DB::table('candidate_document')
            ->where('candidate_id', $created->id)
            ->value('id');

        return [(int) $created->id, $documentId, $url];
    }

    private function createBareDraftId(User $staff): int
    {
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Other Draft',
            'tanggal_lahir' => '1990-01-01',
            'kewarganegaraan_id' => $this->seedCountry('JP'),
            'jenis_kelamin' => 'M',
        ]);

        return (int) $created->id;
    }

    private function staffInput(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        return $staff;
    }

    private function seedCountry(string $code = 'ID'): int
    {
        $existing = DB::table('negara')->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('negara')->insertGetId([
            'code' => $code,
            'label_id' => $code,
            'label_ja' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedLookup(string $table, string $code): int
    {
        $existing = DB::table($table)->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table($table)->insertGetId([
            'code' => $code,
            'label_id' => $code,
            'label_ja' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
