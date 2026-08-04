<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\Jobs\Services\InterviewParticipationService;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * W4-T3 — two bulk pulls for the same candidate serialize on the candidate row.
 */
class InterviewParticipationPullConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** Forked workers need committed rows visible outside the parent transaction. */
    protected array $connectionsToTransact = [];

    private int $actorId;

    private int $containerId;

    private int $secondContainerId;

    private int $candidateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);

        $actor = User::factory()->active()->create();
        $actor->assignRole(Rbac::ASSISTANT_MANAGER);
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);
        $this->actorId = (int) $actor->id;

        $countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W4 Pull Race Company',
            'nama_romaji' => 'W4 Pull Race',
            'nama_id' => 'Perusahaan Pull Race',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W4_PULL_RACE_POSITION',
            'label_id' => 'Posisi Pull Race',
            'label_ja' => '競合ポジション',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W4_PULL_RACE_VISA',
            'label_id' => 'Visa Pull Race',
            'label_ja' => '競合ビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $container = [
            'judul' => 'W4 Pull Race Container',
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 1,
            'deskripsi' => 'Synthetic concurrency fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $actor->id,
            'disetujui_oleh' => $checker->id,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ];
        $this->containerId = (int) DB::table('interview_container')->insertGetId($container);
        $container['judul'] = 'W4 Pull Race Container 2';
        $this->secondContainerId = (int) DB::table('interview_container')->insertGetId($container);
        $this->candidateId = (int) DB::table('candidate')->insertGetId([
            'nomor_induk' => 'K-2026-00001',
            'nama_alphabet' => 'W4 Pull Race Candidate',
            'tanggal_lahir' => '2000-01-01',
            'kewarganegaraan_id' => $countryId,
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'status_approval' => 'Disetujui',
            'version' => 0,
            'created_by' => $actor->id,
            'approved_by' => $checker->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_parallel_pulls_yield_one_success_and_one_conflict(): void
    {
        [$lockHolderPid, $releaseSocket] = $this->startCandidateLockHolder();

        $startAt = microtime(true) + 0.1;
        $pids = [
            $this->forkPull($startAt, $this->containerId),
            $this->forkPull($startAt, $this->secondContainerId),
        ];

        // The separate lock holder forces both workers to reach the same
        // candidate lock before either can commit the pull.
        usleep(2_000_000);
        fwrite($releaseSocket, 'R');
        fclose($releaseSocket);

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);

        pcntl_waitpid($lockHolderPid, $holderStatus);
        DB::purge('pgsql_migrator');
        DB::purge('pgsql');

        $this->assertSame([0, 10], $exitCodes);
        $this->assertSame(1, DB::table('participation')
            ->where('candidate_id', $this->candidateId)
            ->count());
        $this->assertDatabaseHas('candidate', [
            'id' => $this->candidateId,
            'status_ketersediaan' => CandidateAvailability::SedangDipakai->value,
            'version' => 1,
        ]);
    }

    /** @return array{0: int, 1: resource} */
    private function startCandidateLockHolder(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($sockets === false) {
            throw new \RuntimeException('Unable to create concurrency barrier.');
        }

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork concurrency barrier.');
        }

        if ($pid === 0) {
            fclose($parentSocket);

            try {
                DB::purge('pgsql');
                DB::purge('pgsql_migrator');
                $connection = DB::connection('pgsql_migrator');
                $connection->beginTransaction();
                $connection->table('candidate')
                    ->where('id', $this->candidateId)
                    ->lockForUpdate()
                    ->first();

                fwrite($childSocket, 'L');
                fread($childSocket, 1);
                $connection->commit();
                fclose($childSocket);
                exit(0);
            } catch (Throwable) {
                @fwrite($childSocket, 'E');
                exit(20);
            }
        }

        fclose($childSocket);
        stream_set_blocking($parentSocket, true);
        $signal = fread($parentSocket, 1);
        if ($signal !== 'L') {
            fclose($parentSocket);
            pcntl_waitpid($pid, $status);
            throw new \RuntimeException('Concurrency barrier failed.');
        }

        return [$pid, $parentSocket];
    }

    private function forkPull(float $startAt, int $containerId): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            $actor = User::query()->findOrFail($this->actorId);
            auth()->login($actor);
            app(InterviewParticipationService::class)->pull(
                $actor,
                $containerId,
                [$this->candidateId],
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (ValidationException $exception) {
            $code = $exception->errors()['candidate'][0] ?? '';
            exit($code === 'CANDIDATE_NOT_AVAILABLE' ? 11 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame('', $migrator);

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE participation, interview_container, container_counter, '
            .'audit_log, notifications, pending_request, candidate, negara, '
            .'perusahaan, posisi_pekerjaan, jenis_visa RESTART IDENTITY CASCADE'
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
