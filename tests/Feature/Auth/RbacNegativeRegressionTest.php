<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Auth\Public\UserRbacService;
use Modules\Auth\Rbac;
use PHPUnit\Framework\Attributes\DataProvider;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

/**
 * W7-T1 — RBAC negative regression: role/permission matrix tanpa bypass,
 * route matrix, self-decision guard, dan step-up missing ditolak.
 */
class RbacNegativeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[DataProvider('rolePermissionProvider')]
    public function test_role_permission_matrix_has_no_bypass(string $role, array $allowed): void
    {
        $user = User::factory()->active()->create();
        $user->assignRole($role);

        foreach (Rbac::permissions() as $permission) {
            $this->assertSame(
                in_array($permission, $allowed, true),
                Gate::forUser($user)->check($permission),
                "{$role} harus ".($allowed ? '' : 'tidak ')."boleh {$permission}.",
            );
        }
    }

    public static function rolePermissionProvider(): array
    {
        return array_map(
            static fn (array $permissions): array => [
                array_search($permissions, Rbac::ROLE_PERMISSIONS, true),
                $permissions,
            ],
            Rbac::ROLE_PERMISSIONS,
        );
    }

    public function test_roleless_user_has_no_permission(): void
    {
        $roleless = User::factory()->active()->create();

        foreach (Rbac::permissions() as $permission) {
            $this->assertFalse(Gate::forUser($roleless)->check($permission));
        }
    }

    public function test_inactive_super_admin_is_denied_by_policy_and_http_layer(): void
    {
        $inactive = User::factory()->create(['status_akun' => 'Nonaktif']);
        $inactive->assignRole(Rbac::SUPER_ADMIN);

        foreach (['/candidates', '/jobs', '/placements', '/lookup', '/admin/users'] as $uri) {
            $this->actingAs($inactive);
            $response = $this->get($uri);
            $this->assertSame(403, $response->getStatusCode(), "{$uri} → {$response->getStatusCode()} → ".$response->headers->get('Location'));
        }
    }

    #[DataProvider('deniedRouteProvider')]
    public function test_every_role_is_forbidden_outside_its_policy(string $role, array $denied): void
    {
        if ($role !== 'no-role') {
            $user = User::factory()->active()->create();
            $user->assignRole($role);
            if ($user->requiresTwoFactorEnrollment()) {
                $user->forceFill([
                    'two_factor_secret' => 'enrolled-for-route-matrix',
                    'two_factor_confirmed_at' => now(),
                ])->save();
            }
            $this->actingAs($user);
        } else {
            $this->actingAs(User::factory()->active()->create());
        }

        foreach ($denied as $uri) {
            $this->get($uri)->assertForbidden("{$role} tidak boleh akses {$uri}.");
        }
    }

    public static function deniedRouteProvider(): array
    {
        return [
            Rbac::STAFF_INPUT => [
                Rbac::STAFF_INPUT,
                [
                    '/candidates/review',
                    '/jobs',
                    '/jobs/1',
                    '/jobs/create',
                    '/jobs/1/edit',
                    '/jobs/review',
                    '/placements',
                    '/placements/1',
                    '/placements/create',
                    '/placements/1/edit',
                    '/placements/review',
                    '/lookup',
                    '/lookup/requests',
                    '/companies',
                    '/admin/users',
                    '/audit',
                ],
            ],
            Rbac::CANDIDATE_APPROVER => [
                Rbac::CANDIDATE_APPROVER,
                [
                    '/candidates/create',
                    '/candidates/1/edit',
                    '/jobs',
                    '/jobs/1',
                    '/jobs/create',
                    '/jobs/1/edit',
                    '/jobs/review',
                    '/placements',
                    '/placements/1',
                    '/placements/create',
                    '/placements/1/edit',
                    '/placements/review',
                    '/lookup',
                    '/lookup/requests',
                    '/companies',
                    '/admin/users',
                    '/audit',
                ],
            ],
            Rbac::ASSISTANT_MANAGER => [
                Rbac::ASSISTANT_MANAGER,
                [
                    '/candidates',
                    '/candidates/1',
                    '/candidates/1/revision',
                    '/candidates/create',
                    '/candidates/1/edit',
                    '/candidates/review',
                    '/jobs/review',
                    '/placements/review',
                    '/lookup',
                    '/lookup/requests',
                    '/companies',
                    '/admin/users',
                    '/audit',
                ],
            ],
            Rbac::JOB_MANAGER => [
                Rbac::JOB_MANAGER,
                [
                    '/candidates',
                    '/candidates/1',
                    '/candidates/1/revision',
                    '/candidates/create',
                    '/candidates/1/edit',
                    '/candidates/review',
                    '/jobs/create',
                    '/jobs/1/edit',
                    '/placements/create',
                    '/placements/1/edit',
                    '/lookup',
                    '/lookup/requests',
                    '/companies',
                    '/admin/users',
                    '/audit',
                ],
            ],
            Rbac::SUPER_ADMIN => [
                Rbac::SUPER_ADMIN,
                [
                    '/candidates/create',
                    '/candidates/1/edit',
                    '/candidates/review',
                    '/jobs/create',
                    '/jobs/1/edit',
                    '/jobs/review',
                    '/placements/create',
                    '/placements/1/edit',
                    '/placements/review',
                ],
            ],
            'no-role' => [
                'no-role',
                [
                    '/candidates',
                    '/candidates/create',
                    '/candidates/review',
                    '/jobs',
                    '/jobs/create',
                    '/jobs/review',
                    '/placements',
                    '/placements/create',
                    '/placements/review',
                    '/lookup',
                    '/lookup/requests',
                    '/companies',
                    '/admin/users',
                    '/audit',
                ],
            ],
        ];
    }

    public function test_maker_cannot_decide_own_pending_request(): void
    {
        $maker = User::factory()->active()->create();
        $maker->assignRole(Rbac::JOB_MANAGER);
        $requestId = DB::table('pending_request')->insertGetId([
            'type' => PendingType::IC_CLOSE,
            'target_type' => 'interview_container',
            'target_id' => 1,
            'requested_by' => $maker->getKey(),
            'status' => PendingStatus::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = PendingRequest::query()->findOrFail($requestId);

        $this->assertFalse(Gate::forUser($maker)->allows('decide', $request));
    }

    public function test_sensitive_service_call_without_step_up_is_rejected_and_writes_nothing(): void
    {
        $admin = User::factory()->active()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);
        $target = User::factory()->create();
        $this->actingAs($admin);

        $this->assertStepUpRequired(
            fn () => app(UserRbacService::class)->assignRoles($admin, $target, [Rbac::STAFF_INPUT]),
        );

        $this->assertEmpty($target->fresh()->getRoleNames());
        $this->assertSame(0, AuditLog::query()->count());
    }

    private function assertStepUpRequired(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected STEPUP_REQUIRED.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }
    }
}
