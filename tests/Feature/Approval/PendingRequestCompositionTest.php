<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use RuntimeException;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingRequestService;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * BR-APV-08 — jalur komposisi: pemanggil membuka transaksi sendiri, lalu
 * submit() ikut serta di dalamnya sebagai savepoint sehingga status submission
 * dan baris pending commit BERSAMA.
 *
 * Cakupan sengaja terbatas: `users` dipakai sebagai PROXY tulisan domain karena
 * `candidate` / `interview_container` belum lahir di Wave 1. Komposisi
 * `Menunggu*` + pending yang sebenarnya WAJIB mendapat test sendiri di Wave 3
 * (Kandidat) dan Wave 4 (Wawancara) — kelas ini TIDAK menutup BR-APV-08 penuh.
 */
class PendingRequestCompositionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WAJIB tetap kosong. Bila kelas ini transaksional, DB::transaction milik
     * pemanggil turun menjadi savepoint level 2 di dalam transaksi test dan
     * tidak pernah commit sungguhan — ketiga test di bawah akan hijau tanpa
     * membuktikan apa pun soal commit.
     */
    protected array $connectionsToTransact = [];

    private PendingRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
        $this->service = app(PendingRequestService::class);
    }

    protected function tearDown(): void
    {
        $this->cleanFixtures();

        parent::tearDown();
    }

    public function test_pending_row_and_caller_write_share_a_single_commit(): void
    {
        $maker = $this->maker();
        $observer = $this->observer();
        $proxyEmail = $this->proxyEmail();
        $requestId = null;

        DB::transaction(function () use (&$requestId, $maker, $observer, $proxyEmail): void {
            // Tulisan milik pemanggil (proxy agregat domain).
            User::factory()->active()->create(['email' => $proxyEmail]);

            $requestId = $this->service->submit(
                type: PendingType::IC_CREATE,
                targetType: 'interview_container',
                targetId: 51,
                requestedBy: $maker->getKey(),
                auditAction: ActionType::IC_SUBMITTED,
            )->getKey();

            // Belum commit: sesi lain tidak boleh melihat salah satu pun.
            $this->assertSame(0, $this->observedUsers($observer, $proxyEmail), 'caller write must stay invisible pre-commit');
            $this->assertSame(0, $this->observedPending($observer, $requestId), 'pending row must stay invisible pre-commit');
        });

        $this->assertSame(1, $this->observedUsers($observer, $proxyEmail));
        $this->assertSame(1, $this->observedPending($observer, $requestId));
    }

    public function test_outer_rollback_also_rolls_back_the_pending_row(): void
    {
        $maker = $this->maker();
        $proxyEmail = $this->proxyEmail();
        $requestId = null;

        try {
            DB::transaction(function () use (&$requestId, $maker, $proxyEmail): void {
                User::factory()->active()->create(['email' => $proxyEmail]);

                $requestId = $this->service->submit(
                    type: PendingType::IC_CREATE,
                    targetType: 'interview_container',
                    targetId: 52,
                    requestedBy: $maker->getKey(),
                    auditAction: ActionType::IC_SUBMITTED,
                )->getKey();

                $this->assertDatabaseHas('pending_request', ['id' => $requestId]);

                throw new RuntimeException('force rollback');
            });
            $this->fail('outer transaction should rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame('force rollback', $e->getMessage());
        }

        // Satu transaksi, bukan dua commit terpisah: keduanya hilang bersama.
        $this->assertNotNull($requestId);
        $this->assertSame(0, PendingRequest::query()->whereKey($requestId)->count());
        $this->assertSame(0, User::query()->where('email', $proxyEmail)->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IC_SUBMITTED->value)->count());
    }

    public function test_caught_duplicate_conflict_leaves_the_caller_transaction_usable(): void
    {
        $maker = $this->maker();
        $otherMaker = $this->maker();
        $observer = $this->observer();

        // C1: pending aktif milik Maker lain WAJIB commit sebelum transaksi
        // pemanggil dibuka — kalau tidak, yang teruji hanya konflik intra-transaksi.
        $existing = $this->service->submit(
            type: PendingType::IC_CREATE,
            targetType: 'interview_container',
            targetId: 53,
            requestedBy: $otherMaker->getKey(),
            auditAction: ActionType::IC_SUBMITTED,
        );

        $this->assertSame(1, $this->observedPending($observer, $existing->getKey()), 'first pending must be committed');

        $beforeEmail = $this->proxyEmail();
        $afterEmail = $this->proxyEmail();
        $conflict = null;

        DB::transaction(function () use (&$conflict, $maker, $beforeEmail, $afterEmail): void {
            User::factory()->active()->create(['email' => $beforeEmail]);

            try {
                $this->service->submit(
                    type: PendingType::IC_CREATE,
                    targetType: 'interview_container',
                    targetId: 53,
                    requestedBy: $maker->getKey(),
                    auditAction: ActionType::IC_SUBMITTED,
                );
                $this->fail('duplicate active pending must conflict');
            } catch (ConflictHttpException $e) {
                $conflict = $e->getMessage();
            }

            // Savepoint sudah di-rollback: transaksi pemanggil masih dapat dipakai.
            User::factory()->active()->create(['email' => $afterEmail]);
        });

        $this->assertSame('APV_DUPLICATE', $conflict);
        $this->assertSame(1, $this->observedUsers($observer, $beforeEmail), 'write made before the conflict must survive');
        $this->assertSame(1, $this->observedUsers($observer, $afterEmail), 'write made after the conflict must commit');

        $this->assertSame(1, PendingRequest::query()->count());
        $this->assertSame($existing->getKey(), PendingRequest::query()->sole()->getKey());
        $this->assertSame(PendingStatus::PENDING, $existing->fresh()->status);
    }

    /**
     * C2 — observer memakai sesi terpisah dan TIDAK boleh berada di dalam
     * transaksi eksplisit; kalau tidak, snapshot-nya membeku dan assertion
     * invisibilitas menjadi tidak bermakna.
     */
    private function observer(): Connection
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');

        $this->assertNotSame('', $migrator, $this->missingMigratorCredentialsMessage());

        $observer = DB::connection('pgsql_migrator');

        $this->assertSame(0, $observer->transactionLevel(), 'observer session must not be inside a transaction');

        return $observer;
    }

    private function observedUsers(Connection $observer, string $email): int
    {
        return (int) $observer->selectOne(
            'SELECT count(*) AS total FROM users WHERE email = ?',
            [$email]
        )->total;
    }

    private function observedPending(Connection $observer, ?int $requestId): int
    {
        return (int) $observer->selectOne(
            'SELECT count(*) AS total FROM pending_request WHERE id = ?',
            [$requestId]
        )->total;
    }

    private function proxyEmail(): string
    {
        return 'domain-proxy-'.bin2hex(random_bytes(6)).'@example.test';
    }

    private function maker(): User
    {
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);

        return $maker;
    }

    /**
     * C3 — pending_request.requested_by dan audit_log.actor_id keduanya RESTRICT,
     * jadi kedua tabel harus kosong sebelum users dihapus. audit_log append-only
     * untuk role runtime, sehingga owner yang menjalankan TRUNCATE.
     */
    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');

        $this->assertNotSame('', $migrator, $this->missingMigratorCredentialsMessage());

        DB::connection('pgsql_migrator')->statement('TRUNCATE pending_request, audit_log RESTART IDENTITY');

        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
    }

    private function missingMigratorCredentialsMessage(): string
    {
        return 'DB_MIGRATOR_USERNAME must be provided to CLI test processes '
            .'(set -a; source .env.migrator; set +a — or inject it in CI).';
    }
}
