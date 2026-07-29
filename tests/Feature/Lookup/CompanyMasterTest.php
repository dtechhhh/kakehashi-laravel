<?php

namespace Tests\Feature\Lookup;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\CompanyAdminService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class CompanyMasterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanState();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanState();
        } finally {
            parent::tearDown();
        }
    }

    public function test_super_admin_can_create_company_with_step_up_and_audit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $jpId = $this->seedJapan();
        $this->grantCreateStepUp();

        $row = app(CompanyAdminService::class)->create($admin, [
            'nama_ja' => '株式会社カケハシ',
            'nama_romaji' => 'Kakehashi KK',
            'nama_id' => 'Kakehashi',
            'alamat' => 'Tokyo',
        ]);

        $this->assertSame('株式会社カケハシ', $row->nama_ja);
        $this->assertTrue((bool) $row->is_active);
        $this->assertSame($jpId, (int) $row->negara_id);
        $this->assertDatabaseHas('perusahaan', [
            'id' => $row->id,
            'nama_ja' => '株式会社カケハシ',
            'nama_romaji' => 'Kakehashi KK',
            'nama_id' => 'Kakehashi',
            'negara_id' => $jpId,
            'is_active' => true,
        ]);

        $audit = AuditLog::query()->where('action_type', ActionType::COMPANY_CREATED)->sole();
        $this->assertSame($admin->getKey(), $audit->actor_id);
        $this->assertSame('perusahaan', $audit->entity_type);
        $this->assertSame((int) $row->id, (int) $audit->entity_id);
        $this->assertEquals([
            'perusahaan_id' => (int) $row->id,
            'nama_ja' => '株式会社カケハシ',
        ], $audit->detail);
    }

    public function test_create_step_up_scope_is_isolated_from_mutation_and_single_use(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->seedJapan();
        $service = app(CompanyAdminService::class);
        $id = $this->seedCompany();

        $this->grantCreateStepUp();
        $this->assertStepUpRequired(fn () => $service->update($admin, $id, [
            'nama_id' => 'Scope create tidak boleh update',
        ]));
        $this->assertStepUpRequired(fn () => $service->deactivate($admin, $id));

        $this->grantMutationStepUp($id);
        $this->assertStepUpRequired(fn () => $service->create($admin, [
            'nama_ja' => 'Scope mutasi tidak boleh create',
        ]));

        $this->grantCreateStepUp();
        $service->create($admin, ['nama_ja' => '一度きり']);
        $this->assertStepUpRequired(fn () => $service->create($admin, [
            'nama_ja' => 'Token sudah terpakai',
        ]));

        $this->assertDatabaseHas('perusahaan', ['nama_ja' => '一度きり']);
        $this->assertDatabaseMissing('perusahaan', ['nama_ja' => 'Scope mutasi tidak boleh create']);
        $this->assertDatabaseMissing('perusahaan', ['nama_ja' => 'Token sudah terpakai']);
        $this->assertDatabaseHas('perusahaan', [
            'id' => $id,
            'nama_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_create_defaults_negara_to_active_japan_and_rejects_missing_jp(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);

        $this->grantCreateStepUp();
        $this->assertValidationCode(
            fn () => $service->create($admin, ['nama_ja' => 'JP必須']),
            'COMPANY_DEFAULT_NEGARA_JP_MISSING',
        );

        $jpId = $this->seedJapan(active: false);
        $this->grantCreateStepUp();
        $this->assertValidationCode(
            fn () => $service->create($admin, ['nama_ja' => 'JP必須']),
            'COMPANY_DEFAULT_NEGARA_JP_MISSING',
        );

        DB::table('negara')->where('id', $jpId)->update(['is_active' => true]);
        $this->grantCreateStepUp();
        $row = $service->create($admin, ['nama_ja' => '既定日本']);

        $this->assertSame($jpId, (int) $row->negara_id);
        $this->assertDatabaseHas('perusahaan', [
            'nama_ja' => '既定日本',
            'negara_id' => $jpId,
        ]);
        $this->assertSame(0, DB::table('perusahaan')->where('nama_ja', 'JP必須')->count());
    }

    public function test_update_soft_disable_and_reactivate_preserve_row_with_audit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $id = $this->seedCompany();

        $this->grantMutationStepUp($id);
        $updated = $service->update($admin, $id, [
            'nama_id' => 'Perusahaan Diperbarui',
            'alamat' => 'Osaka',
        ]);

        $this->assertSame('Perusahaan Diperbarui', $updated->nama_id);
        $this->assertDatabaseHas('perusahaan', [
            'id' => $id,
            'nama_ja' => '既存会社',
            'nama_id' => 'Perusahaan Diperbarui',
            'alamat' => 'Osaka',
            'is_active' => true,
        ]);

        $updateAudit = AuditLog::query()->where('action_type', ActionType::COMPANY_UPDATED)->sole();
        $this->assertEquals([
            'perusahaan_id' => $id,
            'nama_ja' => '既存会社',
            'changed' => [
                'nama_id' => [null, 'Perusahaan Diperbarui'],
                'alamat' => [null, 'Osaka'],
            ],
        ], $updateAudit->detail);

        $this->grantMutationStepUp($id);
        $service->deactivate($admin, $id);

        $this->assertDatabaseHas('perusahaan', [
            'id' => $id,
            'nama_ja' => '既存会社',
            'is_active' => false,
        ]);
        $this->assertSame(1, DB::table('perusahaan')->where('id', $id)->count());

        $deactivated = AuditLog::query()->where('action_type', ActionType::COMPANY_DEACTIVATED)->sole();
        $this->assertEquals([
            'perusahaan_id' => $id,
            'nama_ja' => '既存会社',
            'changed' => ['is_active' => [true, false]],
        ], $deactivated->detail);

        $this->grantMutationStepUp($id);
        $service->reactivate($admin, $id);

        $this->assertDatabaseHas('perusahaan', ['id' => $id, 'is_active' => true]);
        $reactivated = AuditLog::query()->where('action_type', ActionType::COMPANY_REACTIVATED)->sole();
        $this->assertEquals([
            'perusahaan_id' => $id,
            'nama_ja' => '既存会社',
            'changed' => ['is_active' => [false, true]],
        ], $reactivated->detail);
    }

    public function test_company_mutation_requires_super_admin_and_step_up(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(CompanyAdminService::class)->create($staff, [
            'nama_ja' => '不可',
        ]);
    }

    public function test_super_admin_without_step_up_cannot_mutate_company(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->seedJapan();

        try {
            app(CompanyAdminService::class)->create($admin, [
                'nama_ja' => '再認証なし',
            ]);
            $this->fail('Expected STEPUP_REQUIRED.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }

        $this->assertDatabaseMissing('perusahaan', ['nama_ja' => '再認証なし']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::COMPANY_CREATED)->count());
    }

    public function test_update_deactivate_and_reactivate_require_step_up(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $id = $this->seedCompany();

        $this->assertStepUpRequired(fn () => $service->update($admin, $id, [
            'nama_id' => 'Tanpa step-up',
        ]));
        $this->assertStepUpRequired(fn () => $service->deactivate($admin, $id));

        DB::table('perusahaan')->where('id', $id)->update(['is_active' => false]);
        $this->assertStepUpRequired(fn () => $service->reactivate($admin, $id));

        $this->assertDatabaseHas('perusahaan', [
            'id' => $id,
            'nama_ja' => '既存会社',
            'is_active' => false,
        ]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_non_super_admin_cannot_update_deactivate_or_reactivate(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $id = $this->seedCompany();

        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::ASSISTANT_MANAGER);
        $this->actingAs($staff);
        $service = app(CompanyAdminService::class);

        $this->assertAuthorizationDenied(fn () => $service->update($staff, $id, [
            'nama_id' => 'Tidak boleh',
        ]));
        $this->assertAuthorizationDenied(fn () => $service->deactivate($staff, $id));
        $this->assertAuthorizationDenied(fn () => $service->reactivate($staff, $id));
    }

    public function test_nama_ja_is_required_and_blank_rejected(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $this->grantCreateStepUp();

        $this->assertValidationCode(
            fn () => $service->create($admin, ['nama_romaji' => 'Only romaji']),
            'nama_ja',
        );

        $this->grantCreateStepUp();
        $this->assertValidationCode(
            fn () => $service->create($admin, ['nama_ja' => '   ']),
            'nama_ja',
        );

        $this->assertSame(0, DB::table('perusahaan')->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_unknown_fields_and_inactive_parents_are_rejected(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $this->grantCreateStepUp();

        $this->assertValidationCode(
            fn () => $service->create($admin, [
                'nama_ja' => '会社',
                'code' => 'NOT_A_FIELD',
            ]),
            'COMPANY_FIELD_UNKNOWN',
        );

        $negaraId = $this->seedJapan(active: false);

        $this->grantCreateStepUp();
        $this->assertValidationCode(
            fn () => $service->create($admin, [
                'nama_ja' => '会社',
                'negara_id' => $negaraId,
            ]),
            'COMPANY_PARENT_INACTIVE',
        );

        $this->assertSame(0, DB::table('perusahaan')->count());
    }

    public function test_audit_failure_rolls_back_company_mutation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $id = $this->seedCompany();

        AuditLog::creating(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        try {
            $this->grantMutationStepUp($id);
            $service->update($admin, $id, ['nama_id' => 'Tidak boleh commit']);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('perusahaan', [
            'id' => $id,
            'nama_id' => null,
            'nama_ja' => '既存会社',
        ]);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::COMPANY_UPDATED)->count());
    }

    public function test_noop_update_and_active_toggle_skip_step_up_and_audit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(CompanyAdminService::class);
        $id = $this->seedCompany();

        $same = $service->update($admin, $id, ['nama_ja' => '既存会社']);
        $this->assertSame('既存会社', $same->nama_ja);

        DB::table('perusahaan')->where('id', $id)->update(['is_active' => false]);
        $noop = $service->deactivate($admin, $id);
        $this->assertFalse((bool) $noop->is_active);

        $this->assertSame(0, AuditLog::query()->count());
    }

    private function seedCompany(): int
    {
        return (int) DB::table('perusahaan')->insertGetId([
            'nama_ja' => '既存会社',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedJapan(bool $active = true): int
    {
        $existing = DB::table('negara')->where('code', 'JP')->value('id');

        if ($existing !== null) {
            DB::table('negara')->where('id', $existing)->update([
                'is_active' => $active,
                'updated_at' => now(),
            ]);

            return (int) $existing;
        }

        return (int) DB::table('negara')->insertGetId([
            'code' => 'JP',
            'label_id' => 'Jepang',
            'label_ja' => '日本',
            'sort_order' => 0,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->active()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);

        return $admin;
    }

    private function grantCreateStepUp(): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.'.CompanyAdminService::STEP_UP_ENTITY_CREATE.'.1' => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
    }

    private function grantMutationStepUp(int $entityId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.perusahaan.'.$entityId => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
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

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function cleanState(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException('CompanyMasterTest cleanup is testing-only.');
        }

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, model_has_roles, model_has_permissions, users, perusahaan, negara, bidang_industri_perusahaan '
            .'RESTART IDENTITY CASCADE'
        );
        AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
    }
}
