<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidatePhotoService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Files\FileStorageService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * W3-T7 / FIX1 — photo R2 path vs documents Drive URL (no document upload to R2).
 */
class CandidateFileSplitTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Test after-commit cleanup against a real PostgreSQL commit. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'filesystems.disks.r2.driver' => 'local',
            'filesystems.disks.r2.root' => storage_path('framework/testing/r2-'.uniqid('', true)),
            'filesystems.disks.r2.throw' => true,
        ]);
        $this->cleanFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_r2_disk_config_has_checksum_when_required_and_retain_visibility_false(): void
    {
        $disk = config('filesystems.disks.r2');
        $this->assertIsArray($disk);
        $this->assertSame('when_required', $disk['request_checksum_calculation']);
        $this->assertSame('when_required', $disk['response_checksum_validation']);
        $this->assertFalse($disk['retain_visibility']);
    }

    public function test_staff_can_upload_valid_photo_to_r2_with_audit_and_version_bump(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);

        $photo = app(CandidatePhotoService::class)->store(
            $staff,
            $candidateId,
            $this->pngUpload('face.png'),
            0,
        );

        $this->assertNotNull($photo->object_key);
        $this->assertSame('image/png', $photo->mime_type);
        $this->assertGreaterThan(0, (int) $photo->size_bytes);
        $this->assertTrue(app(FileStorageService::class)->exists((string) $photo->object_key));

        $fresh = DB::table('candidate')->where('id', $candidateId)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(1, (int) $fresh->version, 'caller reloads Candidate for its new version');

        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->sole();
        $this->assertSame($staff->getKey(), $audit->actor_id);
        $this->assertSame($candidateId, $audit->detail['candidate_id']);
        $this->assertSame('image/png', $audit->detail['mime']);
    }

    public function test_temporary_url_returns_signed_style_url_for_viewer(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        app(CandidatePhotoService::class)->store($staff, $candidateId, $this->pngUpload(), 0);

        $url = app(CandidatePhotoService::class)->temporaryUrl($staff, $candidateId);

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        preg_match('/expires=(\d+)/', $url, $m);
        $ttl = (int) $m[1] - now()->getTimestamp();
        $this->assertGreaterThanOrEqual(FileStorageService::MIN_SIGNED_TTL_SECONDS - 5, $ttl);
        $this->assertLessThanOrEqual(FileStorageService::MAX_SIGNED_TTL_SECONDS + 5, $ttl);
    }

    public function test_rejects_fake_mime_oversized_and_corrupt_png(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);

        try {
            $service->store($staff, $candidateId, UploadedFile::fake()->createWithContent(
                'evil.jpg',
                'not-an-image-payload',
            ), 0);
            $this->fail('Expected MIME rejection.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_PHOTO_MIME_INVALID'], $e->errors()['photo'] ?? null);
        }

        try {
            $service->store($staff, $candidateId, UploadedFile::fake()->createWithContent(
                'big.png',
                $this->minimalPng().str_repeat('x', 5 * 1024 * 1024 + 1),
            ), 0);
            $this->fail('Expected size rejection.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_PHOTO_TOO_LARGE'], $e->errors()['photo'] ?? null);
        }

        try {
            $service->store($staff, $candidateId, UploadedFile::fake()->createWithContent(
                'trunc.png',
                $this->truncatedPng(),
            ), 0);
            $this->fail('Expected corrupt rejection.');
        } catch (ValidationException $e) {
            $this->assertSame(['CANDIDATE_PHOTO_CORRUPT'], $e->errors()['photo'] ?? null);
        }

        $this->assertSame(0, DB::table('candidate_photo')->where('candidate_id', $candidateId)->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
        $this->assertSame(0, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame([], Storage::disk(FileStorageService::DISK)->allFiles());
    }

    public function test_photo_larger_than_1024px_is_resized(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);

        $photo = app(CandidatePhotoService::class)->store(
            $staff,
            $candidateId,
            UploadedFile::fake()->createWithContent('large.png', $this->largePng(2000, 1500)),
            0,
        );

        $bytes = Storage::disk(FileStorageService::DISK)->get((string) $photo->object_key);
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(1024, max($info[0], $info[1]));
        $this->assertSame(1024, $info[0]);
        $this->assertSame(768, $info[1]);
        $this->assertSame('image/png', $info['mime']);
    }

    public function test_unauthorized_and_actor_mismatch_cannot_upload_or_sign(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        app(CandidatePhotoService::class)->store($staff, $candidateId, $this->pngUpload(), 0);

        $outsider = User::factory()->active()->create();
        $outsider->assignRole(Rbac::JOB_MANAGER);

        try {
            app(CandidatePhotoService::class)->store($outsider, $candidateId, $this->pngUpload(), 1);
            $this->fail('Expected actor mismatch for store.');
        } catch (AuthorizationException $e) {
            $this->assertSame('CANDIDATE_ACTOR_MISMATCH', $e->getMessage());
        }

        $this->actingAs($outsider);
        try {
            app(CandidatePhotoService::class)->store($staff, $candidateId, $this->pngUpload(), 1);
            $this->fail('Expected mismatch when session is outsider.');
        } catch (AuthorizationException $e) {
            $this->assertSame('CANDIDATE_ACTOR_MISMATCH', $e->getMessage());
        }

        try {
            app(CandidatePhotoService::class)->temporaryUrl($staff, $candidateId);
            $this->fail('Expected mismatch on temporaryUrl.');
        } catch (AuthorizationException $e) {
            $this->assertSame('CANDIDATE_ACTOR_MISMATCH', $e->getMessage());
        }

        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
        $this->assertSame(1, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(1, DB::table('candidate_photo')->where('candidate_id', $candidateId)->count());
        $this->assertCount(1, Storage::disk(FileStorageService::DISK)->allFiles());
    }

    public function test_stale_version_conflicts_without_partial_write(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);
        $first = $service->store($staff, $candidateId, $this->pngUpload('a.png'), 0);
        $oldKey = (string) $first->object_key;

        try {
            $service->store($staff, $candidateId, $this->pngUpload('b.png'), 0);
            $this->fail('Expected 409 on stale version.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('CONFLICT', $e->getMessage());
        }

        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $candidateId,
            'object_key' => $oldKey,
        ]);
        $this->assertTrue(app(FileStorageService::class)->exists($oldKey));
        $this->assertSame(1, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
    }

    public function test_audit_failure_rolls_back_and_removes_new_object_keeps_old(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);
        $first = $service->store($staff, $candidateId, $this->pngUpload('keep.png'), 0);
        $oldKey = (string) $first->object_key;

        AuditLog::creating(function ($model): void {
            if ($model->action_type === ActionType::CANDIDATE_PHOTO_UPLOADED) {
                throw new \RuntimeException('photo audit failed');
            }
        });

        try {
            $service->store($staff, $candidateId, $this->pngUpload('new.png'), 1);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('photo audit failed', $e->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('candidate_photo', [
            'candidate_id' => $candidateId,
            'object_key' => $oldKey,
        ]);
        $this->assertTrue(app(FileStorageService::class)->exists($oldKey));
        $this->assertSame(1, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
        $files = Storage::disk(FileStorageService::DISK)->allFiles();
        $this->assertCount(1, $files);
    }

    public function test_cleanup_query_failure_is_reported_without_losing_current_photo(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);
        $first = $service->store($staff, $candidateId, $this->pngUpload('old-query.png'), 0);
        $oldKey = (string) $first->object_key;
        $connection = DB::connection();
        $throwOnce = true;

        DB::listen(function (QueryExecuted $query) use (&$throwOnce, $connection): void {
            if (! $throwOnce
                || $query->connection !== $connection
                || $connection->transactionLevel() !== 0
                || ! str_contains($query->sql, 'candidate_photo')
                || ! str_contains($query->sql, 'exists')) {
                return;
            }

            $throwOnce = false;

            throw new \RuntimeException('old photo cleanup query failed');
        });

        $second = $service->store($staff, $candidateId, $this->pngUpload('new-query.png'), 1);
        $currentKey = (string) $second->object_key;

        $this->assertFalse($throwOnce, 'The failure must be raised from old-key cleanup after commit.');
        $this->assertDatabaseHas('candidate_photo', ['candidate_id' => $candidateId, 'object_key' => $currentKey]);
        $this->assertTrue(app(FileStorageService::class)->exists($currentKey));
        $this->assertTrue(app(FileStorageService::class)->exists($oldKey), 'Old key may remain orphaned.');
        $this->assertSame(2, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(2, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
    }

    public function test_cleanup_delete_failure_is_reported_without_losing_current_photo(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);
        $first = $service->store($staff, $candidateId, $this->pngUpload('old-delete.png'), 0);
        $oldKey = (string) $first->object_key;
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $this->assertNotNull($events);
        $replaceStorageOnce = true;
        $storage = Storage::getFacadeRoot();
        $brokenDisk = \Mockery::mock(Filesystem::class);
        $brokenDisk->shouldReceive('delete')->once()->with($oldKey)->andThrow(
            new \RuntimeException('old photo cleanup delete failed'),
        );
        $brokenStorage = \Mockery::mock();
        $brokenStorage->shouldReceive('disk')->once()->with(FileStorageService::DISK)->andReturn($brokenDisk);

        $events->listen(TransactionBeginning::class, function (TransactionBeginning $event) use (
            &$replaceStorageOnce,
            $connection,
            $brokenStorage,
        ): void {
            if (! $replaceStorageOnce || $event->connection !== $connection) {
                return;
            }

            $replaceStorageOnce = false;
            $event->connection->afterCommit(function () use ($brokenStorage): void {
                Storage::swap($brokenStorage);
            });
        });

        try {
            $second = $service->store($staff, $candidateId, $this->pngUpload('new-delete.png'), 1);
        } finally {
            Storage::swap($storage);
        }
        $currentKey = (string) $second->object_key;

        $this->assertFalse($replaceStorageOnce, 'The failure must be raised from old-key cleanup after commit.');
        $this->assertDatabaseHas('candidate_photo', ['candidate_id' => $candidateId, 'object_key' => $currentKey]);
        $this->assertTrue(app(FileStorageService::class)->exists($currentKey));
        $this->assertTrue(app(FileStorageService::class)->exists($oldKey), 'Old key may remain orphaned.');
        $this->assertSame(2, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(2, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
    }

    public function test_independent_after_commit_failure_does_not_delete_committed_current_photo(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);
        $service->store($staff, $candidateId, $this->pngUpload('old-independent.png'), 0);
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $this->assertNotNull($events);
        $registerFailureOnce = true;

        $events->listen(TransactionBeginning::class, function (TransactionBeginning $event) use (
            &$registerFailureOnce,
            $connection,
        ): void {
            if (! $registerFailureOnce || $event->connection !== $connection) {
                return;
            }

            $registerFailureOnce = false;
            $event->connection->afterCommit(static function (): void {
                throw new \RuntimeException('independent after-commit failure');
            });
        });

        try {
            $service->store($staff, $candidateId, $this->pngUpload('new-independent.png'), 1);
            $this->fail('Expected independent after-commit exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('independent after-commit failure', $e->getMessage());
        }

        $current = DB::table('candidate_photo')->where('candidate_id', $candidateId)->sole();
        $currentKey = (string) $current->object_key;

        $this->assertFalse($registerFailureOnce, 'The independent callback must run inside store transaction.');
        $this->assertTrue(app(FileStorageService::class)->exists($currentKey));
        $this->assertSame(2, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
        $this->assertSame(2, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
    }

    public function test_documents_stay_drive_urls_and_are_not_put_on_r2(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $country = $this->seedCountry();
        $docType = $this->seedLookup('jenis_dokumen', 'KTP');

        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Drive Only',
            'tanggal_lahir' => '1998-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
            'documents' => [[
                'jenis_dokumen_id' => $docType,
                'url_dokumen' => 'https://drive.google.com/file/d/doc-only/view',
            ]],
        ]);

        $this->assertDatabaseHas('candidate_document', [
            'candidate_id' => $created->id,
            'url_dokumen' => 'https://drive.google.com/file/d/doc-only/view',
        ]);
        $this->assertSame([], Storage::disk(FileStorageService::DISK)->allFiles());
    }

    public function test_replace_photo_updates_metadata_and_removes_unreferenced_old_object_after_commit(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);
        $service = app(CandidatePhotoService::class);

        $first = $service->store($staff, $candidateId, $this->pngUpload('a.png'), 0);
        $oldKey = (string) $first->object_key;

        $second = $service->store($staff, $candidateId, $this->pngUpload('b.png'), 1);
        $this->assertNotSame($oldKey, (string) $second->object_key);
        $this->assertTrue(app(FileStorageService::class)->exists((string) $second->object_key));
        $this->assertFalse(app(FileStorageService::class)->exists($oldKey));
        $this->assertSame(1, DB::table('candidate_photo')->where('candidate_id', $candidateId)->count());
        $this->assertSame(2, (int) DB::table('candidate')->where('id', $candidateId)->value('version'));
    }

    public function test_deleted_anonymized_and_noneditable_candidates_reject_upload_without_side_effects(): void
    {
        $staff = $this->staffInput();
        $this->actingAs($staff);
        $candidateId = $this->createDraftId($staff);

        foreach ([
            ['deleted_at' => now()],
            ['pii_anonymized_at' => now()],
            ['status_approval' => CandidateApprovalStatus::MenungguTinjauanBaru->value],
        ] as $state) {
            DB::table('candidate')->where('id', $candidateId)->update($state);

            try {
                app(CandidatePhotoService::class)->store($staff, $candidateId, $this->pngUpload(), 0);
                $this->fail('Expected candidate state rejection.');
            } catch (ValidationException) {
                // expected
            }

            $this->assertSame(0, DB::table('candidate_photo')->where('candidate_id', $candidateId)->count());
            $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count());
            $this->assertSame([], Storage::disk(FileStorageService::DISK)->allFiles());
            DB::table('candidate')->where('id', $candidateId)->update([
                'deleted_at' => null,
                'pii_anonymized_at' => null,
                'status_approval' => CandidateApprovalStatus::Draft->value,
            ]);
        }
    }

    private function createDraftId(User $staff): int
    {
        $row = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Photo Person',
            'tanggal_lahir' => '1997-03-03',
            'kewarganegaraan_id' => $this->seedCountry(),
            'jenis_kelamin' => 'M',
        ]);

        $this->assertSame(CandidateApprovalStatus::Draft->value, $row->status_approval);
        $this->assertSame(CandidateAvailability::Tersedia->value, $row->status_ketersediaan);

        return (int) $row->id;
    }

    private function pngUpload(string $name = 'face.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->minimalPng());
    }

    private function minimalPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    /** Valid PNG signature + IHDR but truncated body — finfo may still say image/png. */
    private function truncatedPng(): string
    {
        $png = $this->minimalPng();

        return substr($png, 0, 20);
    }

    private function largePng(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($im, 10, 20, 30);
        imagefill($im, 0, 0, $bg);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function staffInput(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        return $staff;
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

    private function seedLookup(string $table, string $code): int
    {
        return DB::table($table)->insertGetId([
            'code' => $code,
            'label_id' => $code,
            'label_ja' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cleanFixtures(): void
    {
        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, notifications, pending_request, nik_counter, candidate, negara, jenis_dokumen RESTART IDENTITY CASCADE',
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
