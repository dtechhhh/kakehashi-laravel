<?php

namespace Tests\Feature\Guest;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Rbac;
use Modules\Jobs\Services\GuestLinkService;

/**
 * Shared W6 fixture: roles, an Aktif interview container, and a helper that
 * requests + approves a guest link and returns the one-time raw token plus
 * the link id. Raw tokens are never persisted by these helpers.
 */
trait GuestFixture
{
    protected int $makerId;

    protected int $checkerId;

    protected int $containerId;

    protected int $countryId;

    protected function guestFixtureSetup(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);
        $this->makerId = (int) $maker->id;
        $this->checkerId = (int) $checker->id;
        $this->countryId = $this->lookup('negara', 'ID', 'Indonesia', 'インドネシア');
        $this->containerId = $this->createGuestContainer();
    }

    /**
     * Create an approved candidate and pull them into the container.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createParticipant(int $containerId, array $overrides = []): int
    {
        $now = now();
        $candidateId = (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => 'K-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'nama_alphabet' => 'W6 Test Candidate',
            'nama_katakana' => 'テスト コウホ',
            'tanggal_lahir' => '1998-05-14',
            'kewarganegaraan_id' => $this->countryId,
            'jenis_kelamin' => 'M',
            'status_pernikahan' => 'SINGLE',
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
            'status_approval' => 'Disetujui',
            'version' => 0,
            'created_by' => $this->makerId,
            'approved_by' => $this->checkerId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('participation')->insert([
            'interview_container_id' => $containerId,
            'candidate_id' => $candidateId,
            'status_wawancara' => 'Menunggu Wawancara',
            'version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $candidateId;
    }

    protected function addJapaneseLevel(int $candidateId, string $code, string $labelJa, string $score = 'N3'): void
    {
        $typeId = $this->lookup('jenis_kualifikasi_bahasa_jepang', $code, 'Bahasa Jepang '.$code, $labelJa);
        DB::table('candidate_qual_japanese')->insert([
            'candidate_id' => $candidateId,
            'jenis_id' => $typeId,
            'tanggal_akuisisi' => '2024-03-01',
            'skor' => $score,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function addSswQualification(int $candidateId, string $code, string $labelJa, bool $shareable = false): void
    {
        $skillId = $this->lookup('skill_ssw', $code, 'SSW '.$code, $labelJa, ['is_shareable' => $shareable]);
        DB::table('candidate_qual_ssw')->insert([
            'candidate_id' => $candidateId,
            'skill_ssw_id' => $skillId,
            'tanggal_akuisisi' => '2024-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function addBidangDiminati(int $candidateId, string $code, string $labelJa): void
    {
        $bidangId = $this->lookup('bidang_diminati', $code, 'Bidang '.$code, $labelJa);
        DB::table('candidate_self_promo')->insert([
            'candidate_id' => $candidateId,
            'bidang_diminati_id' => $bidangId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{token: string, link_id: int}
     */
    protected function approveLink(
        ?int $containerId = null,
        ?string $code = null,
        ?string $label = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $containerId ??= $this->containerId;
        $label ??= 'W6 guest link';
        $expiresAt ??= now()->addDays(3);

        $this->actingAs(User::findOrFail($this->makerId));
        $request = app(GuestLinkService::class)->requestGuestLink(
            User::findOrFail($this->makerId),
            $containerId,
            [
                'version' => 0,
                'label' => $label,
                'tanggal_kadaluarsa' => $expiresAt->format('Y-m-d H:i:s'),
                'kode_tambahan' => $code,
            ],
        );

        $this->actingAs(User::findOrFail($this->checkerId));
        $token = (string) app(GuestLinkService::class)
            ->approveGuestLink(User::findOrFail($this->checkerId), (int) $request->getKey())
            ->token;

        return [
            'token' => $token,
            'link_id' => (int) DB::table('guest_link')
                ->where('interview_container_id', $containerId)
                ->where('token_hash', hash('sha256', $token))
                ->value('id'),
        ];
    }

    private function createGuestContainer(): int
    {
        $companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W6 テスト会社',
            'nama_romaji' => 'W6 Test Company',
            'nama_id' => 'Perusahaan Test W6',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $positionId = $this->lookup('posisi_pekerjaan', 'W6_POSITION', 'Posisi Test', 'テストポジション');
        $visaId = $this->lookup('jenis_visa', 'W6_VISA', 'Visa Test', 'テストビザ', ['kategori' => 'SSW']);

        return (int) DB::table('interview_container')->insertGetId([
            'judul' => 'W6 Container',
            'perusahaan_id' => $companyId,
            'posisi_pekerjaan_id' => $positionId,
            'jenis_wawancara' => 'ONLINE',
            'jenis_visa_id' => $visaId,
            'tanggal_wawancara' => '2026-09-01',
            'jumlah_peserta' => 0,
            'target_peserta_diterima' => 1,
            'deskripsi' => 'Synthetic W6 fixture',
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function lookup(string $table, string $code, string $labelId, string $labelJa, array $extra = []): int
    {
        $existing = DB::table($table)->where('code', $code)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table($table)->insertGetId(array_merge([
            'code' => $code,
            'label_id' => $labelId,
            'label_ja' => $labelJa,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
