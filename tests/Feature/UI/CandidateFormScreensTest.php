<?php

namespace Tests\Feature\UI;

use App\Livewire\Candidate\CandidateForm;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Candidates\Services\CandidateDraftService;
use Tests\TestCase;

class CandidateFormScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seedLookupFixtures();
    }

    private function seedLookupFixtures(): void
    {
        $this->negaraId = DB::table('negara')->insertGetId(['code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 1, 'is_active' => true]);
        DB::table('jenis_dokumen')->insertGetId(['code' => 'PASSPORT', 'label_id' => 'Paspor', 'label_ja' => 'パスポート', 'sort_order' => 1, 'is_active' => true]);
        DB::table('tingkat_pendidikan')->insertGetId(['code' => 'SMA', 'label_id' => 'SMA', 'label_ja' => '高校', 'sort_order' => 1, 'is_active' => true]);
    }

    private int $negaraId;

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

        return $user;
    }

    private function minimalFields(): array
    {
        return [
            'formNamaAlphabet' => 'Budi Santoso',
            'formTanggalLahir' => '1998-05-10',
            'formKewarganegaraanId' => (string) $this->negaraId,
            'formJenisKelamin' => 'M',
        ];
    }

    private function draftCandidate(): int
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        return (int) app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => 'Budi Santoso',
            'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $this->negaraId,
            'jenis_kelamin' => 'M',
        ])->id;
    }

    // ----- Page access -----

    public function test_create_page_is_staff_only(): void
    {
        $this->actingAs($this->staff())
            ->get('/candidates/create')
            ->assertOk()
            ->assertSee('Tambah Kandidat');

        $this->actingAs($this->approver())->get('/candidates/create')->assertForbidden();
    }

    public function test_edit_page_renders_and_prefills(): void
    {
        $id = $this->draftCandidate();

        $this->actingAs($this->staff())
            ->get('/candidates/'.$id.'/edit')
            ->assertOk()
            ->assertSee('Ubah Kandidat');
    }

    public function test_edit_page_forbids_approver(): void
    {
        $id = $this->draftCandidate();

        $this->actingAs($this->approver())->get('/candidates/'.$id.'/edit')->assertForbidden();
    }

    // ----- Draft save -----

    public function test_save_draft_creates_draft_without_nik_or_pending(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('saveDraft')
            ->assertRedirect();

        $row = DB::table('candidate')->where('nama_alphabet', 'Budi Santoso')->first();
        $this->assertNotNull($row);
        $this->assertSame('Draft', $row->status_approval);
        $this->assertNull($row->nomor_induk);
        $this->assertSame(0, $row->version);
        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_save_draft_requires_required_fields(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $component = Livewire::test(CandidateForm::class)
            ->set('formNamaAlphabet', '')
            ->call('saveDraft');

        $this->assertNotNull($component->get('serverErrors.nama_alphabet'));
        $this->assertSame(0, DB::table('candidate')->count());
    }

    public function test_save_draft_updates_with_version_and_conflicts_on_stale_version(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $id = $this->draftCandidate();

        $component = Livewire::test(CandidateForm::class, ['candidate' => $id])
            ->assertSet('version', 0)
            ->set('formNamaAlphabet', 'Budi Santoso Baru')
            ->call('saveDraft')
            ->assertRedirect();

        $this->assertSame('Budi Santoso Baru', DB::table('candidate')->find($id)->nama_alphabet);
        $this->assertSame(1, DB::table('candidate')->find($id)->version);

        // Simulate another actor saving after this form was loaded: held version 1 vs DB 5.
        $held = Livewire::test(CandidateForm::class, ['candidate' => $id]);
        DB::table('candidate')->where('id', $id)->update(['version' => 5]);

        $conflict = $held
            ->set('formNamaAlphabet', 'Ubah Lagi')
            ->call('saveDraft')
            ->assertSet('conflict', true);

        $this->assertTrue($conflict->get('conflict'));
        $this->assertSame('Budi Santoso Baru', DB::table('candidate')->find($id)->nama_alphabet);
    }

    public function test_documents_accept_only_private_drive_urls(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $component = Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('addRow', 'documents')
            ->set('documents.0.jenis_dokumen_id', '1')
            ->set('documents.0.url_dokumen', 'https://example.com/not-drive.pdf')
            ->call('saveDraft');

        $this->assertArrayHasKey('url_dokumen', $component->get('serverErrors'));
        $this->assertSame(0, DB::table('candidate')->count());
    }

    // ----- Submit -----

    public function test_submit_assigns_nik_and_starts_review(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('submitCandidate')
            ->assertRedirect();

        $row = DB::table('candidate')->where('nama_alphabet', 'Budi Santoso')->first();
        $this->assertNotNull($row->nomor_induk);
        $this->assertMatchesRegularExpression('/^K-\d{4}-\d{5}$/', $row->nomor_induk);
        $this->assertSame('Menunggu Tinjauan-BARU', $row->status_approval);
        $this->assertSame(1, DB::table('pending_request')->count());
    }

    public function test_submit_returns_soft_similarity_warning(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        // Candidate A submitted first.
        $first = Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('submitCandidate');
        $firstId = (int) DB::table('candidate')->where('nama_alphabet', 'Budi Santoso')->value('id');

        // Candidate B identical identity → soft warning.
        Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('submitCandidate')
            ->assertSet('similarityMatches', fn (mixed $matches): bool => is_array($matches) && count($matches) >= 1);

        $b = DB::table('candidate')->orderByDesc('id')->first();
        $this->assertSame('Draft', $b->status_approval);
        $this->assertNull($b->nomor_induk);

        // Confirm continues and submits.
        Livewire::test(CandidateForm::class)
            ->set($this->minimalFields())
            ->call('submitCandidate')
            ->call('confirmSimilarityAndSubmit')
            ->assertRedirect();

        $b = DB::table('candidate')->orderByDesc('id')->first();
        $this->assertNotNull($b->nomor_induk);
        $this->assertSame('Menunggu Tinjauan-BARU', $b->status_approval);
    }

    // ----- Photo -----

    public function test_photo_upload_requires_draft_first(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $component = Livewire::test(CandidateForm::class)
            ->set('photoFile', UploadedFile::fake()->image('face.jpg'));

        $this->assertSame('Simpan draft dahulu sebelum mengunggah foto.', $component->get('photoError'));
    }

    public function test_photo_upload_after_draft_succeeds(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $id = $this->draftCandidate();

        Livewire::test(CandidateForm::class, ['candidate' => $id])
            ->set('photoFile', UploadedFile::fake()->image('face.jpg', 800, 600))
            ->assertSet('photoError', null)
            ->assertSet('version', 1);

        $photo = DB::table('candidate_photo')->where('candidate_id', $id)->first();
        $this->assertNotNull($photo);
        $this->assertSame('image/jpeg', $photo->mime_type);
        $this->assertSame(1, DB::table('candidate')->find($id)->version);
    }

    // ----- Inline lookup request -----

    public function test_inline_lookup_request_creates_lookup_request(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        Livewire::test(CandidateForm::class)
            ->call('openLookupRequest', 'negara')
            ->set('lookupLabelId', 'Vietnam')
            ->set('lookupLabelJa', 'ベトナム')
            ->call('submitLookupRequest')
            ->assertSet('lookupRequested', true);

        $this->assertSame(1, DB::table('lookup_request')->where('lookup_table', 'negara')->count());
        $row = DB::table('lookup_request')->where('lookup_table', 'negara')->first();
        $this->assertSame('VI', $row->code);
        $this->assertSame('pending', $row->status);
    }
}
