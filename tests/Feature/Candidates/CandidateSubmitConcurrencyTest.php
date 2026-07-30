<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * Concurrent first-submit of two different drafts in the same JST year:
 * both succeed with unique NIK, counter=2, one pending each.
 */
class CandidateSubmitConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** Forked workers need committed rows visible outside the parent transaction. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_parallel_submit_of_two_drafts_yields_unique_niks_and_two_pendings(): void
    {
        Carbon::setTestNow(Carbon::parse('2029-06-15 10:00:00', 'Asia/Tokyo'));

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

        $draft = app(CandidateDraftService::class);
        $a = $draft->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Alpha',
            'tanggal_lahir' => '2001-01-01',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
        ]);
        $b = $draft->createDraft($staff, [
            'nama_alphabet' => 'Concurrent Beta',
            'tanggal_lahir' => '2002-02-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'F',
        ]);

        $startAt = microtime(true) + 0.5;
        $pids = [
            $this->forkSubmit((int) $staff->getKey(), (int) $a->id, 0, $startAt),
            $this->forkSubmit((int) $staff->getKey(), (int) $b->id, 0, $startAt),
        ];

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        DB::purge('pgsql');

        $this->assertSame([0, 0], $exitCodes, 'both concurrent submits of distinct drafts must succeed');

        $rows = DB::table('candidate')
            ->whereIn('id', [$a->id, $b->id])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $niks = $rows->pluck('nomor_induk')->all();
        $this->assertCount(2, array_unique($niks));
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^K-2029-\d{5}$/', (string) $row->nomor_induk);
            $this->assertSame('Menunggu Tinjauan-BARU', $row->status_approval);
            $this->assertSame(1, (int) $row->version);
            $this->assertSame(
                1,
                DB::table('pending_request')
                    ->where('target_type', 'candidate')
                    ->where('target_id', $row->id)
                    ->where('type', PendingType::CANDIDATE_NEW->value)
                    ->where('status', PendingStatus::PENDING->value)
                    ->count(),
            );
        }

        $this->assertDatabaseHas('nik_counter', [
            'year' => 2029,
            'last_value' => 2,
        ]);
        $this->assertSame(2, DB::table('pending_request')->count());
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

            // Child process must use the same frozen JST year as the parent fixture.
            Carbon::setTestNow(Carbon::parse('2029-06-15 10:00:00', 'Asia/Tokyo'));

            app(CandidateSubmitService::class)->submit($actor, $candidateId, [
                'version' => $version,
            ]);

            exit(0);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame(
            '',
            $migrator,
            'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).'
        );

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, pending_request, notifications, nik_counter, candidate, negara, '
            .'model_has_roles, model_has_permissions, users '
            .'RESTART IDENTITY CASCADE'
        );
    }
}
