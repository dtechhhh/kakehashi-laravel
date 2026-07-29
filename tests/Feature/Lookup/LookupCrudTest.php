<?php

namespace Tests\Feature\Lookup;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\LookupAdminService;
use Modules\LookupData\Public\LookupService;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Tests\TestCase;

class LookupCrudTest extends TestCase
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

    public function test_super_admin_can_create_lookup_with_step_up_and_audit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->grantCreateStepUp('agama');

        app(LookupService::class)->options('agama', 'id');

        $row = app(LookupAdminService::class)->create($admin, 'agama', [
            'code' => 'CUSTOM_RELIGION',
            'label_id' => 'Agama Tambahan',
            'label_ja' => '追加宗教',
            'sort_order' => 99,
        ]);

        $this->assertSame('CUSTOM_RELIGION', $row->code);
        $this->assertDatabaseHas('agama', [
            'code' => 'CUSTOM_RELIGION',
            'label_id' => 'Agama Tambahan',
            'label_ja' => '追加宗教',
            'is_active' => true,
        ]);
        $this->assertTrue(Cache::store('redis')->missing('lookup:agama:id'));

        $audit = AuditLog::query()->where('action_type', ActionType::LOOKUP_CREATED)->sole();
        $this->assertSame($admin->getKey(), $audit->actor_id);
        $this->assertEquals([
            'lookup_category' => 'agama',
            'code' => 'CUSTOM_RELIGION',
            'label_id' => 'Agama Tambahan',
            'label_ja' => '追加宗教',
        ], $audit->detail);
    }

    public function test_lookup_mutation_requires_super_admin_permission_and_step_up(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seedAgama();

        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);

        $this->expectException(AuthorizationException::class);

        app(LookupAdminService::class)->create($staff, 'agama', [
            'code' => 'NOT_ALLOWED',
            'label_id' => 'Tidak Boleh',
            'label_ja' => '不可',
        ]);
    }

    public function test_super_admin_without_step_up_cannot_mutate_lookup(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        try {
            app(LookupAdminService::class)->create($admin, 'agama', [
                'code' => 'NO_STEP_UP',
                'label_id' => 'Tanpa Step-up',
                'label_ja' => '再認証なし',
            ]);
            $this->fail('Expected STEPUP_REQUIRED.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
            $this->assertSame('STEPUP_REQUIRED', $exception->getResponse()->getData(true)['message']);
        }

        $this->assertDatabaseMissing('agama', ['code' => 'NO_STEP_UP']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::LOOKUP_CREATED)->count());
    }

    public function test_code_is_immutable_and_duplicate_or_blank_values_are_rejected(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        $this->assertValidationCode(
            fn () => $service->update($admin, 'agama', $id, ['code' => 'CHANGED']),
            'LOOKUP_CODE_IMMUTABLE',
        );

        $this->grantCreateStepUp('agama');
        $this->assertValidationCode(
            fn () => $service->create($admin, 'agama', [
                'code' => 'ISLAM',
                'label_id' => 'Duplikat',
                'label_ja' => '重複',
            ]),
            'LOOKUP_CODE_EXISTS',
        );

        $this->grantCreateStepUp('agama');
        $this->assertValidationCode(
            fn () => $service->create($admin, 'agama', [
                'code' => ' ',
                'label_id' => 'Kosong',
                'label_ja' => '空',
            ]),
            'code',
        );
    }

    public function test_update_and_soft_disable_preserve_code_and_old_data(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        $this->grantMutationStepUp('agama', $id);
        $service->update($admin, 'agama', $id, [
            'label_id' => 'Islam diperbarui',
            'label_ja' => 'イスラム教 更新',
        ]);

        $this->assertDatabaseHas('agama', [
            'id' => $id,
            'code' => 'ISLAM',
            'label_id' => 'Islam diperbarui',
        ]);
        $updated = AuditLog::query()->where('action_type', ActionType::LOOKUP_UPDATED)->sole();
        $this->assertEquals([
            'lookup_category' => 'agama',
            'code' => 'ISLAM',
            'changed' => [
                'label_id' => ['Islam', 'Islam diperbarui'],
                'label_ja' => ['イスラム教', 'イスラム教 更新'],
            ],
        ], $updated->detail);

        app(LookupService::class)->options('agama', 'id');
        $this->grantMutationStepUp('agama', $id);
        $service->deactivate($admin, 'agama', $id);

        $this->assertDatabaseHas('agama', ['id' => $id, 'code' => 'ISLAM', 'is_active' => false]);
        $this->assertSame('Islam diperbarui', app(LookupService::class)->resolve('agama', 'ISLAM', 'id'));
        $this->assertArrayNotHasKey('ISLAM', app(LookupService::class)->options('agama', 'id'));

        $deactivated = AuditLog::query()->where('action_type', ActionType::LOOKUP_DEACTIVATED)->sole();
        $this->assertEquals([
            'lookup_category' => 'agama',
            'code' => 'ISLAM',
            'label_id' => 'Islam diperbarui',
            'label_ja' => 'イスラム教 更新',
        ], $deactivated->detail);

        $this->grantMutationStepUp('agama', $id);
        $service->reactivate($admin, 'agama', $id);

        $this->assertDatabaseHas('agama', ['id' => $id, 'code' => 'ISLAM', 'is_active' => true]);
        $this->assertArrayHasKey('ISLAM', app(LookupService::class)->options('agama', 'id'));
        $reactivated = AuditLog::query()->where('action_type', ActionType::LOOKUP_REACTIVATED)->sole();
        $this->assertEquals([
            'lookup_category' => 'agama',
            'code' => 'ISLAM',
            'changed' => ['is_active' => [false, true]],
        ], $reactivated->detail);
        $this->assertSame(1, DB::table('agama')->where('code', 'ISLAM')->count());
    }

    public function test_cache_flush_waits_for_the_outer_transaction_commit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        app(LookupService::class)->options('agama', 'id');
        $this->grantMutationStepUp('agama', $id);

        DB::transaction(function () use ($admin, $id, $service): void {
            $service->update($admin, 'agama', $id, [
                'label_id' => 'Islam committed',
                'label_ja' => 'イスラム教 commit',
            ]);

            $this->assertSame(
                'Islam',
                Cache::store('redis')->get('lookup:agama:id')['ISLAM'],
            );
        });

        $this->assertTrue(Cache::store('redis')->missing('lookup:agama:id'));
        $this->assertSame('Islam committed', app(LookupService::class)->options('agama', 'id')['ISLAM']);
    }

    public function test_audit_failure_rolls_back_lookup_and_preserves_cache(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        app(LookupService::class)->options('agama', 'id');
        AuditLog::creating(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        try {
            $this->grantMutationStepUp('agama', $id);
            $service->update($admin, 'agama', $id, [
                'label_id' => 'Tidak boleh commit',
                'label_ja' => 'commit しない',
            ]);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit failed', $exception->getMessage());
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseHas('agama', [
            'id' => $id,
            'label_id' => 'Islam',
            'label_ja' => 'イスラム教',
        ]);
        $this->assertSame('Islam', Cache::store('redis')->get('lookup:agama:id')['ISLAM']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::LOOKUP_UPDATED)->count());
    }

    public function test_update_deactivate_and_reactivate_require_step_up(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        $this->assertStepUpRequired(fn () => $service->update($admin, 'agama', $id, [
            'label_id' => 'Tanpa step-up',
        ]));
        $this->assertStepUpRequired(fn () => $service->deactivate($admin, 'agama', $id));

        DB::table('agama')->where('id', $id)->update(['is_active' => false]);
        $this->assertStepUpRequired(fn () => $service->reactivate($admin, 'agama', $id));

        $this->assertDatabaseHas('agama', ['id' => $id, 'label_id' => 'Islam', 'is_active' => false]);
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_non_super_admin_cannot_update_deactivate_or_reactivate_lookup(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seedAgama();

        $staff = User::factory()->active()->create();
        $staff->assignRole(Rbac::STAFF_INPUT);
        $this->actingAs($staff);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');

        $this->assertAuthorizationDenied(fn () => $service->update($staff, 'agama', $id, [
            'label_id' => 'Tidak boleh',
        ]));
        $this->assertAuthorizationDenied(fn () => $service->deactivate($staff, 'agama', $id));
        $this->assertAuthorizationDenied(fn () => $service->reactivate($staff, 'agama', $id));
    }

    public function test_update_deactivate_and_reactivate_reject_unknown_lookup_before_table_access(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);

        foreach ([
            'update' => fn () => $service->update($admin, 'not_a_lookup', 1, ['label_id' => 'Tidak boleh']),
            'deactivate' => fn () => $service->deactivate($admin, 'not_a_lookup', 1),
            'reactivate' => fn () => $service->reactivate($admin, 'not_a_lookup', 1),
        ] as $operation => $callback) {
            try {
                $callback();
                $this->fail("Expected {$operation} to reject the lookup category.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, AuditLog::query()->count());
        $this->assertDatabaseHas('agama', ['code' => 'ISLAM', 'is_active' => true]);
    }

    public function test_reader_old_snapshot_is_flushed_after_commit_without_stale_cache(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $id = DB::table('agama')->where('code', 'ISLAM')->value('id');
        $signalPrefix = 'test:lookup-cache:'.Str::uuid()->toString();
        [$process, $pipes] = $this->startCacheReader($id, 'Islam after race', $signalPrefix);

        try {
            $this->waitForRedisFlag($signalPrefix.':written');
            $this->assertSame('Islam', Cache::store('redis')->get('lookup:agama:id')['ISLAM']);

            $this->grantMutationStepUp('agama', $id);
            $service->update($admin, 'agama', $id, [
                'label_id' => 'Islam after race',
                'label_ja' => 'レース後のイスラム教',
            ]);

            $this->assertTrue(Cache::store('redis')->missing('lookup:agama:id'));
            $this->assertSame('Islam after race', app(LookupService::class)->options('agama', 'id')['ISLAM']);
        } finally {
            $cache = Cache::store('redis');
            $cache->put($signalPrefix.':stop', true, 30);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            foreach ([
                'lookup:agama:id',
                'lookup:agama:ja',
                'lookup:agama:lock',
                $signalPrefix.':written',
                $signalPrefix.':stop',
            ] as $key) {
                $cache->forget($key);
            }

            $this->assertSame(0, $status, "Cache reader failed: {$stderr}");
        }
    }

    public function test_create_step_up_scope_is_isolated_per_table_and_from_mutation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $sharedId = $this->seedNegaraWithSharedId();

        $this->grantCreateStepUp('agama');
        $this->assertStepUpRequired(fn () => $service->create($admin, 'negara', [
            'code' => 'ZZ',
            'label_id' => 'Negara silang',
            'label_ja' => '横断国',
        ]));
        $this->assertStepUpRequired(fn () => $service->update($admin, 'agama', $sharedId, [
            'label_id' => 'Create token tidak boleh update',
        ]));
        $this->assertStepUpRequired(fn () => $service->deactivate($admin, 'agama', $sharedId));

        $this->grantCreateStepUp('agama');
        $row = $service->create($admin, 'agama', [
            'code' => 'CUSTOM_ISOLATED',
            'label_id' => 'Agama isolasi',
            'label_ja' => '隔離宗教',
        ]);
        $this->assertStepUpRequired(fn () => $service->create($admin, 'agama', [
            'code' => 'TOKEN_USED',
            'label_id' => 'Token terpakai',
            'label_ja' => '使用済み',
        ]));

        $this->assertSame('CUSTOM_ISOLATED', $row->code);
        $this->assertDatabaseHas('agama', ['code' => 'CUSTOM_ISOLATED', 'is_active' => true]);
        $this->assertDatabaseMissing('agama', ['code' => 'TOKEN_USED']);
        $this->assertDatabaseMissing('negara', ['code' => 'ZZ']);
        $this->assertDatabaseHas('agama', [
            'id' => $sharedId,
            'label_id' => 'Islam',
            'is_active' => true,
        ]);
    }

    public function test_mutation_step_up_scope_is_isolated_per_table_and_from_create(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $service = app(LookupAdminService::class);
        $sharedId = $this->seedNegaraWithSharedId();

        $this->grantMutationStepUp('agama', $sharedId);
        $this->assertStepUpRequired(fn () => $service->update($admin, 'negara', $sharedId, [
            'label_id' => 'Mutasi silang dilarang',
        ]));
        $this->assertStepUpRequired(fn () => $service->deactivate($admin, 'negara', $sharedId));
        $this->assertStepUpRequired(fn () => $service->create($admin, 'agama', [
            'code' => 'FROM_MUTATION',
            'label_id' => 'Token mutasi tidak create',
            'label_ja' => '作成不可',
        ]));

        $this->grantMutationStepUp('agama', $sharedId);
        $service->update($admin, 'agama', $sharedId, [
            'label_id' => 'Islam scope ok',
            'label_ja' => 'スコープOK',
        ]);
        $this->assertStepUpRequired(fn () => $service->update($admin, 'agama', $sharedId, [
            'label_id' => 'Token mutasi terpakai',
        ]));

        $this->grantMutationStepUp('agama', $sharedId);
        $service->deactivate($admin, 'agama', $sharedId);
        $this->assertStepUpRequired(fn () => $service->reactivate($admin, 'agama', $sharedId));

        $this->grantMutationStepUp('agama', $sharedId);
        $service->reactivate($admin, 'agama', $sharedId);

        $this->assertDatabaseHas('agama', [
            'id' => $sharedId,
            'label_id' => 'Islam scope ok',
            'label_ja' => 'スコープOK',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('negara', [
            'id' => $sharedId,
            'code' => 'JP',
            'label_id' => 'Jepang',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('agama', ['code' => 'FROM_MUTATION']);
        $this->assertDatabaseMissing('agama', ['label_id' => 'Token mutasi terpakai']);
    }

    private function seedAgama(): void
    {
        DB::table('agama')->insert([
            'code' => 'ISLAM',
            'label_id' => 'Islam',
            'label_ja' => 'イスラム教',
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Insert negara sharing the same numeric primary key as the seeded agama row.
     * Tables are truncated with RESTART IDENTITY so first rows align without overriding identity.
     */
    private function seedNegaraWithSharedId(): int
    {
        $agamaId = (int) DB::table('agama')->where('code', 'ISLAM')->value('id');

        $negaraId = (int) DB::table('negara')->insertGetId([
            'code' => 'JP',
            'label_id' => 'Jepang',
            'label_ja' => '日本',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            $agamaId,
            $negaraId,
            'Fixture requires matching numeric IDs on agama and negara for cross-table scope isolation.',
        );

        return $agamaId;
    }

    private function superAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seedAgama();

        $admin = User::factory()->active()->create();
        $admin->assignRole(Rbac::SUPER_ADMIN);

        return $admin;
    }

    private function grantCreateStepUp(string $table): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.lookup_create:'.$table.'.1' => now()->addMinutes(5)->getTimestamp(),
            ],
        ]);
    }

    private function grantMutationStepUp(string $table, int $entityId): void
    {
        session([
            'stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.lookup:'.$table.'.'.$entityId => now()->addMinutes(5)->getTimestamp(),
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

    /**
     * @return array{resource, array<int, resource>}
     */
    private function startCacheReader(int $id, string $expectedLabel, string $signalPrefix): array
    {
        $worker = base_path('tests/workers/lookup_cache_reader.php');
        $command = sprintf(
            '%s %s agama %d %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($worker),
            $id,
            escapeshellarg($expectedLabel),
            escapeshellarg($signalPrefix),
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $environment = array_merge(getenv() ?: [], [
            'APP_ENV' => (string) app()->environment(),
        ]);
        $process = proc_open($command, $descriptors, $pipes, base_path(), $environment);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes];
    }

    private function waitForRedisFlag(string $key): void
    {
        $deadline = microtime(true) + 10;
        $cache = Cache::store('redis');

        while (! $cache->has($key) && microtime(true) < $deadline) {
            usleep(10000);
        }

        $this->assertTrue($cache->has($key), "Timed out waiting for Redis flag {$key}.");
    }

    private function cleanState(): void
    {
        if (! app()->environment('testing')) {
            throw new \RuntimeException('LookupCrudTest cleanup is testing-only.');
        }

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, model_has_roles, model_has_permissions, users, agama, negara '
            .'RESTART IDENTITY CASCADE'
        );

        $cache = Cache::store('redis');
        foreach ([
            'lookup:agama:id',
            'lookup:agama:ja',
            'lookup:agama:lock',
            'lookup:negara:id',
            'lookup:negara:ja',
            'lookup:negara:lock',
        ] as $key) {
            $cache->forget($key);
        }
    }
}
