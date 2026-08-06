<?php

namespace Tests\Feature\UI;

use App\Livewire\Placement\PlacementIndex;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Placement\Public\PlacementQueryService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PlacementScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedPlacementReferences();
        $this->seedNegara();
    }

    protected int $companyId;

    private function seedPlacementReferences(): void
    {
        $this->companyId = (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => 'W5 配属会社',
            'nama_romaji' => 'W5 Haizoku Kaisha',
            'nama_id' => 'Perusahaan W5',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedNegara(): void
    {
        DB::table('negara')->insertOrIgnore([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function maker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::ASSISTANT_MANAGER);

        return $user;
    }

    protected function checker(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::JOB_MANAGER);

        return $this->withTwoFactor($user);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        return $this->withTwoFactor($user);
    }

    protected function noRoleUser(): User
    {
        return User::factory()->active()->create();
    }

    private function withTwoFactor(User $user): User
    {
        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    protected function createPlacementContainer(array $overrides = []): int
    {
        return (int) DB::table('placement_container')->insertGetId(array_merge([
            'kode_kontainer' => null,
            'nama' => 'Kontainer April',
            'perusahaan_id' => $this->companyId,
            'status' => 'Draft',
            'dibuat_oleh' => $this->maker()->id,
            'disetujui_oleh' => null,
            'version' => 0,
            'created_at' => now(),
            'approved_at' => null,
            'archived_at' => null,
            'updated_at' => now(),
        ], $overrides));
    }

    protected function createCandidate(array $overrides = []): int
    {
        static $sequence = 0;
        $sequence++;

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => sprintf('K-2026-%05d', $sequence),
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => Carbon::parse('1998-05-10'),
            'kewarganegaraan_id' => (int) DB::table('negara')->where('code', 'ID')->value('id'),
            'jenis_kelamin' => 'M',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Disetujui',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $this->maker()->id,
            'approved_by' => $this->checker()->id,
            'deleted_at' => null,
            'pii_anonymized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function addParticipant(int $containerId, int $candidateId, array $overrides = []): int
    {
        return (int) DB::table('placement_participants')->insertGetId(array_merge([
            'placement_container_id' => $containerId,
            'candidate_id' => $candidateId,
            'source_participation_id' => 1,
            'kategori_force_majeur_id' => null,
            'alasan_force_majeur' => null,
            'jenis_visa_id' => $this->visaId(),
            'tanggal_mulai_kerja' => '2026-09-01',
            'durasi_kontrak_bulan' => 12,
            'tanggal_berakhir_kontrak' => '2027-08-31',
            'status_penempatan' => 'Bekerja',
            'tanggal_status_final' => null,
            'catatan_alasan' => null,
            'disetujui_oleh' => $this->checker()->id,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function visaId(): int
    {
        static $visaId = null;

        if ($visaId === null) {
            $visaId = (int) DB::table('jenis_visa')->insertGetId([
                'code' => 'W5_SSW',
                'label_id' => 'Visa W5',
                'label_ja' => 'W5ビザ',
                'kategori' => 'SSW',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $visaId;
    }

    public function test_paginate_requires_placement_view(): void
    {
        $this->expectException(AuthorizationException::class);

        app(PlacementQueryService::class)->paginate($this->noRoleUser());
    }

    public function test_list_page_opens_for_placement_roles(): void
    {
        $this->createPlacementContainer([
            'kode_kontainer' => 'P-2026-00001',
            'nama' => 'Kontainer April',
        ]);

        foreach ([$this->maker(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)
                ->get('/placements')
                ->assertOk()
                ->assertSee('Kontainer Penempatan')
                ->assertSee('Kontainer April');
        }
    }

    public function test_list_page_forbids_user_without_placement_view(): void
    {
        $this->actingAs($this->noRoleUser())->get('/placements')->assertForbidden();
    }

    public function test_list_page_redirects_guest(): void
    {
        $this->get('/placements')->assertRedirect();
    }

    public function test_create_page_forbids_non_execute_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->checker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/placements/create')->assertForbidden();
        }
    }

    public function test_review_page_forbids_non_review_roles(): void
    {
        foreach ([$this->noRoleUser(), $this->maker(), $this->superAdmin()] as $user) {
            $this->actingAs($user)->get('/placements/review')->assertForbidden();
        }
    }

    public function test_detail_and_edit_routes_enforce_ability(): void
    {
        $id = $this->createPlacementContainer();

        $this->actingAs($this->noRoleUser())->get('/placements/'.$id)->assertForbidden();
        $this->actingAs($this->noRoleUser())->get('/placements/'.$id.'/edit')->assertForbidden();
    }

    public function test_detail_returns_null_for_missing_container(): void
    {
        $this->assertNull(app(PlacementQueryService::class)->detail($this->maker(), 999999));
    }

    public function test_detail_page_renders_participants_and_pending_overlay(): void
    {
        $maker = $this->maker();
        $candidateId = $this->createCandidate();
        $containerId = $this->createPlacementContainer([
            'kode_kontainer' => 'P-2026-00002',
            'nama' => 'Kontainer Aktif',
            'status' => 'Aktif',
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
        ]);
        $this->addParticipant($containerId, $candidateId);
        DB::table('pending_request')->insert([
            'type' => 'PC_CANCEL_ACTIVE',
            'target_type' => 'placement_container',
            'target_id' => $containerId,
            'requested_by' => $maker->id,
            'reason_maker' => 'Tidak jadi',
            'checker_id' => null,
            'note_checker' => null,
            'payload' => json_encode(['snapshot' => ['version' => 0]]),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($maker)
            ->get('/placements/'.$containerId)
            ->assertOk()
            ->assertSee('Kontainer Aktif')
            ->assertSee('Budi Santoso')
            ->assertSee('Bekerja')
            ->assertSee('Pembatalan kontainer aktif');
    }

    public function test_detail_page_shows_not_found_state(): void
    {
        $this->actingAs($this->maker())
            ->get('/placements/999999')
            ->assertOk()
            ->assertSee('Kontainer tidak ditemukan');
    }

    public function test_detail_page_forbids_user_without_placement_view(): void
    {
        $containerId = $this->createPlacementContainer();

        $this->actingAs($this->noRoleUser())->get('/placements/'.$containerId)->assertForbidden();
    }

    public function test_archived_detail_is_read_only(): void
    {
        $containerId = $this->createPlacementContainer([
            'nama' => 'Kontainer Arsip',
            'status' => 'Arsip',
            'disetujui_oleh' => $this->checker()->id,
            'approved_at' => now(),
            'archived_at' => now(),
        ]);

        $this->actingAs($this->maker())
            ->get('/placements/'.$containerId)
            ->assertOk()
            ->assertSee('Kontainer Arsip')
            ->assertSee('hanya dapat dilihat');
    }

    public function test_livewire_index_renders_empty_state(): void
    {
        Livewire::actingAs($this->maker())
            ->test(PlacementIndex::class)
            ->assertSee('Belum ada kontainer');
    }
}
