<?php

namespace Tests\Feature\Guest;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Rbac;
use Modules\Jobs\Services\GuestLinkService;
use Shared\Audit\ActionType;
use Tests\TestCase;

/**
 * W6-T1 — token/link issuance invariants.
 *
 * The raw token is generated at approval, returned exactly once to the
 * Checker, and only its SHA-256 hash is ever persisted. Before approval no
 * guest_link row exists. A token is bound to exactly one interview container.
 */
class GuestTokenIssuanceTest extends TestCase
{
    use RefreshDatabase;

    private int $makerId;

    private int $checkerId;

    private int $containerId;

    private int $countryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);
        $this->makerId = (int) $maker->id;
        $this->checkerId = (int) $checker->id;
        $this->countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->containerId = $this->createActiveContainer();
    }

    public function test_request_creates_no_guest_link_before_approval(): void
    {
        $this->actingAs(User::findOrFail($this->makerId));

        app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $this->containerId,
            [
                'version' => 0,
                'label' => 'W6-T1 pending link',
                'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
                'kode_tambahan' => 'secret-code',
            ],
        );

        $this->assertSame(
            0,
            DB::table('guest_link')->where('interview_container_id', $this->containerId)->count(),
            'No guest_link row may exist while the request is pending (token only after approval).',
        );
    }

    public function test_approval_returns_token_once_and_persists_only_sha256_hash(): void
    {
        $this->actingAs(User::findOrFail($this->makerId));
        $request = app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $this->containerId,
            [
                'version' => 0,
                'label' => 'W6-T1 token link',
                'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
                'kode_tambahan' => null,
            ],
        );

        $this->actingAs(User::findOrFail($this->checkerId));
        $token = (string) app(GuestLinkService::class)
            ->approveGuestLink(User::findOrFail($this->checkerId), (int) $request->getKey())
            ->token;

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, '256-bit random token expected.');

        $link = DB::table('guest_link')->where('interview_container_id', $this->containerId)->first();
        $this->assertNotNull($link);
        $this->assertSame('Aktif', $link->status_link);
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertNotSame($token, $link->token_hash);
    }

    public function test_raw_token_appears_nowhere_at_rest(): void
    {
        $this->actingAs(User::findOrFail($this->makerId));
        $request = app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $this->containerId,
            [
                'version' => 0,
                'label' => 'W6-T1 leak scan link',
                'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
                'kode_tambahan' => 'leak-scan-code',
            ],
        );

        $this->actingAs(User::findOrFail($this->checkerId));
        $token = (string) app(GuestLinkService::class)
            ->approveGuestLink(User::findOrFail($this->checkerId), (int) $request->getKey())
            ->token;

        // Scan every text-ish column of the link, its access log, and audit rows.
        foreach (['guest_link', 'guest_access_log'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get();
            foreach ($rows as $row) {
                foreach ((array) $row as $column => $value) {
                    $this->assertFalse(
                        is_string($value) && str_contains($value, $token),
                        "Raw token leaked into {$table}.{$column}.",
                    );
                }
            }
        }

        $auditRows = DB::table('audit_log')
            ->where('action_type', ActionType::GUEST_LINK_APPROVED->value)
            ->orWhere('action_type', ActionType::GUEST_LINK_REQUESTED->value)
            ->get();
        $this->assertNotEmpty($auditRows);
        foreach ($auditRows as $row) {
            foreach ((array) $row as $column => $value) {
                $this->assertFalse(
                    is_string($value) && str_contains($value, $token),
                    "Raw token leaked into audit_log.{$column}.",
                );
            }
        }

        $approved = $auditRows->firstWhere('action_type', ActionType::GUEST_LINK_APPROVED->value);
        $this->assertNotNull($approved);
        $detail = is_string($approved->detail) ? json_decode($approved->detail, true) : $approved->detail;
        $this->assertIsArray($detail);
        $this->assertSame($this->containerId, (int) ($detail['interview_container_id'] ?? 0));
    }

    public function test_each_approved_link_is_bound_to_one_container(): void
    {
        $this->actingAs(User::findOrFail($this->makerId));

        $secondContainerId = $this->createActiveContainer();

        $requestA = app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $this->containerId,
            [
                'version' => 0,
                'label' => 'W6-T1 link A',
                'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
                'kode_tambahan' => null,
            ],
        );
        $requestB = app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $secondContainerId,
            [
                'version' => 0,
                'label' => 'W6-T1 link B',
                'tanggal_kadaluarsa' => now()->addDays(3)->toISOString(),
                'kode_tambahan' => null,
            ],
        );

        $this->actingAs(User::findOrFail($this->checkerId));
        $tokenA = (string) app(GuestLinkService::class)
            ->approveGuestLink(User::findOrFail($this->checkerId), (int) $requestA->getKey())
            ->token;
        $tokenB = (string) app(GuestLinkService::class)
            ->approveGuestLink(User::findOrFail($this->checkerId), (int) $requestB->getKey())
            ->token;

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame(
            $this->containerId,
            (int) DB::table('guest_link')->where('token_hash', hash('sha256', $tokenA))->value('interview_container_id'),
        );
        $this->assertSame(
            $secondContainerId,
            (int) DB::table('guest_link')->where('token_hash', hash('sha256', $tokenB))->value('interview_container_id'),
        );
    }

    private function createActiveContainer(): int
    {
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W6-T1 テスト会社',
            'nama_romaji' => 'W6-T1 Test Company',
            'nama_id' => 'Perusahaan Test W6-T1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->lookupId('posisi_pekerjaan', 'W6_T1_POSITION');
        $visaId = $this->lookupId('jenis_visa', 'W6_T1_VISA');

        return (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W6-T1 Container '.random_int(100, 999),
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 1,
            'deskripsi' => 'Synthetic W6-T1 fixture',
            'syarat' => 'N3',
            'status' => 'Aktif',
            'dibuat_oleh' => $this->makerId,
            'disetujui_oleh' => $this->checkerId,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lookupId(string $table, string $code): int
    {
        $existing = DB::table($table)->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $label = [
            'posisi_pekerjaan' => ['Posisi Test', 'テストポジション'],
            'jenis_visa' => ['Visa Test', 'テストビザ'],
        ][$table];

        return (int) DB::table($table)->insertGetId([
            'code' => $code,
            'label_id' => $label[0],
            'label_ja' => $label[1],
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
