<?php

namespace Tests\Feature\UI;

use App\Livewire\Candidate\CandidateDetail;
use App\Livewire\Candidate\CandidateForm;
use App\Livewire\Candidate\ReviewQueue;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Candidates\Public\CandidateQueryService;
use Modules\Candidates\Services\CandidateApprovalService;
use Modules\Candidates\Services\CandidateDraftService;
use Modules\Candidates\Services\CandidateSubmitService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ReviewRevisionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->negaraId = DB::table('negara')->insertGetId(['code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 1, 'is_active' => true]);
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

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
    }

    private function submittedCandidate(string $name = 'Budi Santoso', ?string $katakana = null): int
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $id = (int) app(CandidateDraftService::class)->createDraft($staff, [
            'nama_alphabet' => $name,
            'nama_katakana' => $katakana,
            'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $this->negaraId,
            'jenis_kelamin' => 'M',
        ])->id;

        app(CandidateSubmitService::class)->submit($staff, $id, ['version' => 0]);

        return $id;
    }

    // ----- Read contracts -----

    public function test_review_queue_is_approver_only_and_shows_main_nik_for_revisions(): void
    {
        $approver = $this->approver();
        $this->actingAs($approver);
        $mainId = $this->submittedCandidate();
        $mainNik = DB::table('candidate')->find($mainId)->nomor_induk;

        $rows = app(CandidateQueryService::class)->reviewQueue($approver);
        $this->assertSame(1, $rows->total());
        $this->assertSame('CANDIDATE_NEW', $rows->first()->pending_type);
        $this->assertSame($mainNik, $rows->first()->nomor_induk_display);

        $staff = $this->staff();
        $this->actingAs($staff);
        $this->expectException(AuthorizationException::class);
        app(CandidateQueryService::class)->reviewQueue($staff);
    }

    public function test_revision_diff_returns_payload_or_null(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $mainId = $this->submittedCandidate();

        $revisionId = (int) DB::table('candidate')->insertGetId([
            'nama_alphabet' => 'Budi Santoso', 'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $this->negaraId, 'jenis_kelamin' => 'M',
            'parent_candidate_id' => $mainId, 'status_approval' => 'Draft', 'version' => 0,
            'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payload = app(CandidateQueryService::class)->revisionDiff($staff, $revisionId);
        $this->assertNotNull($payload);
        $this->assertSame($mainId, (int) $payload['main']->id);
        $this->assertNull(app(CandidateQueryService::class)->revisionDiff($staff, $mainId));
    }

    // ----- Pages -----

    public function test_review_page_is_approver_only(): void
    {
        $this->actingAs($this->approver())
            ->get('/candidates/review')
            ->assertOk()
            ->assertSee('Antrian Tinjauan Kandidat');

        $this->actingAs($this->staff())->get('/candidates/review')->assertForbidden();
    }

    public function test_revision_page_renders_diff(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $mainId = $this->submittedCandidate();

        $revisionId = (int) DB::table('candidate')->insertGetId([
            'nama_alphabet' => 'Budi Santoso', 'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $this->negaraId, 'jenis_kelamin' => 'M',
            'nama_katakana' => 'ブディ・サントソ', 'parent_candidate_id' => $mainId,
            'status_approval' => 'Draft', 'version' => 0, 'created_by' => $staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/candidates/'.$revisionId.'/revision')
            ->assertOk()
            ->assertSee('Diff Revisi Kandidat')
            ->assertSee('ブディ・サントソ');
    }

    // ----- K4 decisions -----

    public function test_approve_and_reject_flow(): void
    {
        $approver = $this->approver();
        $mainId = $this->submittedCandidate();
        $this->actingAs($approver);
        $version = (int) DB::table('candidate')->find($mainId)->version;

        $pendingId = (int) DB::table('pending_request')->where('target_id', $mainId)->where('status', 'pending')->value('id');

        Livewire::test(ReviewQueue::class)
            ->call('approve', $pendingId, $version)
            ->assertSet('conflict', false);

        $this->assertSame('Disetujui', DB::table('candidate')->find($mainId)->status_approval);
        $this->assertSame('approved', DB::table('pending_request')->find($pendingId)->status);

        // Reject flow on a fresh candidate.
        $otherId = $this->submittedCandidate('Taro Yamada', 'タロ・ヤマダ');
        $this->actingAs($approver);
        $otherPending = (int) DB::table('pending_request')->where('target_id', $otherId)->where('status', 'pending')->value('id');
        $otherVersion = (int) DB::table('candidate')->find($otherId)->version;

        $component = Livewire::test(ReviewQueue::class)
            ->call('reject', $otherPending, $otherVersion);
        $this->assertSame('Catatan penolakan wajib diisi.', $component->get('actionError'));

        Livewire::test(ReviewQueue::class)
            ->set('rejectNotes.'.$otherPending, 'Data tidak lengkap')
            ->call('reject', $otherPending, $otherVersion)
            ->assertSet('conflict', false);

        $this->assertSame('Ditolak', DB::table('candidate')->find($otherId)->status_approval);
        $this->assertSame('Data tidak lengkap', DB::table('candidate')->find($otherId)->catatan_penolakan_terakhir);
    }

    public function test_approve_denies_self_decision(): void
    {
        $approver = $this->approver();
        $this->actingAs($approver);

        $candidateId = (int) DB::table('candidate')->insertGetId([
            'nama_alphabet' => 'Punya Approver', 'nomor_induk' => 'K-2026-99999', 'tanggal_lahir' => '1998-05-10',
            'kewarganegaraan_id' => $this->negaraId, 'jenis_kelamin' => 'M',
            'status_approval' => 'Menunggu Tinjauan-BARU', 'status_ketersediaan' => 'TERSEDIA',
            'version' => 0, 'created_by' => $approver->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pendingId = (int) DB::table('pending_request')->insertGetId([
            'type' => 'CANDIDATE_NEW', 'target_type' => 'candidate', 'target_id' => $candidateId,
            'requested_by' => $approver->id, 'reason_maker' => null, 'checker_id' => null,
            'note_checker' => null, 'payload' => null, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $component = Livewire::test(ReviewQueue::class)
            ->call('approve', $pendingId, 0);

        $this->assertSame('Anda tidak dapat memutuskan pengajuan sendiri.', $component->get('actionError'));
    }

    public function test_double_decision_surfaces_conflict(): void
    {
        $approver = $this->approver();
        $mainId = $this->submittedCandidate();
        $this->actingAs($approver);
        $version = (int) DB::table('candidate')->find($mainId)->version;
        $pendingId = (int) DB::table('pending_request')->where('target_id', $mainId)->where('status', 'pending')->value('id');

        Livewire::test(ReviewQueue::class)->call('approve', $pendingId, $version);

        Livewire::test(ReviewQueue::class)
            ->call('approve', $pendingId, $version)
            ->assertSet('conflict', true);
    }

    // ----- K5 revision flow -----

    public function test_full_revision_flow_start_edit_submit_approve(): void
    {
        $staff = $this->staff();
        $mainId = $this->submittedCandidate();

        // Approve the main candidate first (Checker).
        $approver = $this->approver();
        $this->actingAs($approver);
        $pendingId = (int) DB::table('pending_request')->where('target_id', $mainId)->where('status', 'pending')->value('id');
        app(CandidateApprovalService::class)->approve(
            $approver,
            $pendingId,
            ['version' => (int) DB::table('candidate')->find($mainId)->version],
        );
        $this->assertSame('Disetujui', DB::table('candidate')->find($mainId)->status_approval);
        $mainNik = DB::table('candidate')->find($mainId)->nomor_induk;

        // Start revision from detail (Maker).
        $this->actingAs($staff);

        Livewire::test(CandidateDetail::class, ['candidateId' => $mainId])
            ->call('startRevision')
            ->assertRedirect();

        $revisionId = (int) DB::table('candidate')->where('parent_candidate_id', $mainId)->value('id');
        $this->assertNotNull($revisionId);
        $this->assertSame('Draft', DB::table('candidate')->find($revisionId)->status_approval);
        $this->assertNull(DB::table('candidate')->find($revisionId)->nomor_induk);

        // Second revision while one is open → conflict.
        Livewire::test(CandidateDetail::class, ['candidateId' => $mainId])
            ->call('startRevision')
            ->assertSet('conflict', true);

        // Edit revision + submit (no change → server error).
        $noChange = Livewire::test(CandidateForm::class, ['candidate' => $revisionId])
            ->call('submitCandidate');
        $this->assertNotNull($noChange->get('serverErrors.revision'));

        // Change a field, save the draft, then submit the revision
        // (revision submit reads the saved row — no similarity gate).
        Livewire::test(CandidateForm::class, ['candidate' => $revisionId])
            ->set('formNamaKatakana', 'ブディ・サントソ')
            ->call('saveDraft')
            ->assertRedirect();

        Livewire::test(CandidateForm::class, ['candidate' => $revisionId])
            ->call('submitCandidate')
            ->assertRedirect();

        $revision = DB::table('candidate')->find($revisionId);
        $this->assertSame('Menunggu Tinjauan-REVISI', $revision->status_approval);
        $pending = DB::table('pending_request')->where('target_id', $revisionId)->where('status', 'pending')->first();
        $this->assertNotNull($pending);
        $this->assertSame('CANDIDATE_REVISION', $pending->type);

        // Approver decides.
        $approver = $this->approver();
        $this->actingAs($approver);

        Livewire::test(ReviewQueue::class)
            ->call('approve', (int) $pending->id, (int) $revision->version)
            ->assertSet('conflict', false);

        $main = DB::table('candidate')->find($mainId);
        $this->assertSame('ブディ・サントソ', $main->nama_katakana);
        $this->assertSame($mainNik, $main->nomor_induk);
        $this->assertSame('Diterapkan', DB::table('candidate')->find($revisionId)->status_approval);
    }
}
