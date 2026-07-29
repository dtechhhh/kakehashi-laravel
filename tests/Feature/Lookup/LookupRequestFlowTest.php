<?php

namespace Tests\Feature\Lookup;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Rbac;
use Modules\Auth\StepUpAction;
use Modules\LookupData\Public\LookupRequestService;
use Modules\LookupData\Public\LookupService;
use Shared\Approval\PendingRequest;
use Shared\Approval\PendingType;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;
use Throwable;

class LookupRequestFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanFixtures();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_staff_and_assistant_can_submit_lookup_and_super_admin_is_notified(): void
    {
        $admin = $this->user(Rbac::SUPER_ADMIN);

        foreach ([Rbac::STAFF_INPUT, Rbac::ASSISTANT_MANAGER] as $role) {
            $maker = $this->user($role);
            $this->actingAs($maker);

            $request = $this->service()->submitLookup($maker, [
                'lookup_table' => 'agama',
                'code' => 'REQUEST_'.strtoupper(str_replace(' ', '_', $role)),
                'label_id' => 'Nilai baru',
                'label_ja' => '新しい値',
            ]);

            $this->assertSame('pending', $request->status);
            $this->assertDatabaseHas('notifications', [
                'type' => ActionType::LOOKUP_REQUEST_SUBMITTED->value,
                'notifiable_id' => $admin->getKey(),
            ]);
        }

        $this->assertSame(2, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_SUBMITTED)->count());
        $this->assertDatabaseCount('pending_request', 0);
    }

    public function test_only_active_assistant_can_submit_company_request(): void
    {
        $assistant = $this->user(Rbac::ASSISTANT_MANAGER);
        $this->actingAs($assistant);

        $request = $this->service()->submitCompany($assistant, ['nama_ja' => '株式会社テスト']);

        $this->assertSame('pending', $request->status);
        $this->assertDatabaseHas('company_request', ['id' => $request->id, 'requested_by' => $assistant->getKey()]);
        $this->assertValidation(fn () => $this->service()->submitCompany($assistant, ['nama_ja' => '   ']));

        foreach ([Rbac::STAFF_INPUT, Rbac::SUPER_ADMIN] as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor);
            $this->assertAuthorizationDenied(fn () => $this->service()->submitCompany($actor, ['nama_ja' => '不可']));
        }

        $inactive = $this->user(Rbac::ASSISTANT_MANAGER, 'Nonaktif');
        $this->actingAs($inactive);
        $this->assertAuthorizationDenied(fn () => $this->service()->submitLookup($inactive, $this->lookupPayload('INACTIVE')));

        $this->actingAs($admin = $this->user(Rbac::SUPER_ADMIN));
        $this->assertAuthorizationDenied(fn () => $this->service()->submitLookup($admin, $this->lookupPayload('ADMIN_DENIED')));
    }

    public function test_submit_rejects_unknown_fields_unknown_table_and_blank_values(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $this->actingAs($maker);

        $this->assertValidation(fn () => $this->service()->submitLookup($maker, $this->lookupPayload('BAD') + ['is_active' => true]));
        $this->assertValidation(fn () => $this->service()->submitLookup($maker, array_replace($this->lookupPayload('BLANK'), ['code' => ' '])));
        $this->assertValidation(fn () => $this->service()->submitLookup($maker, array_replace($this->lookupPayload('BLANK_LABEL'), ['label_ja' => '  '])));
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->submitLookup($maker, array_replace($this->lookupPayload('BAD_TABLE'), ['lookup_table' => 'users']));
    }

    public function test_lookup_request_preserves_validated_extra_and_rejects_invalid_parent(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $parentId = DB::table('bidang_pekerjaan')->insertGetId([
            'code' => 'PARENT', 'label_id' => 'Induk', 'label_ja' => '親', 'is_active' => true,
        ]);
        $this->actingAs($maker);

        $request = $this->service()->submitLookup($maker, [
            'lookup_table' => 'skill_ssw',
            'code' => 'SKILL_CHILD',
            'label_id' => 'Keahlian',
            'label_ja' => '技能',
            'bidang_id' => $parentId,
        ]);

        $this->assertJsonStringEqualsJsonString(json_encode(['bidang_id' => $parentId]), $request->extra);
        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        $this->service()->approveLookup($admin, $request->id);
        $this->assertDatabaseHas('skill_ssw', ['code' => 'SKILL_CHILD', 'bidang_id' => $parentId]);

        $this->actingAs($maker);
        $this->assertValidation(fn () => $this->service()->submitLookup($maker, [
            'lookup_table' => 'skill_ssw', 'code' => 'INVALID_PARENT', 'label_id' => 'X', 'label_ja' => 'X', 'bidang_id' => 99999,
        ]));
        DB::table('bidang_pekerjaan')->where('id', $parentId)->update(['is_active' => false]);
        $this->assertValidation(fn () => $this->service()->submitLookup($maker, [
            'lookup_table' => 'skill_ssw', 'code' => 'INACTIVE_PARENT', 'label_id' => 'X', 'label_ja' => 'X', 'bidang_id' => $parentId,
        ]));
    }

    public function test_approval_revalidates_parent_activity_and_rolls_back_the_decision(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $parentId = DB::table('bidang_pekerjaan')->insertGetId([
            'code' => 'APPROVAL_PARENT', 'label_id' => 'Induk', 'label_ja' => '親', 'is_active' => true,
        ]);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, [
            'lookup_table' => 'skill_ssw',
            'code' => 'APPROVAL_CHILD',
            'label_id' => 'Keahlian',
            'label_ja' => '技能',
            'bidang_id' => $parentId,
        ]);
        DB::table('bidang_pekerjaan')->where('id', $parentId)->update(['is_active' => false]);

        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        $this->assertValidation(fn () => $this->service()->approveLookup($admin, $request->id));

        $this->assertDatabaseHas('lookup_request', ['id' => $request->id, 'status' => 'pending', 'reviewed_by' => null]);
        $this->assertDatabaseMissing('skill_ssw', ['code' => 'APPROVAL_CHILD']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_APPROVED)->count());
        $this->assertDatabaseMissing('notifications', ['type' => ActionType::LOOKUP_REQUEST_APPROVED->value]);
    }

    public function test_approve_lookup_and_reject_company_require_single_step_up_and_commit_atomically(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $assistant = $this->user(Rbac::ASSISTANT_MANAGER);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $lookup = $this->service()->submitLookup($maker, $this->lookupPayload('APPROVED'));
        $this->actingAs($assistant);
        $company = $this->service()->submitCompany($assistant, ['nama_ja' => '承認会社']);
        $approvedCompany = $this->service()->submitCompany($assistant, ['nama_ja' => '会社作成']);

        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $lookup->id);
        $this->service()->approveLookup($admin, $lookup->id);
        $this->grantStepUp('company_request', $company->id);
        $this->service()->rejectCompany($admin, $company->id, 'Data belum lengkap');
        $this->grantStepUp('company_request', $approvedCompany->id);
        $this->service()->approveCompany($admin, $approvedCompany->id);

        $this->assertDatabaseHas('agama', ['code' => 'APPROVED', 'is_active' => true]);
        $this->assertDatabaseHas('lookup_request', ['id' => $lookup->id, 'status' => 'approved', 'reviewed_by' => $admin->getKey()]);
        $this->assertDatabaseHas('company_request', ['id' => $company->id, 'status' => 'rejected', 'note_checker' => 'Data belum lengkap']);
        $this->assertDatabaseHas('perusahaan', ['nama_ja' => '会社作成', 'is_active' => true]);
        $this->assertDatabaseHas('notifications', ['type' => ActionType::LOOKUP_REQUEST_APPROVED->value, 'notifiable_id' => $maker->getKey()]);
        $this->assertDatabaseHas('notifications', ['type' => ActionType::COMPANY_REJECTED->value, 'notifiable_id' => $assistant->getKey()]);
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_APPROVED)->count());
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::COMPANY_REJECTED)->count());
        $this->assertDatabaseCount('pending_request', 0);
    }

    public function test_decision_rejects_missing_step_up_self_decision_and_blank_rejection_note(): void
    {
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $selfRequestId = DB::table('company_request')->insertGetId([
            'nama_ja' => '自己判断禁止',
            'requested_by' => $admin->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->assertAccessDenied(fn () => $this->service()->approveCompany($admin, $selfRequestId), 'APV_SELF');

        $maker = $this->user(Rbac::ASSISTANT_MANAGER);
        $this->actingAs($maker);
        $company = $this->service()->submitCompany($maker, ['nama_ja' => '決定禁止']);

        $this->actingAs($admin);
        $this->assertStepUpRequired(fn () => $this->service()->approveCompany($admin, $company->id));
        $this->assertValidation(fn () => $this->service()->rejectCompany($admin, $company->id, '   '));
        $this->assertDatabaseHas('company_request', ['id' => $company->id, 'status' => 'pending']);
    }

    public function test_duplicate_lookup_code_rolls_back_target_decision_audit_and_notification(): void
    {
        DB::table('agama')->insert(['code' => 'DUPLICATE', 'label_id' => 'Lama', 'label_ja' => '古い', 'is_active' => true]);
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('DUPLICATE'));
        $submittedNotifications = DB::table('notifications')->count();

        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        $this->assertValidation(fn () => $this->service()->approveLookup($admin, $request->id));

        $this->assertDatabaseHas('lookup_request', ['id' => $request->id, 'status' => 'pending', 'reviewed_by' => null]);
        $this->assertSame(1, DB::table('agama')->where('code', 'DUPLICATE')->count());
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_APPROVED)->count());
        $this->assertSame($submittedNotifications, DB::table('notifications')->count());
    }

    public function test_audit_or_notification_failure_rolls_back_submit_or_decision(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $this->actingAs($maker);
        AuditLog::creating(static function (): never {
            throw new \RuntimeException('audit failed');
        });

        try {
            $this->assertRuntimeFailure(fn () => $this->service()->submitLookup($maker, $this->lookupPayload('AUDIT_FAIL')));
        } finally {
            AuditLog::getEventDispatcher()?->forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertDatabaseMissing('lookup_request', ['code' => 'AUDIT_FAIL']);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('NOTIFY_FAIL'));
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        Notification::shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('notification failed'));
        $this->assertRuntimeFailure(fn () => $this->service()->approveLookup($admin, $request->id));

        $this->assertDatabaseHas('lookup_request', ['id' => $request->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('agama', ['code' => 'NOTIFY_FAIL']);
        $this->assertSame(0, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_APPROVED)->count());
    }

    public function test_enqueue_failure_does_not_rollback_committed_decision(): void
    {
        Queue::fake();
        Queue::beforePushing(static function (): never {
            throw new \RuntimeException('queue unavailable');
        });
        Log::spy();
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('QUEUE_SAFE'));

        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        $this->service()->approveLookup($admin, $request->id);

        $this->assertDatabaseHas('lookup_request', ['id' => $request->id, 'status' => 'approved']);
        $this->assertDatabaseHas('agama', ['code' => 'QUEUE_SAFE']);
        $this->assertDatabaseHas('notifications', ['type' => ActionType::LOOKUP_REQUEST_APPROVED->value, 'notifiable_id' => $maker->id]);
        $this->assertSame(1, AuditLog::query()->where('action_type', ActionType::LOOKUP_REQUEST_APPROVED)->count());
    }

    public function test_database_enforces_pending_lifecycle_provenance_and_runtime_update_columns(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('GUARDED'));
        $owner = DB::connection('pgsql_migrator');

        $this->assertDatabaseFailure(fn () => $owner->table('company_request')->insert([
            'nama_ja' => 'langsung disetujui',
            'requested_by' => $maker->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]));
        $this->assertDatabaseFailure(fn () => $owner->table('company_request')->insert([
            'nama_ja' => 'langsung ditolak',
            'requested_by' => $maker->id,
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'note_checker' => 'langsung',
        ]));
        $this->assertDatabaseFailure(fn () => $owner->table('lookup_request')->where('id', $request->id)->delete());
        $this->assertDatabaseFailure(fn () => $owner->table('lookup_request')->where('id', $request->id)->update([
            'status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'code' => 'MUTATED',
        ]));
        $owner->table('lookup_request')->where('id', $request->id)->update([
            'status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseFailure(fn () => $owner->table('lookup_request')->where('id', $request->id)->update(['updated_at' => now()]));

        $privileges = DB::selectOne(<<<'SQL'
            SELECT
                has_column_privilege(current_user, 'lookup_request', 'status', 'UPDATE')::int AS may_decide,
                has_column_privilege(current_user, 'lookup_request', 'code', 'UPDATE')::int AS may_mutate_provenance
            SQL);
        $this->assertTrue((bool) $privileges->may_decide);
        $this->assertFalse((bool) $privileges->may_mutate_provenance);
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege(current_user, 'lookup_request', 'DELETE')::int AS allowed")->allowed);
        $this->assertFalse((bool) DB::selectOne("SELECT has_table_privilege(current_user, 'lookup_request', 'TRUNCATE')::int AS allowed")->allowed);
        $this->assertDatabaseFailure(fn () => DB::statement('TRUNCATE lookup_request'));
    }

    public function test_lookup_cache_flushes_only_after_outer_transaction_commit(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $admin = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('CACHE_COMMIT'));
        app(LookupService::class)->options('agama', 'id');

        $this->actingAs($admin);
        $this->grantStepUp('lookup_request', $request->id);
        DB::transaction(function () use ($admin, $request): void {
            $this->service()->approveLookup($admin, $request->id);
            $this->assertArrayNotHasKey('CACHE_COMMIT', Cache::store('redis')->get('lookup:agama:id'));
        });

        $this->assertTrue(Cache::store('redis')->missing('lookup:agama:id'));
        $this->assertSame('Nilai CACHE_COMMIT', app(LookupService::class)->options('agama', 'id')['CACHE_COMMIT']);
    }

    public function test_second_decision_and_concurrent_decisions_return_conflict_without_pending_request(): void
    {
        $maker = $this->user(Rbac::STAFF_INPUT);
        $checkerA = $this->user(Rbac::SUPER_ADMIN);
        $checkerB = $this->user(Rbac::SUPER_ADMIN);
        $this->actingAs($maker);
        $request = $this->service()->submitLookup($maker, $this->lookupPayload('RACE'));
        $startAt = microtime(true) + 0.5;

        $pids = [
            $this->forkDecision($request->id, $checkerA->id, $startAt),
            $this->forkDecision($request->id, $checkerB->id, $startAt),
        ];
        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }
        sort($exitCodes);
        DB::purge('pgsql');

        $this->assertSame([0, 10], $exitCodes);
        $this->assertDatabaseHas('lookup_request', ['id' => $request->id, 'status' => 'approved']);
        $this->assertSame(1, DB::table('agama')->where('code', 'RACE')->count());
        $this->assertSame(0, PendingRequest::query()->count());
        $this->assertFalse(in_array('LOOKUP_REQUEST', array_column(PendingType::cases(), 'value'), true));

        $this->actingAs($checkerA);
        $this->grantStepUp('lookup_request', $request->id);
        $this->assertConflict(fn () => $this->service()->approveLookup($checkerA, $request->id));
    }

    private function forkDecision(int $requestId, int $checkerId, float $startAt): int
    {
        $pid = pcntl_fork();
        if ($pid !== 0) {
            return $pid;
        }

        try {
            DB::purge('pgsql');
            $checker = User::query()->findOrFail($checkerId);
            Auth::login($checker);
            session(['stepup.tokens' => [
                StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.lookup_request.'.$requestId => now()->addMinutes(5)->getTimestamp(),
            ]]);
            while (microtime(true) < $startAt) {
                usleep(1000);
            }
            $this->service()->approveLookup($checker, $requestId);
            exit(0);
        } catch (ConflictHttpException $exception) {
            exit($exception->getMessage() === 'APV_DONE' ? 10 : 20);
        } catch (Throwable) {
            exit(20);
        }
    }

    private function service(): LookupRequestService
    {
        return app(LookupRequestService::class);
    }

    /** @return array<string, string> */
    private function lookupPayload(string $code): array
    {
        return ['lookup_table' => 'agama', 'code' => $code, 'label_id' => 'Nilai '.$code, 'label_ja' => '値 '.$code];
    }

    private function user(string $role, string $status = 'Aktif'): User
    {
        $user = User::factory()->create();
        $user->forceFill(['status_akun' => $status])->save();
        $user->assignRole($role);

        return $user;
    }

    private function grantStepUp(string $entityType, int $entityId): void
    {
        session(['stepup.tokens' => [
            StepUpAction::MANAGE_LOOKUP_OR_COMPANY.'.'.$entityType.'.'.$entityId => now()->addMinutes(5)->getTimestamp(),
        ]]);
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

    private function assertAccessDenied(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected access denial.');
        } catch (AccessDeniedHttpException $exception) {
            $this->assertSame($message, $exception->getMessage());
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

    private function assertValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertRuntimeFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected runtime failure.');
        } catch (\RuntimeException $exception) {
            $this->assertContains($exception->getMessage(), ['audit failed', 'notification failed']);
        }
    }

    private function assertConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected APV_DONE.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('APV_DONE', $exception->getMessage());
        }
    }

    private function assertDatabaseFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected database constraint failure.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function cleanFixtures(): void
    {
        $migrator = (string) config('database.connections.pgsql_migrator.username');
        $this->assertNotSame('', $migrator, 'DB_MIGRATOR_USERNAME must be available to run PostgreSQL tests.');

        DB::connection('pgsql_migrator')->statement(
            'TRUNCATE audit_log, notifications, lookup_request, company_request, perusahaan, agama, skill_ssw, bidang_pekerjaan RESTART IDENTITY CASCADE'
        );
        DB::table('model_has_roles')->delete();
        DB::table('model_has_permissions')->delete();
        User::query()->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['agama', 'skill_ssw', 'bidang_pekerjaan'] as $table) {
            foreach (['id', 'ja', 'lock'] as $suffix) {
                Cache::store('redis')->forget("lookup:{$table}:{$suffix}");
            }
        }
    }
}
