<?php

namespace Tests\Feature\Jobs;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\Jobs\Services\GuestLinkService;
use Modules\Jobs\Services\InterviewContainerService;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

/**
 * W4-R1 — approveGuestLink must never create an Aktif link for a container
 * whose close has already committed. The guarded INSERT revalidates container
 * status/version atomically inside the same transaction (no FOR UPDATE).
 */
class GuestLinkApprovalConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** Forked workers need committed rows visible outside the parent transaction. */
    protected array $connectionsToTransact = [];

    private int $makerId;

    private int $checkerId;

    private int $containerId;

    private int $guestLinkPendingId;

    private int $closePendingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);

        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);
        $this->makerId = (int) $maker->id;
        $this->checkerId = (int) $checker->id;

        $countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W4-R1 Guest Race Company',
            'nama_romaji' => 'W4-R1 Guest Race',
            'nama_id' => 'Perusahaan Guest Race',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = (int) DB::table('posisi_pekerjaan')->insertGetId([
            'code' => 'W4_R1_GUEST_RACE_POSITION',
            'label_id' => 'Posisi Guest Race',
            'label_ja' => 'ゲストレースポジション',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $visaId = (int) DB::table('jenis_visa')->insertGetId([
            'code' => 'W4_R1_GUEST_RACE_VISA',
            'label_id' => 'Visa Guest Race',
            'label_ja' => 'ゲストレースビザ',
            'kategori' => 'SSW',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->containerId = (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W4-R1 Guest Link Race Container',
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
            'dibuat_oleh' => $this->makerId,
            'disetujui_oleh' => $this->checkerId,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker);
        $guestLinkPending = app(GuestLinkService::class)->requestGuestLink($maker, $this->containerId, [
            'version' => 0,
            'label' => 'Race guest link',
            'tanggal_kadaluarsa' => now()->addDays(2)->toISOString(),
            'kode_tambahan' => 'race-code',
        ]);
        app(InterviewContainerService::class)->requestClose(
            $maker,
            $this->containerId,
            'Tutup race',
            ['version' => 0],
        );

        $this->guestLinkPendingId = (int) $guestLinkPending->id;
        $closePendingId = DB::table('pending_request')
            ->where('type', PendingType::IC_CLOSE->value)
            ->where('target_id', $this->containerId)
            ->where('status', 'pending')
            ->value('id');
        $this->assertNotNull($closePendingId);
        $this->closePendingId = (int) $closePendingId;
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_parallel_approve_link_and_close_never_creates_active_link_after_close_commits(): void
    {
        $startAt = microtime(true) + 0.5;
        $linkPid = $this->forkApproveGuestLink($startAt);
        $closePid = $this->forkApproveClose($startAt);

        $results = [];
        foreach (['link' => $linkPid, 'close' => $closePid] as $name => $pid) {
            pcntl_waitpid($pid, $status);
            $results[$name] = pcntl_wexitstatus($status);
        }

        DB::purge('pgsql');
        DB::purge('pgsql_migrator');

        $this->assertSame(0, $results['close'], 'close must always succeed for an Aktif container');
        $this->assertContains($results['link'], [0, 10], 'link approval must succeed first or conflict with CONFLICT');

        $container = DB::table('interview_container')->where('id', $this->containerId)->first();
        $this->assertSame('Ditutup', $container->status);
        $this->assertSame(1, (int) $container->version);

        $linkCount = DB::table('guest_link')->where('interview_container_id', $this->containerId)->count();
        $this->assertContains($linkCount, [0, 1]);

        if ($linkCount === 1) {
            $this->assertSame(
                'Aktif',
                DB::table('guest_link')->where('interview_container_id', $this->containerId)->value('status_link'),
            );
            $this->assertSame(
                'approved',
                DB::table('pending_request')->where('id', $this->guestLinkPendingId)->value('status'),
            );
        } else {
            $this->assertSame(
                'pending',
                DB::table('pending_request')->where('id', $this->guestLinkPendingId)->value('status'),
            );
            $this->assertSame(
                0,
                DB::table('audit_log')->where('action_type', ActionType::GUEST_LINK_APPROVED->value)->count(),
            );
        }
    }

    public function test_close_committed_first_forces_guest_link_approval_conflict_and_rollback(): void
    {
        $pid = $this->forkApproveClose(microtime(true) + 0.1);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));

        DB::purge('pgsql');
        DB::purge('pgsql_migrator');

        $checker = User::query()->findOrFail($this->checkerId);
        Auth::login($checker);

        try {
            app(GuestLinkService::class)->approveGuestLink($checker, $this->guestLinkPendingId);
            $this->fail('Guest link approval must conflict after close has committed.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('CONFLICT', $exception->getMessage());
        }

        $this->assertSame(
            0,
            DB::table('guest_link')->where('interview_container_id', $this->containerId)->count(),
        );
        $this->assertSame(
            'pending',
            DB::table('pending_request')->where('id', $this->guestLinkPendingId)->value('status'),
        );
        $this->assertSame(
            0,
            DB::table('audit_log')->where('action_type', ActionType::GUEST_LINK_APPROVED->value)->count(),
        );
    }

    public function test_guest_link_approved_first_survives_later_close(): void
    {
        $pid = $this->forkApproveGuestLink(microtime(true) + 0.1);
        pcntl_waitpid($pid, $status);
        $this->assertSame(0, pcntl_wexitstatus($status));

        DB::purge('pgsql');
        DB::purge('pgsql_migrator');

        $checker = User::query()->findOrFail($this->checkerId);
        Auth::login($checker);
        session([
            'stepup.tokens' => [
                StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$this->containerId => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);

        $closed = app(InterviewContainerService::class)->approveClose(
            $checker,
            $this->closePendingId,
            'Tutup setelah link disetujui',
        );

        $this->assertSame('Ditutup', $closed->status);
        $this->assertSame(1, (int) $closed->version);
        $this->assertDatabaseHas('guest_link', [
            'interview_container_id' => $this->containerId,
            'status_link' => 'Aktif',
        ]);
        $this->assertSame(
            1,
            DB::table('guest_link')->where('interview_container_id', $this->containerId)->count(),
        );
    }

    private function forkApproveGuestLink(float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            DB::purge('pgsql_migrator');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            $checker = User::query()->findOrFail($this->checkerId);
            Auth::login($checker);
            app(GuestLinkService::class)->approveGuestLink($checker, $this->guestLinkPendingId);

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function forkApproveClose(float $startAt): int
    {
        $pid = pcntl_fork();

        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            DB::purge('pgsql_migrator');
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            $checker = User::query()->findOrFail($this->checkerId);
            Auth::login($checker);
            session([
                'stepup.tokens' => [
                    StepUpAction::APPROVE_INTERVIEW_CLOSE.'.interview_container.'.$this->containerId => now()->addMinutes(5)->getTimestamp(),
                ],
            ]);
            app(InterviewContainerService::class)->approveClose(
                $checker,
                $this->closePendingId,
                'Tutup sekarang',
            );

            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'CONFLICT' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame('', $migrator);

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE guest_link, participation, interview_container, container_counter, '
            .'audit_log, notifications, pending_request, candidate, negara, '
            .'perusahaan, posisi_pekerjaan, jenis_visa RESTART IDENTITY CASCADE'
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }
}
