<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Auth\Rbac;
use RuntimeException;
use Shared\Audit\ActionType;
use Shared\Audit\AuditLog;
use Shared\Audit\AuditLogger;
use Tests\TestCase;

class AuditImmutableTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_schema_matches_contract(): void
    {
        $this->assertTrue(Schema::hasTable('audit_log'));
        $this->assertTrue(Schema::hasColumns('audit_log', [
            'id',
            'actor_id',
            'actor_role_snapshot',
            'action_type',
            'entity_type',
            'entity_id',
            'detail',
            'ip',
            'user_agent',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_log', 'updated_at'));

        $trigger = DB::selectOne(
            "SELECT 1 FROM pg_trigger WHERE tgname = 'trg_audit_log_immutable' AND NOT tgisinternal"
        );
        $this->assertNotNull($trigger, 'immutability trigger must exist');

        $identity = DB::selectOne(
            "SELECT is_identity, identity_generation
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'audit_log'
               AND column_name = 'id'"
        );

        $this->assertNotNull($identity);
        $this->assertSame('YES', $identity->is_identity);
        $this->assertSame('ALWAYS', $identity->identity_generation);
    }

    public function test_record_inserts_with_actor_role_snapshot(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $actor = User::factory()->create();
        $actor->assignRole(Rbac::SUPER_ADMIN);

        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::LOGIN_SUCCESS,
            entityType: 'user',
            entityId: $actor->id,
            detail: ['user_id' => $actor->id],
            actorId: $actor->id,
            ip: '203.0.113.10',
            userAgent: 'phpunit',
        );

        $this->assertDatabaseHas('audit_log', [
            'id' => $row->id,
            'actor_id' => $actor->id,
            'actor_role_snapshot' => Rbac::SUPER_ADMIN,
            'action_type' => ActionType::LOGIN_SUCCESS->value,
            'entity_type' => 'user',
            'entity_id' => $actor->id,
            'ip' => '203.0.113.10',
            'user_agent' => null,
        ]);

        $stored = AuditLog::query()->findOrFail($row->id);
        $this->assertSame(['user_id' => $actor->id], $stored->detail);
        $this->assertNull($stored->updated_at ?? null);
    }

    public function test_role_snapshot_is_frozen_at_event_time_not_live_join(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $actor = User::factory()->create();
        $actor->assignRole(Rbac::STAFF_INPUT);

        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::USER_UPDATED,
            entityType: 'user',
            entityId: $actor->id,
            detail: ['target_user_id' => $actor->id],
            actorId: $actor->id,
        );

        $this->assertSame(Rbac::STAFF_INPUT, $row->actor_role_snapshot);

        // Role changes later must not rewrite historical snapshot.
        $actor->syncRoles([Rbac::JOB_MANAGER]);
        $fresh = AuditLog::query()->findOrFail($row->id);
        $this->assertSame(Rbac::STAFF_INPUT, $fresh->actor_role_snapshot);
        $this->assertNotSame(Rbac::JOB_MANAGER, $fresh->actor_role_snapshot);
    }

    public function test_system_or_guest_actor_has_null_role_snapshot(): void
    {
        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::GUEST_ACCESS,
            entityType: 'guest_link',
            entityId: 1,
            detail: ['token_id' => 9, 'container_id' => 1],
            actorId: null,
            ip: '198.51.100.7',
        );

        $this->assertNull($row->actor_id);
        $this->assertNull($row->actor_role_snapshot);
    }

    public function test_update_is_blocked_by_model_and_database(): void
    {
        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::LOGOUT,
            entityType: 'user',
            entityId: 1,
            detail: ['user_id' => 1],
            actorId: null,
        );

        try {
            $row->action_type = ActionType::LOGIN_SUCCESS;
            $row->save();
            $this->fail('Model update should throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // PG aborts the outer test transaction on RAISE; isolate with a savepoint.
        DB::statement('SAVEPOINT audit_update_gate');
        try {
            DB::table('audit_log')->where('id', $row->id)->update(['action_type' => 'TAMPERED']);
            $this->fail('SQL UPDATE should throw');
        } catch (QueryException $e) {
            $this->assertTrue(
                $this->isAppendOnlyViolation($e),
                'SQL UPDATE must be blocked by privilege or trigger, got: '.$e->getMessage()
            );
            DB::statement('ROLLBACK TO SAVEPOINT audit_update_gate');
        }

        $this->assertSame(
            ActionType::LOGOUT->value,
            DB::table('audit_log')->where('id', $row->id)->value('action_type')
        );
    }

    public function test_delete_is_blocked_by_model_and_database(): void
    {
        $logger = new AuditLogger;
        $row = $logger->record(
            actionType: ActionType::LOGOUT,
            entityType: 'user',
            entityId: 2,
            detail: ['user_id' => 2],
        );

        try {
            $row->delete();
            $this->fail('Model delete should throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        DB::statement('SAVEPOINT audit_delete_gate');
        try {
            DB::table('audit_log')->where('id', $row->id)->delete();
            $this->fail('SQL DELETE should throw');
        } catch (QueryException $e) {
            $this->assertTrue(
                $this->isAppendOnlyViolation($e),
                'SQL DELETE must be blocked by privilege or trigger, got: '.$e->getMessage()
            );
            DB::statement('ROLLBACK TO SAVEPOINT audit_delete_gate');
        }

        $this->assertDatabaseHas('audit_log', ['id' => $row->id]);
    }

    public function test_detail_rejects_secret_and_raw_email_keys(): void
    {
        $logger = new AuditLogger;

        try {
            $logger->record(
                actionType: ActionType::LOGIN_FAILED,
                entityType: 'auth',
                detail: ['password' => 'SuperSecret1!'],
            );
            $this->fail('password key must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }

        try {
            $logger->record(
                actionType: ActionType::LOGIN_FAILED,
                entityType: 'auth',
                detail: ['email' => 'victim@example.com'],
            );
            $this->fail('raw email key must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('email', strtolower($e->getMessage()));
        }

        try {
            $logger->record(
                actionType: ActionType::TWOFA_FAILED,
                entityType: 'user',
                entityId: 1,
                detail: ['totp_code' => '123456'],
            );
            $this->fail('totp must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('totp', strtolower($e->getMessage()));
        }

        try {
            $logger->record(
                actionType: ActionType::TWOFA_RECOVERY_USED,
                entityType: 'user',
                entityId: 1,
                detail: ['recovery_code' => 'abcd-efgh'],
            );
            $this->fail('recovery code must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('recovery', strtolower($e->getMessage()));
        }

        foreach ([
            'password' => 'SuperSecret1!',
            'token' => 'plain-token',
            'secret' => 'shared-secret',
            'totp' => '123456',
            'otp' => '654321',
            'recovery_code' => 'abcd-efgh',
        ] as $key => $value) {
            try {
                $logger->record(
                    actionType: ActionType::LOGIN_FAILED,
                    entityType: 'auth',
                    detail: ['meta' => [$key => $value]],
                );
                $this->fail("nested {$key} must be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($key, strtolower($e->getMessage()));
            }
        }

        // Opaque token_id is allowed (PRD GUEST_ACCESS); raw token is not.
        $guest = $logger->record(
            actionType: ActionType::GUEST_ACCESS,
            entityType: 'guest_link',
            entityId: 3,
            detail: ['token_id' => 3, 'container_id' => 1],
        );
        $this->assertSame(3, $guest->detail['token_id']);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_login_fail_detail_uses_fingerprint_not_raw_email(): void
    {
        $logger = new AuditLogger;
        $raw = 'Anon.User@Example.COM';
        $fingerprint = $logger->fingerprintEmail($raw);

        $this->assertStringStartsWith('hmac:', $fingerprint);
        $this->assertStringNotContainsString('anon.user@example.com', $fingerprint);
        $this->assertStringNotContainsString($raw, $fingerprint);

        $row = $logger->record(
            actionType: ActionType::LOGIN_FAILED,
            entityType: 'auth',
            detail: [
                'user_id' => null,
                'email_masked_or_fingerprint' => $fingerprint,
                'reason' => 'bad_credentials',
                'attempts_left' => 4,
            ],
            actorId: null,
            ip: '192.0.2.55',
        );

        $stored = AuditLog::query()->findOrFail($row->id);
        $json = json_encode($stored->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame($fingerprint, $stored->detail['email_masked_or_fingerprint']);
        $this->assertStringNotContainsString('Anon.User@Example.COM', $json);
        $this->assertStringNotContainsString('anon.user@example.com', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertSame('192.0.2.55', $stored->ip);
        $this->assertArrayNotHasKey('ip', $stored->detail);
    }

    public function test_mask_email_helper_is_not_raw(): void
    {
        $logger = new AuditLogger;
        $masked = $logger->maskEmail('staff@example.com');

        $this->assertSame('s***@example.com', $masked);
        $this->assertNotSame('staff@example.com', $masked);
    }

    public function test_raw_email_value_rejected_regardless_of_key_and_nesting(): void
    {
        $logger = new AuditLogger;

        foreach ([
            ['identifier' => 'victim@example.com'],
            ['message' => 'Login failed for victim@example.com from browser'],
            ['meta' => ['contact' => 'victim@example.com']],
            ['meta' => ['message' => 'Contact: victim@example.com immediately']],
        ] as $detail) {
            try {
                $logger->record(
                    actionType: ActionType::LOGIN_FAILED,
                    entityType: 'auth',
                    detail: $detail,
                );
                $this->fail('standalone or embedded raw email must be rejected recursively');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('raw email', strtolower($e->getMessage()));
            }
        }

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_user_agent_is_never_stored(): void
    {
        $logger = new AuditLogger;
        $userAgent = 'Mozilla/5.0 victim@example.com token=plain-secret';

        $row = $logger->record(
            actionType: ActionType::LOGIN_SUCCESS,
            entityType: 'user',
            entityId: 1,
            detail: ['user_id' => 1],
            userAgent: $userAgent,
        );

        $this->assertNull($row->user_agent);
        $this->assertDatabaseHas('audit_log', [
            'id' => $row->id,
            'user_agent' => null,
        ]);
        $this->assertStringNotContainsString(
            $userAgent,
            json_encode($row->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_auth_detail_ip_rejected_but_guest_detail_ip_allowed(): void
    {
        $logger = new AuditLogger;

        try {
            $logger->record(
                actionType: ActionType::LOGIN_FAILED,
                entityType: 'auth',
                detail: [
                    'reason' => 'bad_credentials',
                    'ip' => '203.0.113.50',
                ],
                ip: '203.0.113.50',
            );
            $this->fail('Auth detail.ip must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ip', strtolower($e->getMessage()));
        }

        try {
            $logger->record(
                actionType: ActionType::LOGIN_SUCCESS,
                entityType: 'user',
                entityId: 1,
                detail: [
                    'user_id' => 1,
                    'nested' => ['IP' => '198.51.100.1'],
                ],
            );
            $this->fail('Auth nested IP key must be rejected case-insensitively');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ip', strtolower($e->getMessage()));
        }

        // Lampiran A GUEST_ACCESS may include ip inside detail.
        $guest = $logger->record(
            actionType: ActionType::GUEST_ACCESS,
            entityType: 'guest_link',
            entityId: 9,
            detail: [
                'token_id' => 9,
                'container_id' => 1,
                'ip' => '198.51.100.20',
            ],
            actorId: null,
            ip: '198.51.100.20',
        );

        $this->assertSame('198.51.100.20', $guest->detail['ip']);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_audit_record_rolls_back_with_failed_transaction(): void
    {
        $logger = new AuditLogger;
        $marker = 'rollback-marker-'.bin2hex(random_bytes(8));

        try {
            DB::transaction(function () use ($logger, $marker): void {
                $logger->record(
                    actionType: ActionType::LOGOUT,
                    entityType: 'user',
                    entityId: 99,
                    detail: [
                        'user_id' => 99,
                        'marker' => $marker,
                    ],
                );

                throw new RuntimeException('force rollback');
            });
            $this->fail('transaction should rethrow');
        } catch (RuntimeException $e) {
            $this->assertSame('force rollback', $e->getMessage());
        }

        $this->assertDatabaseMissing('audit_log', [
            'action_type' => ActionType::LOGOUT->value,
            'entity_type' => 'user',
            'entity_id' => 99,
        ]);

        $this->assertSame(
            0,
            AuditLog::query()->where('detail->marker', $marker)->count()
        );
    }

    public function test_strict_fingerprint_and_mask_formats_are_accepted(): void
    {
        $logger = new AuditLogger;
        $fingerprint = $logger->fingerprintEmail('staff@example.com');
        $masked = $logger->maskEmail('staff@example.com');

        $this->assertMatchesRegularExpression('/^hmac:[a-f0-9]{64}$/', $fingerprint);
        $this->assertMatchesRegularExpression('/^.\*\*\*@[^\s@]+$/', $masked);

        $row = $logger->record(
            actionType: ActionType::LOGIN_FAILED,
            entityType: 'auth',
            detail: [
                'email_masked_or_fingerprint' => $fingerprint,
                'also_masked' => $masked,
                'reason' => 'bad_credentials',
            ],
            ip: '192.0.2.10',
        );

        $this->assertSame($fingerprint, $row->detail['email_masked_or_fingerprint']);
        $this->assertSame($masked, $row->detail['also_masked']);
    }

    /**
     * Append-only is enforced twice. The runtime role has UPDATE/DELETE revoked
     * (migration 000005), so PostgreSQL raises insufficient_privilege before
     * trg_audit_log_immutable can fire; the owner/migrator path still hits the
     * trigger. Runtime-role privilege detail is asserted in AuditRuntimePrivilegeTest.
     */
    private function isAppendOnlyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return ($e->errorInfo[0] ?? null) === '42501'
            || str_contains($message, 'permission denied')
            || str_contains($message, 'immutable');
    }
}
