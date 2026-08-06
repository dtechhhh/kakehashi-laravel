<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pending_request DROP CONSTRAINT IF EXISTS pending_request_decision_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE pending_request
            ADD CONSTRAINT pending_request_decision_shape CHECK (
                status NOT IN ('pending', 'approved', 'rejected')
                OR (
                    (
                        status = 'pending'
                        AND checker_id IS NULL
                        AND note_checker IS NULL
                        AND decided_at IS NULL
                    )
                    OR (
                        status = 'approved'
                        AND checker_id IS NOT NULL
                        AND decided_at IS NOT NULL
                    )
                    OR (
                        status = 'rejected'
                        AND decided_at IS NOT NULL
                        AND (
                            (
                                type = 'IC_CREATE'
                                AND target_type = 'interview_container'
                                AND checker_id IS NULL
                                AND note_checker = 'IC_CANCELLED_BY_MAKER'
                            )
                            OR (
                                type = 'PC_CREATE'
                                AND target_type = 'placement_container'
                                AND checker_id IS NULL
                                AND note_checker = 'IC_CANCELLED_BY_MAKER'
                            )
                            OR (
                                checker_id IS NOT NULL
                                AND note_checker IS NOT NULL
                                AND btrim(note_checker) <> ''
                            )
                        )
                    )
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pending_request DROP CONSTRAINT IF EXISTS pending_request_decision_shape');
        DB::statement(<<<'SQL'
            ALTER TABLE pending_request
            ADD CONSTRAINT pending_request_decision_shape CHECK (
                status NOT IN ('pending', 'approved', 'rejected')
                OR (
                    (
                        status = 'pending'
                        AND checker_id IS NULL
                        AND note_checker IS NULL
                        AND decided_at IS NULL
                    )
                    OR (
                        status = 'approved'
                        AND checker_id IS NOT NULL
                        AND decided_at IS NOT NULL
                    )
                    OR (
                        status = 'rejected'
                        AND checker_id IS NOT NULL
                        AND decided_at IS NOT NULL
                        AND note_checker IS NOT NULL
                        AND btrim(note_checker) <> ''
                    )
                )
            )
            SQL);
    }
};
