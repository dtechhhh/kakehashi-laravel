<?php

namespace Tests\Feature\UI;

use App\Livewire\Admin\AuditLogViewer;
use App\Livewire\Admin\UserManagement;
use App\Livewire\StepUpModal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Modules\Auth\Public\UserRbacService;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use PragmaRX\Google2FA\Google2FA;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLogger;
use Shared\Audit\AuditLogQueryService;
use Tests\TestCase;

class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

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

    private function elevateForStepUp(int $userId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::USER_ROLE_OR_DEACTIVATE.'.user.'.$userId => now()->addSeconds(300)->getTimestamp(),
            ],
        ]);
    }

    // ----- Read contracts (service level) -----

    public function test_user_rbac_paginate_is_super_admin_only(): void
    {
        $admin = $this->superAdmin();
        $this->staff();
        $this->actingAs($admin);

        $result = app(UserRbacService::class)->paginate($admin);
        $this->assertSame(2, $result->total());
        $this->assertTrue($result->first()->relationLoaded('roles'));
    }

    public function test_user_rbac_paginate_rejects_non_admin(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(UserRbacService::class)->paginate($staff);
    }

    public function test_user_rbac_paginate_filters_by_search(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        User::factory()->active()->create(['name' => 'Zaidan Khusus']);
        $this->staff();

        $result = app(UserRbacService::class)->paginate($admin, 'zaidan');
        $this->assertSame(1, $result->total());
        $this->assertSame('Zaidan Khusus', $result->first()->name);
    }

    public function test_audit_query_service_filters_and_validates(): void
    {
        $admin = $this->superAdmin();

        app(AuditLogger::class)->record(
            actionType: ActionType::LOGIN_SUCCESS,
            entityType: 'user',
            entityId: 1,
            detail: ['note' => 'ok'],
            actorId: (int) $admin->id,
        );
        app(AuditLogger::class)->record(
            actionType: ActionType::USER_DEACTIVATED,
            entityType: 'user',
            entityId: 2,
            actorId: (int) $admin->id,
        );

        $service = app(AuditLogQueryService::class);

        $this->assertSame(2, $service->paginate($admin)->total());

        $filtered = $service->paginate($admin, ['action_type' => ActionType::LOGIN_SUCCESS->value]);
        $this->assertSame(1, $filtered->total());
        $this->assertSame('LOGIN_SUCCESS', $filtered->first()->action_type->value);
        $this->assertTrue($filtered->first()->relationLoaded('actor'));

        $this->expectException(ValidationException::class);
        $service->paginate($admin, ['action_type' => 'NOT_A_REAL_ACTION']);
    }

    public function test_audit_query_service_rejects_non_admin(): void
    {
        $this->expectException(AuthorizationException::class);

        app(AuditLogQueryService::class)->paginate($this->staff());
    }

    public function test_audit_actor_options_super_admin_only(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        User::factory()->active()->count(3)->create();

        $options = app(AuditLogQueryService::class)->actorOptions($admin);

        $this->assertCount(4, $options);
        $this->assertLessThanOrEqual(100, $options->count());
        $this->assertGreaterThan(0, $options->first()->id);
        $this->assertNotEmpty($options->first()->name);
        $this->assertFalse(isset($options->first()->email));

        $this->expectException(AuthorizationException::class);
        app(AuditLogQueryService::class)->actorOptions($this->staff());
    }

    // ----- Page access -----

    public function test_users_page_is_super_admin_only(): void
    {
        $admin = $this->superAdmin();
        User::factory()->active()->create(['name' => 'Anggota Staf']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('Kelola Akun')
            ->assertSee('Anggota Staf');
    }

    public function test_users_page_forbids_staff(): void
    {
        $this->actingAs($this->staff())->get('/admin/users')->assertForbidden();
    }

    public function test_users_page_redirects_guest(): void
    {
        $this->get('/admin/users')->assertRedirect();
    }

    public function test_audit_page_is_super_admin_only(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get('/audit')
            ->assertOk()
            ->assertSee('Audit Log');
    }

    public function test_audit_page_forbids_staff(): void
    {
        $this->actingAs($this->staff())->get('/audit')->assertForbidden();
    }

    // ----- Step-up modal component -----

    public function test_step_up_modal_opens_and_closes(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(StepUpModal::class)
            ->assertSet('open', false)
            ->dispatch('stepup.open', action: 'USER_ROLE_OR_DEACTIVATE', entityType: 'user', entityId: 1)
            ->assertSet('open', true)
            ->assertSet('entityId', 1)
            ->dispatch('stepup.close')
            ->assertSet('open', false);
    }

    // ----- S4 mutations via Livewire -----

    public function test_save_roles_requires_step_up_when_no_token(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('startEditRoles', (int) $target->id)
            ->set('roleDrafts.'.$target->id, [Rbac::CANDIDATE_APPROVER])
            ->call('saveRoles', (int) $target->id)
            ->assertDispatched('stepup.open')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertFalse($target->hasRole(Rbac::CANDIDATE_APPROVER));
    }

    public function test_user_rbac_find_for_admin_requires_active_super_admin(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();
        $target->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($admin);

        $resolved = app(UserRbacService::class)->findForAdmin($admin, (int) $target->id);
        $this->assertSame((int) $target->id, (int) $resolved->id);
        $this->assertSame([Rbac::STAFF_INPUT], $resolved->getRoleNames()->all());

        $staff = $this->staff();
        $this->actingAs($staff);
        try {
            app(UserRbacService::class)->findForAdmin($staff, (int) $target->id);
            $this->fail('staff must not resolve S4 targets');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $deactivated = User::factory()->create(['status_akun' => 'Nonaktif']);
        $deactivated->assignRole(Rbac::SUPER_ADMIN);
        $this->actingAs($deactivated);
        try {
            app(UserRbacService::class)->findForAdmin($deactivated, (int) $target->id);
            $this->fail('deactivated admin must not resolve S4 targets');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_start_edit_roles_prefills_current_roles_for_admin(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();
        $target->assignRole(Rbac::CANDIDATE_APPROVER);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('startEditRoles', (int) $target->id)
            ->assertSet('editingRolesFor', (int) $target->id)
            ->assertSet('roleDrafts.'.$target->id, [Rbac::CANDIDATE_APPROVER]);
    }

    public function test_s4_mutations_reject_staff_even_with_elevation_token(): void
    {
        $staff = $this->staff();
        $target = User::factory()->active()->create();
        $this->actingAs($staff);
        $this->elevateForStepUp((int) $target->id);
        $service = app(UserRbacService::class);

        foreach ([
            fn () => $service->assignRoles($staff, $target->fresh(), [Rbac::JOB_MANAGER]),
            fn () => $service->deactivateUser($staff, $target->fresh()),
            fn () => $service->reactivateUser($staff, $target->fresh()),
            fn () => $service->resetPasswordByAdmin($staff, $target->fresh(), 'Temp@Password123'),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('staff mutation must be rejected despite elevation token');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $target->refresh();
        $this->assertFalse($target->hasRole(Rbac::JOB_MANAGER));
        $this->assertSame('Aktif', $target->status_akun);
        $this->assertFalse($target->must_change_password);
    }

    public function test_save_roles_executes_with_valid_elevation(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();
        $this->elevateForStepUp((int) $target->id);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('startEditRoles', (int) $target->id)
            ->set('roleDrafts.'.$target->id, [Rbac::CANDIDATE_APPROVER])
            ->call('saveRoles', (int) $target->id)
            ->assertNotDispatched('stepup.open')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->hasRole(Rbac::CANDIDATE_APPROVER));
    }

    public function test_step_up_success_event_runs_staged_role_mutation(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();
        $this->elevateForStepUp((int) $target->id);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('startEditRoles', (int) $target->id)
            ->set('roleDrafts.'.$target->id, [Rbac::JOB_MANAGER])
            ->call('saveRoles', (int) $target->id)
            ->dispatch('stepup.success',
                action: StepUpAction::USER_ROLE_OR_DEACTIVATE,
                entityType: 'user',
                entityId: (int) $target->id,
            )
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->hasRole(Rbac::JOB_MANAGER));
    }

    public function test_deactivate_requires_step_up_but_reactivate_does_not(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('deactivate', (int) $target->id)
            ->assertDispatched('stepup.open');

        $target->refresh();
        $this->assertSame('Aktif', $target->status_akun);

        $this->elevateForStepUp((int) $target->id);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('deactivate', (int) $target->id)
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertSame('Nonaktif', $target->status_akun);

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('reactivate', (int) $target->id)
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertSame('Aktif', $target->status_akun);
    }

    public function test_admin_reset_password_needs_no_step_up_and_forces_change(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('resetPassword', (int) $target->id)
            ->set('temporaryPassword', 'Temp@Password123')
            ->call('confirmResetPassword', (int) $target->id)
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->must_change_password);
    }

    public function test_reset_password_policy_violation_shows_error(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->active()->create();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('resetPassword', (int) $target->id)
            ->set('temporaryPassword', 'short')
            ->call('confirmResetPassword', (int) $target->id)
            ->assertHasNoErrors();

        $component = Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->call('resetPassword', (int) $target->id)
            ->set('temporaryPassword', 'short')
            ->call('confirmResetPassword', (int) $target->id);

        $this->assertNotNull($component->get('actionError'));

        $target->refresh();
        $this->assertFalse($target->must_change_password);
    }

    // ----- S5 viewer -----

    public function test_audit_viewer_renders_logs_without_secrets(): void
    {
        $admin = $this->superAdmin();
        $other = User::factory()->active()->create(['name' => 'Aktor Lain']);
        $other->assignRole(Rbac::CANDIDATE_APPROVER);

        app(AuditLogger::class)->record(
            actionType: ActionType::PASSWORD_RESET_BY_ADMIN,
            entityType: 'user',
            entityId: (int) $other->id,
            detail: ['target_user_id' => $other->id],
            actorId: (int) $admin->id,
        );

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->assertOk()
            ->assertSee('PASSWORD_RESET_BY_ADMIN')
            ->assertDontSee('password')
            ->assertDontSee($other->email);
    }

    public function test_audit_viewer_filters_by_action_type(): void
    {
        $admin = $this->superAdmin();

        app(AuditLogger::class)->record(ActionType::LOGOUT, 'user', 1, actorId: (int) $admin->id);

        Livewire::actingAs($admin)
            ->test(AuditLogViewer::class)
            ->set('actionType', ActionType::LOGOUT->value)
            ->assertOk()
            ->assertSee('LOGOUT');
    }

    public function test_audit_viewer_rejects_staff(): void
    {
        Livewire::actingAs($this->staff())
            ->test(AuditLogViewer::class)
            ->assertForbidden();
    }
}
