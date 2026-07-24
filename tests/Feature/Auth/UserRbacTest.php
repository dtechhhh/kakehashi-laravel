<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Public\UserRbacService;
use Modules\Auth\Rbac;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_rbac_schema_matches_the_w1_baseline(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'email',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'must_change_password',
            'status_akun',
            'created_by',
            'deactivated_at',
            'deactivated_by',
        ]));

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} table is required.");
        }

        $index = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'users' AND indexname = 'uq_users_email_lower'"
        );

        $this->assertNotNull($index, 'users.email must be unique through lower(email).');
    }

    public function test_email_is_normalized_and_case_insensitive_unique(): void
    {
        $user = User::factory()->create(['email' => 'ADMIN@Example.COM']);

        $this->assertSame('admin@example.com', $user->email);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'admin@example.com']);
    }

    public function test_roles_and_permissions_are_seeded_without_assigning_guest_to_users(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing(Rbac::ROLES, Role::pluck('name')->all());
        $this->assertTrue(Role::findByName(Rbac::SUPER_ADMIN)->hasPermissionTo('users.assign_roles'));
        $this->assertTrue(Role::findByName(Rbac::STAFF_INPUT)->hasPermissionTo('candidate.submit'));
        $this->assertCount(0, Role::findByName(Rbac::GUEST)->permissions);
    }

    public function test_policy_baseline_allows_only_super_admin_to_manage_other_users(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $staff = User::factory()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $target = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('assignRoles', $target));
        $this->assertFalse(Gate::forUser($admin)->allows('assignRoles', $admin));
        $this->assertFalse(Gate::forUser($staff)->allows('assignRoles', $target));
    }

    public function test_inactive_super_admin_is_denied_by_policy_and_service(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create(['status_akun' => 'Nonaktif']);
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create();

        $this->assertFalse(Gate::forUser($admin)->allows('assignRoles', $target));

        $this->expectException(AuthorizationException::class);

        app(UserRbacService::class)->assignRoles($admin, $target, [Rbac::STAFF_INPUT]);
    }

    public function test_non_admin_cannot_call_user_rbac_service_directly(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);

        $this->expectException(AuthorizationException::class);

        app(UserRbacService::class)->deactivateUser($staff, User::factory()->create());
    }

    public function test_sensitive_account_fields_are_not_mass_assignable(): void
    {
        $user = new User;
        $user->fill([
            'status_akun' => 'Nonaktif',
            'must_change_password' => false,
            'deactivated_by' => 123,
        ]);

        $this->assertArrayNotHasKey('status_akun', $user->getAttributes());
        $this->assertArrayNotHasKey('must_change_password', $user->getAttributes());
        $this->assertArrayNotHasKey('deactivated_by', $user->getAttributes());
    }

    public function test_service_assigns_valid_roles_and_blocks_sod_violations(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create();
        $service = app(UserRbacService::class);

        $service->assignRoles($admin, $target, [Rbac::STAFF_INPUT, Rbac::ASSISTANT_MANAGER]);

        $this->assertTrue($target->fresh()->hasAllRoles([Rbac::STAFF_INPUT, Rbac::ASSISTANT_MANAGER]));

        foreach ([
            'USR_SOD_CANDIDATE' => [Rbac::STAFF_INPUT, Rbac::CANDIDATE_APPROVER],
            'USR_SOD_JOB' => [Rbac::ASSISTANT_MANAGER, Rbac::JOB_MANAGER],
            'USR_SOD_SUPERADMIN' => [Rbac::SUPER_ADMIN, Rbac::STAFF_INPUT],
            'USR_GUEST_ROLE' => [Rbac::GUEST],
        ] as $code => $roles) {
            $this->assertValidationCode(
                fn () => $service->assignRoles($admin, User::factory()->create(), $roles),
                $code
            );
        }
    }

    public function test_sod_failure_preserves_existing_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create();
        $target->assignRole(Rbac::STAFF_INPUT);

        $this->assertValidationCode(
            fn () => app(UserRbacService::class)->assignRoles(
                $admin,
                $target,
                [Rbac::STAFF_INPUT, Rbac::CANDIDATE_APPROVER]
            ),
            'USR_SOD_CANDIDATE'
        );

        $this->assertTrue($target->fresh()->hasExactRoles([Rbac::STAFF_INPUT]));
    }

    public function test_self_action_guards_block_role_deactivate_and_admin_password_reset(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $service = app(UserRbacService::class);

        $this->assertValidationCode(fn () => $service->assignRoles($admin, $admin, [Rbac::SUPER_ADMIN]), 'USR_SELF_ROLE');
        $this->assertValidationCode(fn () => $service->deactivateUser($admin, $admin), 'USR_SELF_DEACTIVATE');
        $this->assertValidationCode(fn () => $service->resetPasswordByAdmin($admin, $admin, 'temporary-password'), 'USR_SELF_RESET');
    }

    public function test_deactivate_and_reactivate_manage_account_metadata(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $actor = User::factory()->create();
        $actor->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create(['created_by' => $actor->getKey()]);
        $service = app(UserRbacService::class);

        $service->deactivateUser($actor, $target);

        $target->refresh();
        $this->assertSame('Nonaktif', $target->status_akun);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame($actor->getKey(), $target->deactivated_by);

        $service->reactivateUser($actor, $target);

        $target->refresh();
        $this->assertSame('Aktif', $target->status_akun);
        $this->assertNull($target->deactivated_at);
        $this->assertNull($target->deactivated_by);
    }

    public function test_admin_password_reset_enforces_password_policy(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create();
        $service = app(UserRbacService::class);

        $this->assertValidationCode(
            fn () => $service->resetPasswordByAdmin($admin, $target, 'weak'),
            'PWD_POLICY'
        );

        $service->resetPasswordByAdmin($admin, $target, 'TempResetPass1');

        $target->refresh();
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check('TempResetPass1', $target->password));
    }

    private function assertValidationCode(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail("Expected validation code {$code}.");
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($code, (string) json_encode($exception->errors()));
        }
    }
}
