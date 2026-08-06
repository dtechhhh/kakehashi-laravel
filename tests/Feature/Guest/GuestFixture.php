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

    protected function guestFixtureSetup(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::ASSISTANT_MANAGER);
        $checker = User::factory()->active()->create();
        $checker->assignRole(Rbac::JOB_MANAGER);
        $this->makerId = (int) $maker->id;
        $this->checkerId = (int) $checker->id;
        $this->containerId = $this->createGuestContainer();
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
        $countryId = (int) DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
