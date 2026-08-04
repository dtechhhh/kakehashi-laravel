<?php

namespace Tests\Feature\Fixture;

use App\Models\User;
use Database\Seeders\R3ManualFixtureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\LookupData\Public\LookupService;
use RuntimeException;
use Tests\TestCase;

class R3ManualFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_production_environment(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);

        app(R3ManualFixtureSeeder::class)->run();
    }

    public function test_refuses_non_production_unauthorized_environment_before_any_mutation(): void
    {
        $this->app['env'] = 'staging';

        try {
            app(R3ManualFixtureSeeder::class)->run();
            $this->fail('Seeder must refuse a non-production unauthorized environment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local', $exception->getMessage());
            $this->assertStringContainsString('testing', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->where('email', 'like', '%@r3-manual.example.com')->count());
        $this->assertSame(0, DB::table('candidate')->where('nama_alphabet', 'like', 'R3 Pagination Kandidat%')->count());
        $this->assertSame(0, DB::table('negara')->whereIn('code', ['XD', 'XE'])->count());
        $this->assertSame(0, DB::table('perusahaan')->where('nama_ja', 'R3テスト会社')->count());
        $this->assertSame(0, DB::table('lookup_request')->count());
        $this->assertSame(0, DB::table('company_request')->count());
    }

    public function test_refuses_live_connection_not_matching_dedicated_database_before_any_mutation(): void
    {
        $this->app['env'] = 'local';

        config(['database.connections.pgsql.database' => 'kakehashi_r3_manual']);

        $this->assertSame('kakehashi_test', DB::connection()->getDatabaseName());

        try {
            app(R3ManualFixtureSeeder::class)->run();
            $this->fail('Seeder must refuse when the live connection is not the dedicated R3 database.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kakehashi_r3_manual', $exception->getMessage());
        }

        config(['database.connections.pgsql.database' => 'kakehashi_test']);

        $this->assertSame(0, User::query()->where('email', 'like', '%@r3-manual.example.com')->count());
        $this->assertSame(0, DB::table('candidate')->where('nama_alphabet', 'like', 'R3 Pagination Kandidat%')->count());
        $this->assertSame(0, DB::table('negara')->whereIn('code', ['XD', 'XE'])->count());
        $this->assertSame(0, DB::table('perusahaan')->where('nama_ja', 'R3テスト会社')->count());
        $this->assertSame(0, DB::table('lookup_request')->count());
        $this->assertSame(0, DB::table('company_request')->count());
    }

    public function test_provisions_exact_account_states_roles_and_no_separation_of_duties_violation(): void
    {
        $this->seed(R3ManualFixtureSeeder::class);

        $expected = [
            'STAFF-A' => [Rbac::STAFF_INPUT, 'Aktif', false, false],
            'STAFF-FORCED' => [Rbac::STAFF_INPUT, 'Aktif', true, false],
            'STAFF-LOCK' => [Rbac::STAFF_INPUT, 'Aktif', false, false],
            'STAFF-TARGET' => [Rbac::STAFF_INPUT, 'Aktif', false, false],
            'STAFF-INACTIVE' => [Rbac::STAFF_INPUT, 'Nonaktif', false, false],
            'APPROVER-UNENROLLED' => [Rbac::CANDIDATE_APPROVER, 'Aktif', false, false],
            'APPROVER-A' => [Rbac::CANDIDATE_APPROVER, 'Aktif', false, true],
            'APPROVER-B' => [Rbac::CANDIDATE_APPROVER, 'Aktif', false, true],
            'ADMIN-UNENROLLED' => [Rbac::SUPER_ADMIN, 'Aktif', false, false],
            'ADMIN-A' => [Rbac::SUPER_ADMIN, 'Aktif', false, true],
            'ADMIN-B' => [Rbac::SUPER_ADMIN, 'Aktif', false, true],
            'ASSISTANT-A' => [Rbac::ASSISTANT_MANAGER, 'Aktif', false, false],
        ];

        $this->assertSame(count($expected), User::query()->count());

        foreach ($expected as $label => [$role, $status, $mustChange, $totp]) {
            $user = User::query()
                ->where('email', strtolower($label).'@r3-manual.example.com')
                ->sole();

            $this->assertSame([$role], array_values($user->getRoleNames()->all()));
            $this->assertSame($status, $user->status_akun);
            $this->assertSame($mustChange, (bool) $user->must_change_password);
            $this->assertSame($totp, $user->hasEnabledTwoFactorAuthentication());

            if ($totp) {
                $this->assertCount(8, $user->recoveryCodes());
                $this->assertNotNull($user->two_factor_secret);
            } else {
                $this->assertNull($user->two_factor_secret);
            }

            $this->assertNull(Rbac::separationOfDutiesViolation([$role]));
        }
    }

    public function test_second_run_is_idempotent_and_never_rotates_credentials(): void
    {
        $dir = sys_get_temp_dir().'/r3-fixture-test-'.uniqid();
        putenv('R3_FIXTURE_PACK_DIR='.$dir);

        try {
            $this->seed(R3ManualFixtureSeeder::class);
            $packPath = $dir.'/credentials.txt';

            $this->assertFileExists($packPath);
            $this->assertSame(0600, fileperms($packPath) & 0777);
            $packBefore = file_get_contents($packPath);

            $snapshot = [
                'users' => User::query()->count(),
                'candidates' => DB::table('candidate')->count(),
                'lookup_requests' => DB::table('lookup_request')->count(),
                'company_requests' => DB::table('company_request')->count(),
                'companies' => DB::table('perusahaan')->count(),
            ];

            $this->seed(R3ManualFixtureSeeder::class);

            $this->assertSame($snapshot, [
                'users' => User::query()->count(),
                'candidates' => DB::table('candidate')->count(),
                'lookup_requests' => DB::table('lookup_request')->count(),
                'company_requests' => DB::table('company_request')->count(),
                'companies' => DB::table('perusahaan')->count(),
            ]);

            $this->assertSame($packBefore, file_get_contents($packPath));
        } finally {
            putenv('R3_FIXTURE_PACK_DIR');
        }
    }

    public function test_pagination_candidate_fixture_preserves_invariants(): void
    {
        $this->seed(R3ManualFixtureSeeder::class);

        $staffA = User::query()->where('email', 'staff-a@r3-manual.example.com')->sole();

        $rows = DB::table('candidate')
            ->where('nama_alphabet', 'like', 'R3 Pagination Kandidat%')
            ->orderBy('nama_alphabet')
            ->get();

        $this->assertCount(26, $rows);

        foreach ($rows as $row) {
            $this->assertSame(CandidateApprovalStatus::Draft->value, $row->status_approval);
            $this->assertSame(CandidateAvailability::Tersedia->value, $row->status_ketersediaan);
            $this->assertNull($row->nomor_induk);
            $this->assertNull($row->parent_candidate_id);
            $this->assertNull($row->deleted_at);
            $this->assertNull($row->pii_anonymized_at);
            $this->assertSame(0, (int) $row->version);
            $this->assertSame($staffA->getKey(), (int) $row->created_by);
            $this->assertContains($row->jenis_kelamin, ['M', 'F']);
        }

        $baseline = DB::table('candidate')
            ->where('nama_alphabet', 'Budi Santoso')
            ->where('tanggal_lahir', '1995-05-05')
            ->sole();

        $this->assertSame(CandidateApprovalStatus::Draft->value, $baseline->status_approval);
        $this->assertNull($baseline->nomor_induk);
    }

    public function test_inactive_historical_lookup_fixture_stays_active_only_for_new_options(): void
    {
        $this->seed(R3ManualFixtureSeeder::class);

        $negara = DB::table('negara')->where('code', 'XD')->sole();
        $this->assertFalse((bool) $negara->is_active);

        $company = DB::table('perusahaan')->where('nama_ja', 'R3テスト会社')->sole();
        $this->assertSame($negara->id, $company->negara_id);
        $this->assertTrue((bool) $company->is_active);

        $service = app(LookupService::class);
        $options = $service->optionsById('negara');

        $this->assertArrayNotHasKey((int) $negara->id, $options);
        $this->assertSame('Negara Uji R3', $service->labelById('negara', (int) $negara->id));
    }

    public function test_pending_request_fixtures_exist_for_s2_decision_path(): void
    {
        $this->seed(R3ManualFixtureSeeder::class);

        $lookup = DB::table('lookup_request')
            ->where('lookup_table', 'negara')
            ->where('code', 'XE')
            ->sole();

        $this->assertSame('pending', $lookup->status);
        $this->assertSame(
            'staff-a@r3-manual.example.com',
            User::query()->whereKey($lookup->requested_by)->sole()->email,
        );

        $company = DB::table('company_request')->where('nama_ja', 'R3待機会社')->sole();
        $this->assertSame('pending', $company->status);
        $this->assertSame(
            'assistant-a@r3-manual.example.com',
            User::query()->whereKey($company->requested_by)->sole()->email,
        );
    }
}
