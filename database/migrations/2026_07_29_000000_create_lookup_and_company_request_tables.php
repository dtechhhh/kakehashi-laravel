<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_TABLES = [
        'negara', 'bahasa', 'provinsi', 'kota_kabupaten', 'kecamatan', 'agama',
        'golongan_darah', 'ukuran_sepatu', 'tingkat_penglihatan', 'asal_rekrutmen',
        'status_keluarga', 'tingkat_pendidikan', 'jurusan', 'bidang_pekerjaan',
        'posisi_pekerjaan', 'bidang_industri_perusahaan', 'bidang_diminati',
        'jenis_kualifikasi_bahasa_inggris', 'jenis_kualifikasi_bahasa_jepang',
        'skill_ssw', 'kualifikasi_mengemudi', 'kualifikasi_keahlian_lainnya',
        'jenis_visa', 'kategori_force_majeur', 'jenis_dokumen',
    ];

    public function up(): void
    {
        Schema::create('lookup_request', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->text('lookup_table');
            $table->text('code');
            $table->text('label_id');
            $table->text('label_ja');
            $table->jsonb('extra')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->text('reason')->nullable();
            $this->decisionColumns($table);
        });

        Schema::create('company_request', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->text('nama_ja');
            $table->text('nama_romaji')->nullable();
            $table->text('nama_id')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnUpdate()->restrictOnDelete();
            $table->text('reason')->nullable();
            $this->decisionColumns($table);
        });

        $allowed = implode("', '", self::LOOKUP_TABLES);
        DB::statement("ALTER TABLE lookup_request ADD CONSTRAINT lookup_request_table_allowed CHECK (lookup_table IN ('{$allowed}'))");
        DB::statement('ALTER TABLE lookup_request ADD CONSTRAINT lookup_request_code_not_empty CHECK (length(btrim(code)) > 0)');
        DB::statement('ALTER TABLE lookup_request ADD CONSTRAINT lookup_request_label_id_not_empty CHECK (length(btrim(label_id)) > 0)');
        DB::statement('ALTER TABLE lookup_request ADD CONSTRAINT lookup_request_label_ja_not_empty CHECK (length(btrim(label_ja)) > 0)');
        DB::statement('ALTER TABLE company_request ADD CONSTRAINT company_request_nama_ja_not_empty CHECK (length(btrim(nama_ja)) > 0)');

        foreach (['lookup_request', 'company_request'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reviewer_not_maker CHECK (reviewed_by IS NULL OR reviewed_by <> requested_by)");
            DB::statement(<<<SQL
                ALTER TABLE {$table} ADD CONSTRAINT {$table}_decision_fields CHECK (
                    (status = 'pending' AND reviewed_by IS NULL AND reviewed_at IS NULL AND note_checker IS NULL)
                    OR (status = 'approved' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
                    OR (status = 'rejected' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND length(btrim(note_checker)) > 0)
                )
                SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lookup_company_request_lifecycle()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'request_no_delete: rows cannot be deleted'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' THEN
                        RAISE EXCEPTION 'request_insert_pending_only: request must start pending'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending' OR NEW.status NOT IN ('approved', 'rejected') THEN
                    RAISE EXCEPTION 'request decision is final' USING ERRCODE = '23514';
                END IF;

                IF TG_TABLE_NAME = 'lookup_request' THEN
                    IF ROW(
                        NEW.id, NEW.lookup_table, NEW.code, NEW.label_id, NEW.label_ja,
                        NEW.extra, NEW.requested_by, NEW.reason, NEW.created_at
                    ) IS DISTINCT FROM ROW(
                        OLD.id, OLD.lookup_table, OLD.code, OLD.label_id, OLD.label_ja,
                        OLD.extra, OLD.requested_by, OLD.reason, OLD.created_at
                    ) THEN
                        RAISE EXCEPTION 'lookup_request_provenance_immutable: provenance is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF TG_TABLE_NAME = 'company_request' THEN
                    IF ROW(
                        NEW.id, NEW.nama_ja, NEW.nama_romaji, NEW.nama_id,
                        NEW.requested_by, NEW.reason, NEW.created_at
                    ) IS DISTINCT FROM ROW(
                        OLD.id, OLD.nama_ja, OLD.nama_romaji, OLD.nama_id,
                        OLD.requested_by, OLD.reason, OLD.created_at
                    ) THEN
                        RAISE EXCEPTION 'company_request_provenance_immutable: provenance is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
            SQL);

        foreach (['lookup_request', 'company_request'] as $table) {
            DB::statement(<<<SQL
                CREATE TRIGGER trg_{$table}_lifecycle
                BEFORE INSERT OR UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION lookup_company_request_lifecycle()
                SQL);
        }

        $runtime = $this->quotedRuntimeRole();

        foreach (['lookup_request', 'company_request'] as $table) {
            DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON TABLE {$table} FROM {$runtime}");
            DB::statement(
                "GRANT UPDATE (status, reviewed_by, note_checker, reviewed_at, updated_at) ON TABLE {$table} TO {$runtime}"
            );
        }
    }

    public function down(): void
    {
        $runtime = $this->quotedRuntimeRole();

        foreach (['lookup_request', 'company_request'] as $table) {
            DB::statement(
                "REVOKE UPDATE (status, reviewed_by, note_checker, reviewed_at, updated_at) ON TABLE {$table} FROM {$runtime}"
            );
            DB::statement("GRANT UPDATE, DELETE ON TABLE {$table} TO {$runtime}");
        }

        Schema::dropIfExists('lookup_request');
        Schema::dropIfExists('company_request');
        DB::statement('DROP FUNCTION IF EXISTS lookup_company_request_lifecycle()');
    }

    private function decisionColumns(Blueprint $table): void
    {
        $table->string('status')->default('pending');
        $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnUpdate()->restrictOnDelete();
        $table->text('note_checker')->nullable();
        $table->timestampTz('created_at')->useCurrent();
        $table->timestampTz('reviewed_at')->nullable();
        $table->timestampTz('updated_at')->useCurrent();
    }

    private function quotedRuntimeRole(): string
    {
        $runtime = (string) config('database.connections.pgsql.username', '');
        $migrator = (string) config('database.connections.pgsql_migrator.username', '');

        if ($runtime === '' || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $runtime) !== 1) {
            throw new RuntimeException('Runtime database role is missing or invalid.');
        }

        if ($migrator === '' || $runtime === $migrator) {
            throw new RuntimeException('A separate migrator database role is required.');
        }

        if ((string) DB::selectOne('select current_user as usr')->usr !== $migrator) {
            throw new RuntimeException(
                'Privilege migration must run as the migrator role; use --database=pgsql_migrator.'
            );
        }

        $quoted = DB::selectOne('select quote_ident(?) as quoted', [$runtime])->quoted ?? null;

        if (! is_string($quoted) || $quoted === '') {
            throw new RuntimeException('Failed to quote runtime database role via quote_ident.');
        }

        return $quoted;
    }
};
