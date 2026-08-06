<?php

namespace Tests\Feature\Approval;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shared\Approval\PendingStatus;
use Shared\Approval\PendingType;
use Tests\TestCase;
use Throwable;

/**
 * DATABASE_SCHEMA §5.7 — struktur, partial unique aktif, dan CHECK payload
 * ditegakkan di PostgreSQL (bukan hanya di layer aplikasi).
 */
class PendingRequestSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_matches_contract(): void
    {
        $this->assertTrue(Schema::hasTable('pending_request'));
        $this->assertTrue(Schema::hasColumns('pending_request', [
            'id',
            'type',
            'target_type',
            'target_id',
            'requested_by',
            'reason_maker',
            'checker_id',
            'note_checker',
            'payload',
            'status',
            'created_at',
            'decided_at',
            'updated_at',
        ]));

        $identity = DB::selectOne(
            "SELECT is_identity, identity_generation, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'pending_request'
               AND column_name = 'id'"
        );

        $this->assertNotNull($identity);
        $this->assertSame('YES', $identity->is_identity);
        $this->assertSame('ALWAYS', $identity->identity_generation);

        $status = DB::selectOne(
            "SELECT column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = 'pending_request'
               AND column_name = 'status'"
        );

        $this->assertStringContainsString("'pending'", (string) $status->column_default);
    }

    public function test_active_pending_index_is_partial_on_type_target(): void
    {
        $definition = DB::selectOne(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'pending_request'
               AND indexname = 'uq_pending_active'"
        );

        $this->assertNotNull($definition, 'uq_pending_active must exist');

        $indexdef = (string) $definition->indexdef;

        $this->assertStringContainsString('CREATE UNIQUE INDEX', $indexdef);
        $this->assertStringContainsString('(type, target_type, target_id)', $indexdef);
        $this->assertMatchesRegularExpression("/WHERE \(status = 'pending'::text\)/", $indexdef);
    }

    public function test_second_active_pending_for_same_type_and_target_is_rejected(): void
    {
        $maker = User::factory()->create();

        $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 7]);

        $this->assertDbViolation(
            fn () => $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 7]),
            'uq_pending_active'
        );

        $this->assertSame(1, DB::table('pending_request')->count());
    }

    public function test_partial_unique_allows_other_type_target_and_decided_rows(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $first = $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 7]);

        // Tipe berbeda, target sama.
        $this->insertPending($maker->getKey(), ['type' => 'IC_CLOSE', 'target_id' => 7]);
        // Tipe sama, target berbeda.
        $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 8]);
        // Tipe sama, target_type berbeda.
        $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 7, 'target_type' => 'placement_container']);

        // Setelah pending pertama diputus, submit ulang untuk triple yang sama boleh.
        DB::table('pending_request')->where('id', $first)->update([
            'status' => 'rejected',
            'checker_id' => $checker->getKey(),
            'note_checker' => 'ditolak',
            'decided_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 7]);

        $this->assertSame(5, DB::table('pending_request')->count());
        $this->assertSame(
            4,
            DB::table('pending_request')->where('status', 'pending')->count()
        );
    }

    public function test_payload_is_required_for_snapshot_types(): void
    {
        $maker = User::factory()->create();

        foreach (['PLACEMENT_BATCH', 'FORCE_MAJEUR', 'IC_EXPEL', 'PC_CANCEL_ACTIVE', 'PLACEMENT_RESIGN', 'PLACEMENT_EXPEL'] as $index => $type) {
            $this->assertDbViolation(
                fn () => $this->insertPending($maker->getKey(), [
                    'type' => $type,
                    'target_id' => 100 + $index,
                    'payload' => null,
                ]),
                'pending_payload_required'
            );
        }

        $this->insertPending($maker->getKey(), [
            'type' => 'PLACEMENT_BATCH',
            'target_id' => 200,
            'payload' => json_encode(['candidates' => [1, 2]], JSON_THROW_ON_ERROR),
        ]);

        // Tipe non-snapshot tetap boleh tanpa payload.
        $this->insertPending($maker->getKey(), ['type' => 'IC_CREATE', 'target_id' => 201]);

        $this->assertSame(2, DB::table('pending_request')->count());
    }

    public function test_type_and_status_whitelists_are_enforced(): void
    {
        $maker = User::factory()->create();

        $this->assertDbViolation(
            fn () => $this->insertPending($maker->getKey(), ['type' => 'CANDIDATE_DELETE', 'target_id' => 1]),
            'pending_request_type_check'
        );

        $this->assertDbViolation(
            fn () => $this->insertPending($maker->getKey(), ['target_id' => 2, 'status' => 'cancelled']),
            'pending_request_status_check'
        );

        $this->assertSame(0, DB::table('pending_request')->count());
    }

    public function test_database_enforces_decision_shape_and_checker_separation(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->assertDbViolation(
            fn () => $this->insertPending($maker->getKey(), [
                'target_id' => 301,
                'status' => 'approved',
                'checker_id' => $checker->getKey(),
                'decided_at' => now(),
            ]),
            'pending_request_insert_pending_only'
        );

        $missingDecision = $this->insertPending($maker->getKey(), ['target_id' => 302]);
        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $missingDecision)->update([
                'status' => 'approved',
            ]),
            'pending_request_decision_shape'
        );

        $blankRejection = $this->insertPending($maker->getKey(), ['target_id' => 303]);
        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $blankRejection)->update([
                'status' => 'rejected',
                'checker_id' => $checker->getKey(),
                'note_checker' => '   ',
                'decided_at' => now(),
                'updated_at' => now(),
            ]),
            'pending_request_decision_shape'
        );

        $selfDecision = $this->insertPending($maker->getKey(), ['target_id' => 304]);
        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $selfDecision)->update([
                'status' => 'approved',
                'checker_id' => $maker->getKey(),
                'decided_at' => now(),
                'updated_at' => now(),
            ]),
            'pending_request_checker_not_maker'
        );

        $this->assertSame(3, DB::table('pending_request')->where('status', 'pending')->count());
    }

    public function test_maker_cancellation_shape_is_restricted_to_interview_creation(): void
    {
        $maker = User::factory()->create();
        $requestId = $this->insertPending($maker->getKey(), [
            'type' => 'CANDIDATE_NEW',
            'target_type' => 'candidate',
            'target_id' => 305,
        ]);

        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $requestId)->update([
                'status' => 'rejected',
                'checker_id' => null,
                'note_checker' => 'IC_CANCELLED_BY_MAKER',
                'decided_at' => now(),
                'updated_at' => now(),
            ]),
            'pending_request_decision_shape'
        );

        $this->assertSame('pending', DB::table('pending_request')->where('id', $requestId)->value('status'));
    }

    public function test_decision_is_one_way_and_runtime_cannot_mutate_provenance_or_delete(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $requestId = $this->insertPending($maker->getKey(), ['target_id' => 401]);

        DB::table('pending_request')->where('id', $requestId)->update([
            'status' => 'approved',
            'checker_id' => $checker->getKey(),
            'decided_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $requestId)->update([
                'status' => 'rejected',
                'checker_id' => $checker->getKey(),
                'note_checker' => 'ubah keputusan',
                'decided_at' => now(),
                'updated_at' => now(),
            ]),
            'pending_request_decision_once'
        );

        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $requestId)->update(['target_id' => 999]),
            'permission denied'
        );

        $this->assertDbViolation(
            fn () => DB::table('pending_request')->where('id', $requestId)->delete(),
            'permission denied'
        );

        $stored = DB::table('pending_request')->where('id', $requestId)->sole();
        $this->assertSame('approved', $stored->status);
        $this->assertSame(401, (int) $stored->target_id);
    }

    /**
     * Enum aplikasi dan CHECK DB harus identik dua arah. Yang paling mahal bila
     * melenceng adalah requiresPayload(): submit() akan lolos guard aplikasi lalu
     * mati sebagai QueryException di tengah transaksi domain, bukan 422 APV_PAYLOAD.
     */
    public function test_enums_and_database_check_constraints_do_not_drift(): void
    {
        $this->assertSame(
            $this->sortedValues(PendingType::cases()),
            $this->constraintLiterals('pending_request_type_check'),
            'PendingType must mirror pending_request_type_check'
        );

        $this->assertSame(
            $this->sortedValues(PendingStatus::cases()),
            $this->constraintLiterals('pending_request_status_check'),
            'PendingStatus must mirror pending_request_status_check'
        );

        $payloadRequired = array_values(array_filter(
            PendingType::cases(),
            static fn (PendingType $type): bool => $type->requiresPayload()
        ));

        $this->assertSame(
            $this->sortedValues($payloadRequired),
            $this->constraintLiterals('pending_payload_required'),
            'PendingType::requiresPayload() must mirror pending_payload_required'
        );
    }

    public function test_runtime_role_has_only_required_pending_request_privileges(): void
    {
        $row = DB::selectOne(
            "SELECT current_user AS usr,
                    has_table_privilege(current_user, 'pending_request', 'SELECT') AS can_select,
                    has_table_privilege(current_user, 'pending_request', 'INSERT') AS can_insert,
                    has_table_privilege(current_user, 'pending_request', 'UPDATE') AS can_update,
                    has_table_privilege(current_user, 'pending_request', 'DELETE') AS can_delete,
                    has_table_privilege(current_user, 'pending_request', 'TRUNCATE') AS can_truncate,
                    has_column_privilege(current_user, 'pending_request', 'status', 'UPDATE') AS can_update_status,
                    has_column_privilege(current_user, 'pending_request', 'checker_id', 'UPDATE') AS can_update_checker,
                    has_column_privilege(current_user, 'pending_request', 'target_id', 'UPDATE') AS can_update_target"
        );

        $this->assertSame(
            (string) config('database.connections.pgsql.username'),
            $row->usr,
            'Default connection must be the runtime role'
        );
        $this->assertTrue($this->toBool($row->can_select));
        $this->assertTrue($this->toBool($row->can_insert));
        $this->assertFalse($this->toBool($row->can_update));
        $this->assertFalse($this->toBool($row->can_delete));
        $this->assertFalse($this->toBool($row->can_truncate));
        $this->assertTrue($this->toBool($row->can_update_status));
        $this->assertTrue($this->toBool($row->can_update_checker));
        $this->assertFalse($this->toBool($row->can_update_target));
    }

    /**
     * Literal teks di dalam definisi CHECK, terurut.
     *
     * @return list<string>
     */
    private function constraintLiterals(string $constraint): array
    {
        $row = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def
             FROM pg_constraint
             WHERE conrelid = 'pending_request'::regclass AND conname = ?",
            [$constraint]
        );

        $this->assertNotNull($row, "constraint [{$constraint}] must exist");

        preg_match_all("/'([^']+)'::text/", (string) $row->def, $matches);

        $literals = array_values(array_unique($matches[1]));
        sort($literals);

        return $literals;
    }

    /**
     * @param  list<PendingType|PendingStatus>  $cases
     * @return list<string>
     */
    private function sortedValues(array $cases): array
    {
        $values = array_map(static fn (PendingType|PendingStatus $case): string => $case->value, $cases);
        sort($values);

        return array_values($values);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertPending(int $makerId, array $overrides = []): int
    {
        return (int) DB::table('pending_request')->insertGetId(array_merge([
            'type' => 'IC_CREATE',
            'target_type' => 'interview_container',
            'target_id' => 1,
            'requested_by' => $makerId,
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * PG membatalkan transaksi test saat constraint gagal; isolasi dengan savepoint.
     */
    private function assertDbViolation(callable $operation, string $constraint): void
    {
        $savepoint = 'sp_'.bin2hex(random_bytes(6));

        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
            $this->fail("Expected constraint [{$constraint}] to be violated");
        } catch (QueryException $e) {
            $this->assertStringContainsString(
                $constraint,
                $e->getMessage(),
                "Expected [{$constraint}] violation, got: ".$e->getMessage()
            );
        } catch (Throwable $e) {
            // UniqueConstraintViolationException extends QueryException, but keep the
            // failure message useful if PG surfaces something else entirely.
            $this->assertStringContainsString($constraint, $e->getMessage());
        } finally {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
        }
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
