<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Modules\Auth\Rbac;
use Modules\Candidates\Enums\CandidateApprovalStatus;
use Modules\Candidates\Enums\CandidateAvailability;
use Modules\LookupData\Public\LookupRequestService;
use RuntimeException;

/**
 * UI-W0-W3-R2-TASK3 — repeatable R3 manual-browser fixture pack.
 *
 * Runs only in a confirmed local or testing Laravel environment on PostgreSQL
 * (never SQLite, never the drifted local database). Local runs require the
 * live default connection database to be exactly `kakehashi_r3_manual`;
 * PHPUnit (`testing`) requires it to be exactly `kakehashi_test`. Every other
 * environment and database is refused before any write. It reuses the existing
 * RolePermissionSeeder/LookupSeeder and public services
 * (LookupRequestService) for business-state transitions, and writes the
 * operator-only credential pack outside the repository with mode 0600.
 *
 * Repeatability: a second run on the same database is a verified no-op — it
 * never rotates already issued credentials/TOTP material and never duplicates
 * fixtures. Partial states and drifted account states abort with a clear
 * message instead of being silently repaired.
 */
final class R3ManualFixtureSeeder extends Seeder
{
    private const PACK_DIR = '/tmp/kakehashi-r3-manual-fixture';

    private const PACK_FILE = self::PACK_DIR.'/credentials.txt';

    private const EMAIL_DOMAIN = 'r3-manual.example.com';

    private const CANDIDATE_PREFIX = 'R3 Pagination Kandidat';

    /** Similarity baseline for the K3 soft-warning proof (same DOB/nationality
     * as the identity the R3 handoff instructs STAFF-A to create through the
     * normal K3 UI). */
    private const SIMILARITY_BASELINE = [
        'nama_alphabet' => 'Budi Santoso',
        'nama_katakana' => 'ブディ サントソ',
        'tanggal_lahir' => '1995-05-05',
    ];

    private const INACTIVE_NEGARA = [
        'code' => 'XD',
        'label_id' => 'Negara Uji R3',
        'label_ja' => 'R3テスト国',
    ];

    private const PENDING_LOOKUP = [
        'code' => 'XE',
        'label_id' => 'Negara Antre R3',
        'label_ja' => 'R3待機国',
    ];

    private const PENDING_COMPANY_JA = 'R3待機会社';

    /**
     * @var array<string, array{role: string, must_change: bool, inactive: bool, totp: bool}>
     */
    private const ACCOUNTS = [
        'STAFF-A' => ['role' => Rbac::STAFF_INPUT, 'must_change' => false, 'inactive' => false, 'totp' => false],
        'STAFF-FORCED' => ['role' => Rbac::STAFF_INPUT, 'must_change' => true, 'inactive' => false, 'totp' => false],
        'STAFF-LOCK' => ['role' => Rbac::STAFF_INPUT, 'must_change' => false, 'inactive' => false, 'totp' => false],
        'STAFF-TARGET' => ['role' => Rbac::STAFF_INPUT, 'must_change' => false, 'inactive' => false, 'totp' => false],
        'STAFF-INACTIVE' => ['role' => Rbac::STAFF_INPUT, 'must_change' => false, 'inactive' => true, 'totp' => false],
        'APPROVER-UNENROLLED' => ['role' => Rbac::CANDIDATE_APPROVER, 'must_change' => false, 'inactive' => false, 'totp' => false],
        'APPROVER-A' => ['role' => Rbac::CANDIDATE_APPROVER, 'must_change' => false, 'inactive' => false, 'totp' => true],
        'APPROVER-B' => ['role' => Rbac::CANDIDATE_APPROVER, 'must_change' => false, 'inactive' => false, 'totp' => true],
        'ADMIN-UNENROLLED' => ['role' => Rbac::SUPER_ADMIN, 'must_change' => false, 'inactive' => false, 'totp' => false],
        'ADMIN-A' => ['role' => Rbac::SUPER_ADMIN, 'must_change' => false, 'inactive' => false, 'totp' => true],
        'ADMIN-B' => ['role' => Rbac::SUPER_ADMIN, 'must_change' => false, 'inactive' => false, 'totp' => true],
        'ASSISTANT-A' => ['role' => Rbac::ASSISTANT_MANAGER, 'must_change' => false, 'inactive' => false, 'totp' => false],
    ];

    public function run(): void
    {
        $this->assertEnvironment();
        $this->assertDatabaseBoundary();

        $this->call(RolePermissionSeeder::class);
        $this->call(LookupSeeder::class);

        $existing = User::query()
            ->whereIn('email', $this->emails())
            ->get()
            ->keyBy(fn (User $user): string => $user->email);

        $this->clearLockoutFixtureRateLimitState();

        if ($existing->isNotEmpty()) {
            $this->assertCompleteFixtureState($existing);

            $this->command?->info('R3 fixtures already provisioned; state verified, nothing recreated.');

            return;
        }

        $pack = DB::transaction(fn (): array => $this->provision());

        $this->writePack($pack);

        $this->command?->info('R3 fixture pack provisioned.');
    }

    private function assertEnvironment(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('R3ManualFixtureSeeder requires PostgreSQL; SQLite is refused.');
        }

        $environment = app()->environment();

        if ($environment === 'production') {
            throw new RuntimeException('R3ManualFixtureSeeder refuses the production environment.');
        }

        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new RuntimeException(
                'R3ManualFixtureSeeder refuses the application environment "'.addcslashes((string) $environment, "\\\"\r\n").'". '
                .'It may run only in a confirmed local or testing environment.'
            );
        }
    }

    /**
     * Enforce the dedicated database boundary before any seeder, rate-limit
     * clear, user, domain row, or credential file is touched. The database
     * name comes from the same resolved default connection the seeder will
     * write through (`getDatabaseName()`), not from the raw configuration
     * string, so a PostgreSQL URL override cannot bypass the guard:
     * - local execution: only the dedicated R3 manual database;
     * - PHPUnit: only the test database while the environment is exactly
     *   `testing`.
     * Every other live database name (including `kakehashi`) is refused.
     */
    private function assertDatabaseBoundary(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        $allowed = app()->environment('testing')
            ? $database === 'kakehashi_test'
            : $database === 'kakehashi_r3_manual';

        if (! $allowed) {
            throw new RuntimeException(
                'R3ManualFixtureSeeder refuses the active database "'.addcslashes($database, "\\\"\r\n").'". '
                .'Local runs require the dedicated kakehashi_r3_manual database; '
                .'PHPUnit may use kakehashi_test only when the application environment is testing.'
            );
        }
    }

    /**
     * Exact-throttle-key clear for the dedicated lockout fixture only, on the
     * documented local loopback addresses. Never flushes the shared cache.
     */
    private function clearLockoutFixtureRateLimitState(): void
    {
        foreach (['127.0.0.1', '::1'] as $ip) {
            RateLimiter::clear('login:'.$this->email('STAFF-LOCK').'|'.$ip);
        }
    }

    /**
     * @param  Collection<int, User>  $existing  keyed by email
     */
    private function assertCompleteFixtureState(Collection $existing): void
    {
        if ($existing->count() !== count(self::ACCOUNTS)) {
            throw new RuntimeException(
                'R3 fixture state is partially provisioned. Reprovision the dedicated R3 database before continuing.'
            );
        }

        foreach (self::ACCOUNTS as $label => $expected) {
            $user = $existing->get($this->email($label));

            $roles = array_values($user->getRoleNames()->all());
            $roleMismatch = $roles !== [$expected['role']];

            $totpMismatch = $expected['totp']
                ? ! $user->hasEnabledTwoFactorAuthentication() || count($user->recoveryCodes()) !== 8
                : $user->hasEnabledTwoFactorAuthentication();

            if ($roleMismatch
                || $user->status_akun !== ($expected['inactive'] ? 'Nonaktif' : 'Aktif')
                || (bool) $user->must_change_password !== $expected['must_change']
                || $totpMismatch) {
                throw new RuntimeException(
                    "R3 fixture account {$label} drifted from its documented state. "
                    .'Reprovision the dedicated R3 database before continuing.'
                );
            }
        }

        $candidateCount = DB::table('candidate')
            ->where('nama_alphabet', 'like', self::CANDIDATE_PREFIX.'%')
            ->count();
        $similarity = DB::table('candidate')
            ->where('nama_alphabet', self::SIMILARITY_BASELINE['nama_alphabet'])
            ->exists();
        $inactiveNegara = DB::table('negara')->where('code', self::INACTIVE_NEGARA['code'])->exists();
        $company = DB::table('perusahaan')->where('nama_ja', 'R3テスト会社')->exists();
        $pendingLookup = DB::table('lookup_request')
            ->where('lookup_table', 'negara')
            ->where('code', self::PENDING_LOOKUP['code'])
            ->where('status', 'pending')
            ->exists();
        $pendingCompany = DB::table('company_request')
            ->where('nama_ja', self::PENDING_COMPANY_JA)
            ->where('status', 'pending')
            ->exists();

        if ($candidateCount < 26
            || ! $similarity
            || ! $inactiveNegara
            || ! $company
            || ! $pendingLookup
            || ! $pendingCompany) {
            throw new RuntimeException(
                'R3 fixture data is incomplete. Reprovision the dedicated R3 database before continuing.'
            );
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function provision(): array
    {
        $pack = [];

        foreach (self::ACCOUNTS as $label => $expected) {
            $password = $this->generatePassword();
            $entry = [
                'email' => $this->email($label),
                'password' => $password,
                'must_change' => $expected['must_change'],
            ];

            $user = User::create([
                'name' => 'Synthetic Recheck '.$label,
                'email' => $this->email($label),
                'password' => $password,
                'must_change_password' => $expected['must_change'],
                'status_akun' => $expected['inactive'] ? 'Nonaktif' : 'Aktif',
            ]);
            $user->assignRole($expected['role']);

            if ($expected['totp']) {
                $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey(16);
                $codes = Collection::times(8, static fn (): string => RecoveryCode::generate())->all();

                $user->forceFill([
                    'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
                    'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
                    'two_factor_confirmed_at' => now(),
                ])->save();

                $entry['totp_secret'] = $secret;
                $entry['otpauth'] = 'otpauth://totp/Kakehashi:'.$this->email($label)
                    .'?secret='.$secret.'&issuer=Kakehashi&algorithm=SHA1&digits=6&period=30';
                $entry['recovery_codes'] = implode(',', $codes);
            }

            $pack[$label] = $entry;
        }

        $staffA = User::query()->where('email', $this->email('STAFF-A'))->firstOrFail();
        $this->createCandidateFixtures($staffA);
        $this->createInactiveNegaraAndCompany();
        $this->createPendingRequests();

        return $pack;
    }

    private function createCandidateFixtures(User $staffA): void
    {
        $indonesiaId = (int) DB::table('negara')->where('code', 'ID')->value('id');

        for ($i = 1; $i <= 26; $i++) {
            DB::table('candidate')->insert([
                'nama_alphabet' => sprintf('%s %02d', self::CANDIDATE_PREFIX, $i),
                'nama_katakana' => $i % 4 === 0 ? sprintf('R3パジネーション候補 %02d', $i) : null,
                'tanggal_lahir' => now()->subYears(20 + ($i % 20))->subDays($i)->toDateString(),
                'kewarganegaraan_id' => $indonesiaId,
                'jenis_kelamin' => $i % 2 === 0 ? 'F' : 'M',
                'status_approval' => CandidateApprovalStatus::Draft->value,
                'status_ketersediaan' => CandidateAvailability::Tersedia->value,
                'version' => 0,
                'created_by' => $staffA->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('candidate')->insert([
            'nama_alphabet' => self::SIMILARITY_BASELINE['nama_alphabet'],
            'nama_katakana' => self::SIMILARITY_BASELINE['nama_katakana'],
            'tanggal_lahir' => self::SIMILARITY_BASELINE['tanggal_lahir'],
            'kewarganegaraan_id' => $indonesiaId,
            'jenis_kelamin' => 'M',
            'status_approval' => CandidateApprovalStatus::Draft->value,
            'status_ketersediaan' => CandidateAvailability::Tersedia->value,
            'version' => 0,
            'created_by' => $staffA->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createInactiveNegaraAndCompany(): void
    {
        $id = DB::table('negara')->insertGetId([
            'code' => self::INACTIVE_NEGARA['code'],
            'label_id' => self::INACTIVE_NEGARA['label_id'],
            'label_ja' => self::INACTIVE_NEGARA['label_ja'],
            'region' => 'Asia Tenggara',
            'dial_code' => null,
            'sort_order' => 999,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('perusahaan')->insert([
            'nama_ja' => 'R3テスト会社',
            'nama_romaji' => 'Perusahaan Uji R3',
            'nama_id' => 'Perusahaan Uji R3',
            'negara_id' => $id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPendingRequests(): void
    {
        $requests = app(LookupRequestService::class);

        Auth::setUser($this->user('STAFF-A'));
        $requests->submitLookup($this->user('STAFF-A'), [
            'lookup_table' => 'negara',
            'code' => self::PENDING_LOOKUP['code'],
            'label_id' => self::PENDING_LOOKUP['label_id'],
            'label_ja' => self::PENDING_LOOKUP['label_ja'],
            'reason' => 'Fixture R3: pending lookup request for the S2 decision path.',
        ]);

        Auth::setUser($this->user('ASSISTANT-A'));
        $requests->submitCompany($this->user('ASSISTANT-A'), [
            'nama_ja' => self::PENDING_COMPANY_JA,
            'nama_id' => 'Perusahaan Antre R3',
            'reason' => 'Fixture R3: pending company request for the S2 decision path.',
        ]);

        Auth::logout();
    }

    private function user(string $label): User
    {
        return User::query()->where('email', $this->email($label))->firstOrFail();
    }

    private function email(string $label): string
    {
        return strtolower($label).'@'.self::EMAIL_DOMAIN;
    }

    /**
     * @return list<string>
     */
    private function emails(): array
    {
        return array_map(fn (string $label): string => $this->email($label), array_keys(self::ACCOUNTS));
    }

    /**
     * Runtime-generated password that satisfies the A-7 policy
     * (min 12 chars, at least 3 of 4 classes). Never a shared default.
     */
    private function generatePassword(): string
    {
        return Str::random(14).random_int(1000, 9999).'!';
    }

    /**
     * @param  array<string, array<string, string>>  $pack
     */
    private function writePack(array $pack): void
    {
        $dir = (string) (env('R3_FIXTURE_PACK_DIR') ?: self::PACK_DIR);

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create credential pack directory: {$dir}");
        }

        $lines = [
            '# Kakehashi R3 manual fixture credentials — operator-only, mode 0600.',
            '# Generated '.now()->toDateTimeString().'. Do not share, commit, or paste into chat.',
        ];

        foreach (self::ACCOUNTS as $label => $expected) {
            $entry = $pack[$label];
            $line = sprintf(
                '%s email=%s password=%s%s',
                $label,
                $entry['email'],
                $entry['password'],
                $expected['must_change'] ? ' MUST_CHANGE_ON_FIRST_LOGIN' : '',
            );

            if ($expected['totp']) {
                $line .= sprintf(
                    ' totp_secret=%s otpauth=%s recovery_codes=%s',
                    $entry['totp_secret'],
                    $entry['otpauth'],
                    $entry['recovery_codes'],
                );
            }

            $lines[] = $line;
        }

        $file = $dir.'/credentials.txt';

        if (! file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX)) {
            throw new RuntimeException("Cannot write credential pack: {$file}");
        }

        chmod($file, 0600);
    }
}
