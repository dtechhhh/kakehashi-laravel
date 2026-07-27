<?php

namespace Tests\Feature\Lookup;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LookupSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP_TABLES = [
        'negara',
        'bahasa',
        'provinsi',
        'kota_kabupaten',
        'kecamatan',
        'agama',
        'golongan_darah',
        'ukuran_sepatu',
        'tingkat_penglihatan',
        'asal_rekrutmen',
        'status_keluarga',
        'tingkat_pendidikan',
        'jurusan',
        'bidang_pekerjaan',
        'posisi_pekerjaan',
        'bidang_industri_perusahaan',
        'bidang_diminati',
        'jenis_kualifikasi_bahasa_inggris',
        'jenis_kualifikasi_bahasa_jepang',
        'skill_ssw',
        'kualifikasi_mengemudi',
        'kualifikasi_keahlian_lainnya',
        'jenis_visa',
        'kategori_force_majeur',
        'jenis_dokumen',
    ];

    public function test_all_lookup_and_company_tables_match_the_schema_contract(): void
    {
        $this->assertCount(25, self::LOOKUP_TABLES);

        foreach (self::LOOKUP_TABLES as $table) {
            $this->assertTrue(Schema::hasColumns($table, [
                'id',
                'code',
                'label_id',
                'label_ja',
                'sort_order',
                'is_active',
                'created_at',
                'updated_at',
            ]), "{$table} is missing common lookup columns");

            $this->assertIdentity($table);

            $columns = collect(DB::select(
                'SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                   AND column_name IN (?, ?, ?, ?, ?, ?, ?)',
                [$table, 'code', 'label_id', 'label_ja', 'sort_order', 'is_active', 'created_at', 'updated_at']
            ))->keyBy('column_name');

            $this->assertSame('character varying', $columns['code']->data_type);
            $this->assertSame(64, $columns['code']->character_maximum_length);
            $this->assertSame('NO', $columns['code']->is_nullable);
            $this->assertSame('character varying', $columns['label_id']->data_type);
            $this->assertSame(255, $columns['label_id']->character_maximum_length);
            $this->assertSame('NO', $columns['label_id']->is_nullable);
            $this->assertSame('character varying', $columns['label_ja']->data_type);
            $this->assertSame(255, $columns['label_ja']->character_maximum_length);
            $this->assertSame('NO', $columns['label_ja']->is_nullable);
            $this->assertSame('integer', $columns['sort_order']->data_type);
            $this->assertSame('NO', $columns['sort_order']->is_nullable);
            $this->assertSame('0', $columns['sort_order']->column_default);
            $this->assertSame('boolean', $columns['is_active']->data_type);
            $this->assertSame('NO', $columns['is_active']->is_nullable);
            $this->assertSame('true', $columns['is_active']->column_default);

            foreach (['created_at', 'updated_at'] as $timestamp) {
                $this->assertSame('timestamp with time zone', $columns[$timestamp]->data_type);
                $this->assertSame('NO', $columns[$timestamp]->is_nullable);
                $this->assertStringContainsString('CURRENT_TIMESTAMP', $columns[$timestamp]->column_default);
            }

            foreach ([
                "{$table}_code_unique",
                "{$table}_code_not_empty",
                "{$table}_code_format",
                "{$table}_label_id_not_empty",
                "{$table}_label_ja_not_empty",
            ] as $constraint) {
                $this->assertDatabaseConstraintExists($table, $constraint);
            }

            $trigger = DB::selectOne(
                'SELECT 1 AS ok
                 FROM pg_trigger
                 WHERE tgrelid = ?::regclass
                   AND tgname = ?
                   AND NOT tgisinternal',
                [$table, "trg_{$table}_code_immutable"]
            );

            $this->assertNotNull($trigger, "{$table} must enforce immutable code");
        }

        $extraColumns = [
            'negara' => ['region', 'dial_code'],
            'provinsi' => ['negara_id'],
            'kota_kabupaten' => ['provinsi_id'],
            'kecamatan' => ['kota_kabupaten_id'],
            'posisi_pekerjaan' => ['bidang_pekerjaan_id'],
            'skill_ssw' => ['bidang_id', 'is_shareable'],
            'kualifikasi_keahlian_lainnya' => ['is_shareable'],
            'jenis_visa' => ['kategori'],
        ];

        foreach ($extraColumns as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }

        $this->assertTrue(Schema::hasColumns('perusahaan', [
            'id',
            'nama_ja',
            'nama_romaji',
            'nama_id',
            'negara_id',
            'bidang_industri_id',
            'alamat',
            'is_active',
            'created_at',
            'updated_at',
        ]));
        $this->assertIdentity('perusahaan');
        $this->assertDatabaseConstraintExists('perusahaan', 'perusahaan_nama_ja_not_empty');

        $companyColumns = collect(DB::select(
            'SELECT column_name, data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = ?
               AND column_name IN (?, ?, ?, ?)',
            ['perusahaan', 'nama_ja', 'is_active', 'created_at', 'updated_at']
        ))->keyBy('column_name');

        $this->assertSame('text', $companyColumns['nama_ja']->data_type);
        $this->assertSame('NO', $companyColumns['nama_ja']->is_nullable);
        $this->assertSame('NO', $companyColumns['is_active']->is_nullable);
        $this->assertSame('true', $companyColumns['is_active']->column_default);

        foreach (['created_at', 'updated_at'] as $timestamp) {
            $this->assertSame('NO', $companyColumns[$timestamp]->is_nullable);
            $this->assertStringContainsString('CURRENT_TIMESTAMP', $companyColumns[$timestamp]->column_default);
        }

        foreach (['skill_ssw', 'kualifikasi_keahlian_lainnya'] as $table) {
            $shareable = DB::selectOne(
                'SELECT data_type, is_nullable, column_default
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                   AND column_name = ?',
                [$table, 'is_shareable']
            );

            $this->assertNotNull($shareable);
            $this->assertSame('boolean', $shareable->data_type);
            $this->assertSame('NO', $shareable->is_nullable);
            $this->assertSame('false', $shareable->column_default);
        }

        $activeIndex = DB::selectOne(
            "SELECT 1 AS ok FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'perusahaan'
               AND indexname = 'idx_perusahaan_active'"
        );

        $this->assertNotNull($activeIndex);
    }

    public function test_valid_values_and_company_can_be_stored_through_the_runtime_connection(): void
    {
        $ids = [];

        foreach (self::LOOKUP_TABLES as $index => $table) {
            $code = match ($table) {
                'negara' => 'ID',
                'bahasa' => 'id',
                default => 'VALID_'.$index,
            };

            $ids[$table] = DB::table($table)->insertGetId([
                'code' => $code,
                'label_id' => 'Label Indonesia',
                'label_ja' => '日本語ラベル',
            ]);

            $stored = DB::table($table)->where('id', $ids[$table])->sole();

            $this->assertSame(0, $stored->sort_order);
            $this->assertTrue($stored->is_active);
            $this->assertNotNull($stored->created_at);
            $this->assertNotNull($stored->updated_at);

            if (in_array($table, ['skill_ssw', 'kualifikasi_keahlian_lainnya'], true)) {
                $this->assertFalse($stored->is_shareable);
            }
        }

        $companyId = DB::table('perusahaan')->insertGetId([
            'nama_ja' => '架け橋株式会社',
            'negara_id' => $ids['negara'],
            'bidang_industri_id' => $ids['bidang_industri_perusahaan'],
        ]);

        $this->assertDatabaseCount('perusahaan', 1);
        $this->assertDatabaseHas('perusahaan', [
            'id' => $companyId,
            'nama_ja' => '架け橋株式会社',
            'is_active' => true,
        ]);

        $company = DB::table('perusahaan')->where('id', $companyId)->sole();
        $this->assertNotNull($company->created_at);
        $this->assertNotNull($company->updated_at);
    }

    public function test_code_unique_and_format_constraints_reject_invalid_values(): void
    {
        foreach (self::LOOKUP_TABLES as $index => $table) {
            $code = match ($table) {
                'negara' => 'JP',
                'bahasa' => 'ja',
                default => 'UNIQUE_'.$index,
            };

            DB::table($table)->insert($this->lookupRow($code));

            $this->assertDatabaseViolation(
                fn () => DB::table($table)->insert($this->lookupRow($code)),
                "{$table}_code_unique"
            );

            $invalidCode = match ($table) {
                'negara' => 'JPN',
                'bahasa' => 'JA',
                default => 'invalid-code',
            };

            $this->assertDatabaseViolation(
                fn () => DB::table($table)->insert($this->lookupRow($invalidCode)),
                "{$table}_code_format"
            );
        }

        $this->assertDatabaseViolation(
            fn () => DB::table('agama')->insert($this->lookupRow('   ')),
            'violates check constraint'
        );
    }

    public function test_required_lookup_and_company_fields_reject_null_omitted_and_blank_values(): void
    {
        foreach (['code', 'label_id', 'label_ja'] as $column) {
            $nullRow = $this->lookupRow('VALID');
            $nullRow[$column] = null;

            $this->assertDatabaseViolation(
                fn () => DB::table('agama')->insert($nullRow),
                "null value in column \"{$column}\""
            );

            $omittedRow = $this->lookupRow('VALID');
            unset($omittedRow[$column]);

            $this->assertDatabaseViolation(
                fn () => DB::table('agama')->insert($omittedRow),
                "null value in column \"{$column}\""
            );
        }

        $this->assertDatabaseViolation(
            fn () => DB::table('agama')->insert($this->lookupRow('VALID', ['label_id' => '   '])),
            'agama_label_id_not_empty'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('agama')->insert($this->lookupRow('VALID', ['label_ja' => '   '])),
            'agama_label_ja_not_empty'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('perusahaan')->insert(['nama_ja' => '   ']),
            'perusahaan_nama_ja_not_empty'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('perusahaan')->insert(['nama_ja' => null]),
            'null value in column "nama_ja"'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('perusahaan')->insert(['nama_id' => 'Kakehashi']),
            'null value in column "nama_ja"'
        );
    }

    public function test_code_is_immutable_on_every_lookup_table(): void
    {
        foreach (self::LOOKUP_TABLES as $index => $table) {
            [$original, $changed] = match ($table) {
                'negara' => ['ID', 'JP'],
                'bahasa' => ['id', 'ja'],
                default => ["ORIGINAL_{$index}", "CHANGED_{$index}"],
            };

            $id = DB::table($table)->insertGetId($this->lookupRow($original));

            $this->assertDatabaseViolation(
                fn () => DB::table($table)->where('id', $id)->update(['code' => $changed]),
                'lookup code is immutable'
            );

            $this->assertSame($original, DB::table($table)->where('id', $id)->value('code'));
        }
    }

    public function test_lookup_hierarchy_and_company_foreign_keys_restrict_invalid_references(): void
    {
        $foreignKeys = [
            'provinsi_negara_id_foreign',
            'kota_kabupaten_provinsi_id_foreign',
            'kecamatan_kota_kabupaten_id_foreign',
            'posisi_pekerjaan_bidang_pekerjaan_id_foreign',
            'skill_ssw_bidang_id_foreign',
            'perusahaan_negara_id_foreign',
            'perusahaan_bidang_industri_id_foreign',
        ];

        foreach ($foreignKeys as $constraint) {
            $definition = DB::selectOne(
                'SELECT pg_get_constraintdef(oid) AS definition
                 FROM pg_constraint
                 WHERE connamespace = current_schema()::regnamespace
                   AND conname = ?',
                [$constraint]
            );

            $this->assertNotNull($definition, "{$constraint} must exist");
            $this->assertStringContainsString('ON DELETE RESTRICT', $definition->definition);
            $this->assertStringContainsString('ON UPDATE RESTRICT', $definition->definition);
        }

        $this->assertDatabaseViolation(
            fn () => DB::table('provinsi')->insert($this->lookupRow('JABAR', ['negara_id' => PHP_INT_MAX])),
            'provinsi_negara_id_foreign'
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function lookupRow(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'label_id' => 'Label Indonesia',
            'label_ja' => '日本語ラベル',
        ], $overrides);
    }

    private function assertIdentity(string $table): void
    {
        $identity = DB::selectOne(
            "SELECT is_identity, identity_generation
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = ?
               AND column_name = 'id'",
            [$table]
        );

        $this->assertNotNull($identity);
        $this->assertSame('YES', $identity->is_identity);
        $this->assertSame('ALWAYS', $identity->identity_generation);
    }

    private function assertDatabaseConstraintExists(string $table, string $constraint): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok
             FROM pg_constraint
             WHERE conrelid = ?::regclass
               AND conname = ?',
            [$table, $constraint]
        );

        $this->assertNotNull($exists, "{$constraint} must exist on {$table}");
    }

    private function assertDatabaseViolation(Closure $callback, string $message): void
    {
        static $counter = 0;

        $savepoint = 'lookup_violation_'.++$counter;
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $callback();
            $this->fail("Expected database violation containing: {$message}");
        } catch (QueryException $exception) {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            $this->assertStringContainsString($message, $exception->getMessage());
        } finally {
            DB::statement("RELEASE SAVEPOINT {$savepoint}");
        }
    }
}
