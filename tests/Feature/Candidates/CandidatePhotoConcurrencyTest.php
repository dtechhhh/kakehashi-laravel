<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidatePhotoService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Files\FileStorageService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * W3-T7-FIX1 — concurrent photo upload races (optimistic version only).
 */
class CandidatePhotoConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    private string $r2Root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r2Root = storage_path('framework/testing/r2-conc-'.uniqid('', true));
        config([
            'filesystems.disks.r2.driver' => 'local',
            'filesystems.disks.r2.root' => $this->r2Root,
            'filesystems.disks.r2.throw' => true,
        ]);
        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();
        parent::tearDown();
    }

    public function test_parallel_photo_uploads_yield_one_success_and_one_conflict(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);

        $country = DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Photo',
            'tanggal_lahir' => '2001-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkPhotoStore((int) $staff->getKey(), (int) $created->id, 0, 'a.png', $startAt),
            $this->forkPhotoStore((int) $staff->getKey(), (int) $created->id, 0, 'b.png', $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');
        config([
            'filesystems.disks.r2.driver' => 'local',
            'filesystems.disks.r2.root' => $this->r2Root,
            'filesystems.disks.r2.throw' => true,
        ]);

        $this->assertSame([0, 10], $exitCodes, 'exactly one upload succeeds, one CONFLICT');
        $this->assertSame(1, (int) DB::table('candidate')->where('id', $created->id)->value('version'));
        $this->assertSame(1, DB::table('candidate_photo')->where('candidate_id', $created->id)->count());
        $this->assertSame(
            1,
            AuditLog::query()->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)->count(),
        );
        $this->assertCount(1, app(FileStorageService::class)->disk()->allFiles());
    }

    public function test_upload_vs_submit_race_one_success_one_conflict(): void
    {
        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);

        $country = DB::table('negara')->insertGetId([
            'code' => 'JP',
            'label_id' => 'Japan',
            'label_ja' => '日本',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Upload Vs Submit',
            'tanggal_lahir' => '1999-09-09',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'F',
        ]);

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkPhotoStore((int) $staff->getKey(), (int) $created->id, 0, 'race.png', $startAt),
            $this->forkSubmit((int) $staff->getKey(), (int) $created->id, 0, $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');
        $this->assertTrue(
            $exitCodes === [0, 10] || $exitCodes === [0, 11],
            'loser is CONFLICT (409) or non-editable state (422), depending on commit order',
        );

        $row = DB::table('candidate')->where('id', $created->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->version);
        $photoCount = DB::table('candidate_photo')->where('candidate_id', $created->id)->count();
        $photoAuditCount = AuditLog::query()
            ->where('action_type', ActionType::CANDIDATE_PHOTO_UPLOADED)
            ->count();
        $this->assertSame($photoCount, $photoAuditCount);
        $this->assertSame($photoCount, count(app(FileStorageService::class)->disk()->allFiles()));
        $this->assertContains($photoCount, [0, 1]);
    }

    private function forkPhotoStore(int $actorId, int $candidateId, int $version, string $name, float $startAt): int
    {
        $pid = pcntl_fork();
        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            config([
                'filesystems.disks.r2.driver' => 'local',
                'filesystems.disks.r2.root' => $this->r2Root,
                'filesystems.disks.r2.throw' => true,
            ]);

            $actor = User::query()->findOrFail($actorId);
            Auth::login($actor);

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(CandidatePhotoService::class)->store(
                $actor,
                $candidateId,
                $this->pngUpload($name),
                $version,
            );
            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (ValidationException $exception) {
            exit(($exception->errors()['status_approval'] ?? null) === ['CANDIDATE_PHOTO_NOT_EDITABLE'] ? 11 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function forkSubmit(int $actorId, int $candidateId, int $version, float $startAt): int
    {
        $pid = pcntl_fork();
        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $actor = User::query()->findOrFail($actorId);
            Auth::login($actor);

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            app(CandidateSubmitService::class)->submit($actor, $candidateId, ['version' => $version]);
            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
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
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame(
            '',
            $migrator,
            'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).',
        );

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, pending_request, notifications, candidate_photo, candidate, negara, '
            .'model_has_roles, model_has_permissions, users RESTART IDENTITY CASCADE'
        );
    }
}
