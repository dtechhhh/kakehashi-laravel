<?php

namespace Tests\Feature\UI;

use App\Livewire\Candidate\CandidateDetail;
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
use Modules\Candidates\Public\CandidateQueryService;
use PragmaRX\Google2FA\Google2FA;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class CandidateScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedLookupFixtures();
    }

    private int $negaraId;

    private int $jenisDokumenId;

    private function seedLookupFixtures(): void
    {
        $this->negaraId = DB::table('negara')->insertGetId(['code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 1, 'is_active' => true]);
        $this->jenisDokumenId = DB::table('jenis_dokumen')->insertGetId(['code' => 'PASSPORT', 'label_id' => 'Paspor', 'label_ja' => 'パスポート', 'sort_order' => 1, 'is_active' => true]);
    }

    private function staff(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::STAFF_INPUT);

        return $user;
    }

    private function approver(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::CANDIDATE_APPROVER);

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function noRoleUser(): User
    {
        return User::factory()->active()->create();
    }

    private function createCandidate(array $overrides = []): int
    {
        $maker = $this->staff();

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => Carbon::parse('1998-05-10'),
            'tempat_lahir_kota_id' => null,
            'alamat_detail' => 'Jl. Melati No. 1',
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'line_id' => null,
            'kewarganegaraan_id' => $this->negaraId,
            'asal_rekrutmen_id' => null,
            'agama_id' => null,
            'alamat_provinsi_id' => null,
            'alamat_kota_kabupaten_id' => null,
            'alamat_kecamatan_id' => null,
            'jenis_kelamin' => 'M',
            'status_pernikahan' => 'SINGLE',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Draft',
            'parent_candidate_id' => null,
            'version' => 0,
            'created_by' => $maker->id,
            'approved_by' => null,
            'catatan_penolakan_terakhir' => null,
            'catatan_tambahan' => null,
            'deleted_at' => null,
            'pii_anonymized_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function addDocument(int $candidateId, string $url = 'https://drive.google.com/file/d/XYZ/view'): int
    {
        return (int) DB::table('candidate_document')->insertGetId([
            'candidate_id' => $candidateId,
            'jenis_dokumen_id' => $this->jenisDokumenId,
            'url_dokumen' => $url,
            'nama_file' => 'paspor.pdf',
            'catatan' => null,
            'uploaded_by' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addPhoto(int $candidateId): void
    {
        DB::table('candidate_photo')->insert([
            'candidate_id' => $candidateId,
            'object_key' => 'photos/'.$candidateId.'/face.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'uploaded_by' => $this->staff()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addPending(int $candidateId): void
    {
        DB::table('pending_request')->insert([
            'type' => 'CANDIDATE_NEW',
            'target_type' => 'candidate',
            'target_id' => $candidateId,
            'requested_by' => $this->staff()->id,
            'reason_maker' => null,
            'checker_id' => null,
            'note_checker' => null,
            'payload' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ----- Query service -----

    public function test_paginate_requires_candidate_view(): void
    {
        $this->actingAs($this->noRoleUser());

        $this->expectException(AuthorizationException::class);

        app(CandidateQueryService::class)->paginate($this->noRoleUser());
    }

    public function test_paginate_filters_and_excludes_revisions_and_anonymized(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $main = $this->createCandidate();
        $approverUser = $this->approver();
        $this->createCandidate([
            'nama_alphabet' => 'Siti Aminah',
            'tanggal_lahir' => Carbon::parse('1990-01-01'),
            'status_ketersediaan' => 'SEDANG_DIPAKAI',
            'status_approval' => 'Disetujui',
            'approved_by' => $approverUser->id,
        ]);
        $this->createCandidate(['nama_alphabet' => 'Anonim', 'pii_anonymized_at' => now()]);
        $this->createCandidate(['nama_alphabet' => 'Revisi Budi', 'parent_candidate_id' => $main, 'status_approval' => 'Menunggu Tinjauan-REVISI']);

        $service = app(CandidateQueryService::class);

        $this->assertSame(2, $service->paginate($staff)->total());
        $this->assertSame(1, $service->paginate($staff, ['search' => 'siti'])->total());
        $this->assertSame(1, $service->paginate($staff, ['status_approval' => 'Draft'])->total());
        $this->assertSame(1, $service->paginate($staff, ['status_ketersediaan' => 'TERSEDIA'])->total());
        $this->assertSame(1, $service->paginate($staff, ['age_from' => 30, 'age_to' => 40])->total());
        $this->assertSame(2, $service->paginate($staff, ['sort' => 'not_real_column', 'direction' => 'desc'])->total());
    }

    public function test_detail_returns_payload_and_refuses_anonymized(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $id = $this->createCandidate();
        $this->addDocument($id);
        $this->addPhoto($id);
        $this->addPending($id);

        $payload = app(CandidateQueryService::class)->detail($staff, $id);
        $this->assertNotNull($payload);
        $this->assertSame('Budi Santoso', $payload['candidate']->nama_alphabet);
        $this->assertCount(1, $payload['children']['candidate_document']);
        $this->assertNotNull($payload['photo']);
        $this->assertTrue($payload['activePending']);

        $anonymized = $this->createCandidate(['nama_alphabet' => 'Rahasia', 'pii_anonymized_at' => now()]);
        $this->assertNull(app(CandidateQueryService::class)->detail($staff, $anonymized));

        $this->assertNull(app(CandidateQueryService::class)->detail($staff, 999999));
    }

    public function test_detail_requires_candidate_view(): void
    {
        $id = $this->createCandidate();
        $this->actingAs($this->noRoleUser());

        $this->expectException(AuthorizationException::class);

        app(CandidateQueryService::class)->detail($this->noRoleUser(), $id);
    }

    // ----- Pages -----

    public function test_list_page_renders_for_staff_and_approver(): void
    {
        $this->createCandidate(['status_approval' => 'Menunggu Tinjauan-BARU']);

        $this->actingAs($this->staff())
            ->get('/candidates')
            ->assertOk()
            ->assertSee('Kandidat')
            ->assertSee('Budi Santoso')
            ->assertSee('Menunggu Tinjauan-BARU');

        $this->actingAs($this->approver())
            ->get('/candidates')
            ->assertOk();
    }

    public function test_list_page_forbids_user_without_candidate_view(): void
    {
        $this->actingAs($this->noRoleUser())->get('/candidates')->assertForbidden();
    }

    public function test_list_page_redirects_guest(): void
    {
        $this->get('/candidates')->assertRedirect();
    }

    public function test_list_page_does_not_expose_drive_urls(): void
    {
        $id = $this->createCandidate();
        $this->addDocument($id, 'https://drive.google.com/file/d/SECRET/view');

        $this->actingAs($this->staff())
            ->get('/candidates')
            ->assertOk()
            ->assertDontSee('drive.google.com');

        $this->actingAs($this->staff())
            ->get('/candidates/'.$id)
            ->assertOk()
            ->assertDontSee('drive.google.com')
            ->assertSee('Lihat dokumen');
    }

    public function test_detail_page_renders_sections_and_not_found_state(): void
    {
        $id = $this->createCandidate();
        $this->addDocument($id);

        $this->actingAs($this->staff())
            ->get('/candidates/'.$id)
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('ブディ・サントソ')
            ->assertSee('Paspor');

        $this->actingAs($this->staff())
            ->get('/candidates/999999')
            ->assertOk()
            ->assertSee('Halaman tidak ditemukan');
    }

    public function test_detail_page_forbids_user_without_candidate_view(): void
    {
        $id = $this->createCandidate();

        $this->actingAs($this->noRoleUser())->get('/candidates/'.$id)->assertForbidden();
    }

    // ----- Photo (signed URL) -----

    public function test_photo_url_is_signed_and_authorized(): void
    {
        $staff = $this->staff();
        $id = $this->createCandidate();
        $this->addPhoto($id);

        Livewire::actingAs($staff)
            ->test(CandidateDetail::class, ['candidateId' => $id])
            ->call('loadPhoto')
            ->assertSet('photoUrl', fn (?string $url): bool => is_string($url) && str_contains($url, 'r2.local/signed'));
    }

    public function test_photo_missing_state(): void
    {
        $staff = $this->staff();
        $id = $this->createCandidate();

        Livewire::actingAs($staff)
            ->test(CandidateDetail::class, ['candidateId' => $id])
            ->call('loadPhoto')
            ->assertSet('photoMissing', true);
    }

    // ----- Document reveal -----

    public function test_reveal_document_audits_and_returns_url(): void
    {
        $staff = $this->staff();
        $id = $this->createCandidate();
        $documentId = $this->addDocument($id, 'https://drive.google.com/file/d/REVEALED/view');

        Livewire::actingAs($staff)
            ->test(CandidateDetail::class, ['candidateId' => $id])
            ->call('revealDocument', $documentId)
            ->assertDispatched('kakehashi-open-url', url: 'https://drive.google.com/file/d/REVEALED/view')
            ->assertSet('actionError', null);

        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED->value)->count());
        $audit = AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED->value)->first();
        $this->assertSame($id, $audit->entity_id);
        $this->assertArrayNotHasKey('url', $audit->detail);
    }

    public function test_reveal_document_rejects_unknown_document_without_audit(): void
    {
        $staff = $this->staff();
        $id = $this->createCandidate();

        Livewire::actingAs($staff)
            ->test(CandidateDetail::class, ['candidateId' => $id])
            ->call('revealDocument', 9999)
            ->assertNotDispatched('kakehashi-open-url')
            ->assertSet('actionError', 'Dokumen tidak dapat dibuka. Hubungi Super Admin.');

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED->value)->count());
    }

    /**
     * Reveal authorization for users without candidate.view is enforced by the
     * route middleware (403) and by DocumentLinkAuditService (existing
     * DocumentLinkAuditTest covers no-permission → 403 without audit).
     */
    public function test_reveal_document_is_blocked_at_http_layer_for_unauthorized_user(): void
    {
        $id = $this->createCandidate();
        $this->addDocument($id);

        $this->actingAs($this->noRoleUser())
            ->get('/candidates/'.$id)
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::IDENTITY_DOC_VIEWED->value)->count());
    }
}
