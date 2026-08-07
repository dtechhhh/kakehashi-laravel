<?php

namespace Tests\Feature\UI;

use App\Livewire\Candidate\CandidateDetail;
use App\Livewire\StepUpModal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use PragmaRX\Google2FA\Google2FA;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

/**
 * W7-T2 — anonymization UI: Super Admin only, step-up gate, Wave 3 guard
 * revalidation, and no soft-delete/restore surface.
 */
class CandidateAnonymizeScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->negaraId = DB::table('negara')->insertGetId([
            'code' => 'ID', 'label_id' => 'Indonesia', 'label_ja' => 'インドネシア', 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private int $negaraId;

    private function superAdmin(): User
    {
        $user = User::factory()->active()->create();
        $user->assignRole(Rbac::SUPER_ADMIN);

        app(EnableTwoFactorAuthentication::class)($user, true);
        $user->refresh();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $user->refresh();

        return $user;
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

    private function createCandidate(array $overrides = []): int
    {
        $maker = $this->staff();
        $approver = $this->approver();

        return (int) DB::table('candidate')->insertGetId(array_merge([
            'nomor_induk' => 'K-2026-00001',
            'nama_alphabet' => 'Budi Santoso',
            'nama_katakana' => 'ブディ・サントソ',
            'tanggal_lahir' => Carbon::parse('1998-05-10'),
            'email' => 'budi@example.com',
            'phone' => '08123456789',
            'line_id' => 'budi.line',
            'kewarganegaraan_id' => $this->negaraId,
            'jenis_kelamin' => 'M',
            'status_pernikahan' => 'SINGLE',
            'status_ketersediaan' => 'TERSEDIA',
            'status_approval' => 'Disetujui',
            'version' => 1,
            'created_by' => $maker->id,
            'approved_by' => $approver->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function elevate(int $candidateId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::ANONYMIZE_PII.'.candidate.'.$candidateId => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
    }

    public function test_anonymize_button_is_visible_only_to_super_admin(): void
    {
        $candidateId = $this->createCandidate();

        foreach ([$this->staff(), $this->approver()] as $user) {
            $this->actingAs($user)
                ->get('/candidates/'.$candidateId)
                ->assertOk()
                ->assertDontSee(__('ui.candidate.anonymize'));
        }

        $this->actingAs($this->superAdmin())
            ->get('/candidates/'.$candidateId)
            ->assertOk()
            ->assertSee(__('ui.candidate.anonymize'));
    }

    public function test_click_without_elevation_opens_step_up_modal_and_changes_nothing(): void
    {
        $candidateId = $this->createCandidate();
        $this->actingAs($this->superAdmin());

        Livewire::test(CandidateDetail::class, ['candidateId' => $candidateId])
            ->call('anonymizeCandidate')
            ->assertDispatched('stepup.open');

        Livewire::test(StepUpModal::class)
            ->dispatch('stepup.open',
                action: StepUpAction::ANONYMIZE_PII,
                entityType: 'candidate',
                entityId: $candidateId,
            )
            ->assertSet('action', StepUpAction::ANONYMIZE_PII)
            ->assertSet('entityType', 'candidate')
            ->assertSet('entityId', $candidateId);

        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_ANONYMIZED)->count());
    }

    public function test_valid_elevation_executes_anonymization_and_redirects_to_list(): void
    {
        $candidateId = $this->createCandidate();
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->elevate($candidateId);

        Livewire::test(CandidateDetail::class, ['candidateId' => $candidateId])
            ->call('anonymizeCandidate')
            ->assertNotDispatched('stepup.open')
            ->assertRedirect(route('candidate.index'));

        $this->assertNotNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        $audit = AuditLog::query()->where('action_type', ActionType::CANDIDATE_ANONYMIZED)->sole();
        $this->assertSame($admin->getKey(), $audit->actor_id);
        $this->assertSame(Rbac::SUPER_ADMIN, $audit->actor_role_snapshot);
        $this->assertSame($candidateId, $audit->detail['candidate_id']);
    }

    public function test_step_up_success_with_wrong_scope_does_nothing(): void
    {
        $candidateId = $this->createCandidate();
        $this->actingAs($this->superAdmin());

        Livewire::test(CandidateDetail::class, ['candidateId' => $candidateId])
            ->call('anonymizeCandidate')
            ->dispatch('stepup.success',
                action: StepUpAction::MANAGE_LOOKUP_OR_COMPANY,
                entityType: 'candidate',
                entityId: $candidateId,
            );

        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
    }

    public function test_blocked_candidate_shows_error_and_keeps_pii(): void
    {
        $candidateId = $this->createCandidate();
        $maker = $this->staff();
        DB::table('pending_request')->insert([
            'type' => 'CANDIDATE_NEW',
            'target_type' => 'candidate',
            'target_id' => $candidateId,
            'requested_by' => $maker->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->superAdmin());
        $this->elevate($candidateId);

        Livewire::test(CandidateDetail::class, ['candidateId' => $candidateId])
            ->call('anonymizeCandidate')
            ->assertSet('actionError', __('ui.candidate.errors.PII_ACTIVE'));

        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::CANDIDATE_ANONYMIZED)->count());
    }

    public function test_soft_delete_and_restore_and_http_anonymize_routes_are_not_exposed(): void
    {
        $candidateId = $this->createCandidate();

        foreach (['candidate.soft-delete', 'candidate.restore', 'candidates.anonymize'] as $routeName) {
            $this->assertFalse(Route::has($routeName), "{$routeName} must not exist.");
        }

        $this->actingAs($this->superAdmin())
            ->postJson('/candidates/'.$candidateId.'/anonymize')
            ->assertNotFound();
        $this->actingAs($this->superAdmin())
            ->deleteJson('/candidates/'.$candidateId)
            ->assertStatus(405);
        $this->actingAs($this->superAdmin())
            ->postJson('/candidates/'.$candidateId.'/restore')
            ->assertNotFound();

        $this->assertNull(DB::table('candidate')->where('id', $candidateId)->value('pii_anonymized_at'));
    }
}
