<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidatePhotoService;
use Modules\Candidates\Services\CandidateRevisionService;
use Modules\Candidates\Services\CandidateSubmitService;
use RuntimeException;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Files\FileStorageService;
use Tests\TestCase;

/**
 * W3-T7-FIX1 — shared object_key lifecycle across main / revision photo rows.
 */
class CandidatePhotoRevisionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Test after-commit cleanup against a real PostgreSQL commit. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.disks.r2.driver' => 'local',
            'filesystems.disks.r2.root' => storage_path('framework/testing/r2-rev-'.uniqid('', true)),
            'filesystems.disks.r2.throw' => true,
        ]);
        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_revision_photo_change_keeps_main_object_until_approve_then_cleans_old(): void
    {
        [$staff, $approver, $mainId, $mainKey] = $this->approvedMainWithPhoto();
        $this->actingAs($staff);
        $mainBefore = DB::table('candidate')->where('id', $mainId)->first();
        $this->assertNotNull($mainBefore);

        $mainVersion = (int) DB::table('candidate')->where('id', $mainId)->value('version');
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );

        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $revision->id,
            'object_key' => $mainKey,
        ]);
        $this->assertTrue(app(FileStorageService::class)->exists($mainKey));

        $revVersion = (int) $revision->version;
        $revPhoto = app(CandidatePhotoService::class)->store(
            $staff,
            (int) $revision->id,
            $this->pngUpload('rev.png'),
            $revVersion,
        );
        $revKey = (string) $revPhoto->object_key;

        $this->assertNotSame($mainKey, $revKey);
        $this->assertTrue(app(FileStorageService::class)->exists($mainKey), 'main object must survive revision upload');
        $this->assertTrue(app(FileStorageService::class)->exists($revKey));
        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $mainId,
            'object_key' => $mainKey,
        ]);

        app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version'),
            'nama_alphabet' => 'Revision After Photo',
        ]);
        $revisionFresh = DB::table('candidate')->where('id', $revision->id)->first();
        app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $revision->id,
            ['version' => (int) $revisionFresh->version],
        );

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pending->getKey(),
            ['version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version')],
        );

        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $mainId,
            'object_key' => $revKey,
        ]);
        $this->assertTrue(app(FileStorageService::class)->exists($revKey));
        $this->assertFalse(
            app(FileStorageService::class)->exists($mainKey),
            'old main object cleaned after-commit when unreferenced',
        );
        $mainAfter = DB::table('candidate')->where('id', $mainId)->first();
        $this->assertNotNull($mainAfter);
        $this->assertSame($mainBefore->nomor_induk, $mainAfter->nomor_induk);
        $this->assertSame($mainBefore->status_ketersediaan, $mainAfter->status_ketersediaan);
        $this->assertSame($mainBefore->created_by, $mainAfter->created_by);
        $this->assertSame($mainBefore->approved_by, $mainAfter->approved_by);
    }

    public function test_reject_revision_photo_change_keeps_main_object(): void
    {
        [$staff, $approver, $mainId, $mainKey] = $this->approvedMainWithPhoto();
        $this->actingAs($staff);

        $mainVersion = (int) DB::table('candidate')->where('id', $mainId)->value('version');
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => $mainVersion],
        );

        app(CandidatePhotoService::class)->store(
            $staff,
            (int) $revision->id,
            $this->pngUpload('rev-reject.png'),
            (int) $revision->version,
        );
        $revKey = (string) DB::table('candidate_photo')->where('candidate_id', $revision->id)->value('object_key');

        app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version'),
            'nama_alphabet' => 'Reject Me',
        ]);
        app(CandidateRevisionService::class)->submitRevision(
            $staff,
            (int) $revision->id,
            ['version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version')],
        );

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->reject(
            $approver,
            (int) $pending->getKey(),
            'not good',
            ['version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version')],
        );

        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $mainId,
            'object_key' => $mainKey,
        ]);
        $this->assertTrue(app(FileStorageService::class)->exists($mainKey));
        $this->assertTrue(app(FileStorageService::class)->exists($revKey));
        $this->assertSame(
            CandidateApprovalStatus::Disetujui->value,
            DB::table('candidate')->where('id', $mainId)->value('status_approval'),
        );
    }

    public function test_approval_rollback_after_merge_keeps_main_photo_and_skips_cleanup(): void
    {
        [$staff, $approver, $mainId, $mainKey] = $this->approvedMainWithPhoto();
        $this->actingAs($staff);
        $revision = app(CandidateRevisionService::class)->createRevision(
            $staff,
            $mainId,
            ['version' => (int) DB::table('candidate')->where('id', $mainId)->value('version')],
        );
        $revisionPhoto = app(CandidatePhotoService::class)->store(
            $staff,
            (int) $revision->id,
            $this->pngUpload('rollback.png'),
            (int) $revision->version,
        );
        $revisionKey = (string) $revisionPhoto->object_key;

        app(CandidateDraftService::class)->updateDraft($staff, (int) $revision->id, [
            'version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version'),
            'nama_alphabet' => 'Rollback After Merge',
        ]);
        app(CandidateRevisionService::class)->submitRevision($staff, (int) $revision->id, [
            'version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version')],
        );
        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_REVISION)
            ->where('target_id', $revision->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('notification failed'));
        $this->actingAs($approver);
        try {
            app(CandidateApprovalService::class)->approve(
                $approver,
                (int) $pending->getKey(),
                ['version' => (int) DB::table('candidate')->where('id', $revision->id)->value('version')],
            );
            $this->fail('Expected merge transaction rollback.');
        } catch (RuntimeException $e) {
            $this->assertSame('notification failed', $e->getMessage());
        }

        $this->assertDatabaseHas('candidate_photo', ['candidate_id' => $mainId, 'object_key' => $mainKey]);
        $this->assertTrue(app(FileStorageService::class)->exists($mainKey));
        $this->assertTrue(app(FileStorageService::class)->exists($revisionKey));
        $this->assertSame(
            CandidateApprovalStatus::MenungguTinjauanRevisi->value,
            DB::table('candidate')->where('id', $revision->id)->value('status_approval'),
        );
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: string}
     */
    private function approvedMainWithPhoto(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $approver = User::factory()->active()->create();
        $approver->assignRole(Rbac::CANDIDATE_APPROVER);

        $country = DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff);
        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Photo Main',
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);

        $photo = app(CandidatePhotoService::class)->store(
            $staff,
            (int) $created->id,
            $this->pngUpload('main.png'),
            0,
        );
        $mainKey = (string) $photo->object_key;

        $submitted = app(CandidateSubmitService::class)->submit(
            $staff,
            (int) $created->id,
            ['version' => (int) DB::table('candidate')->where('id', $created->id)->value('version')],
        );

        $pending = PendingRequest::query()
            ->where('type', PendingType::CANDIDATE_NEW)
            ->where('target_id', $created->id)
            ->where('status', PendingStatus::PENDING->value)
            ->sole();

        $this->actingAs($approver);
        app(CandidateApprovalService::class)->approve(
            $approver,
            (int) $pending->getKey(),
            ['version' => (int) $submitted->version],
        );

        return [$staff, $approver, (int) $created->id, $mainKey];
    }

    private function pngUpload(string $name): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function cleanFixtures(): void
    {
        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, notifications, pending_request, nik_counter, candidate, negara RESTART IDENTITY CASCADE',
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
