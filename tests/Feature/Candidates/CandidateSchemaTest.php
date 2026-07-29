<?php

namespace Tests\Feature\Candidates;

use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CandidateSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE_COLUMNS = [
        'candidate' => [
            'id', 'nomor_induk', 'nama_alphabet', 'nama_katakana', 'tanggal_lahir',
            'tempat_lahir_kota_id', 'alamat_detail', 'email', 'phone', 'line_id',
            'kewarganegaraan_id', 'asal_rekrutmen_id', 'agama_id', 'alamat_provinsi_id',
            'alamat_kota_kabupaten_id', 'alamat_kecamatan_id', 'jenis_kelamin',
            'status_pernikahan', 'status_ketersediaan', 'status_approval',
            'parent_candidate_id', 'version', 'created_by', 'approved_by',
            'catatan_penolakan_terakhir', 'catatan_tambahan', 'deleted_at',
            'pii_anonymized_at', 'created_at', 'updated_at',
        ],
        'candidate_physical' => [
            'id', 'candidate_id', 'tinggi_cm', 'berat_kg', 'lingkar_perut_cm',
            'golongan_darah_id', 'ukuran_sepatu_id', 'penglihatan_kiri_id',
            'penglihatan_kanan_id', 'dominan_tangan', 'buta_warna', 'merokok',
            'minum_sake', 'pembatasan_makanan', 'riwayat_penyakit',
            'riwayat_operasi', 'catatan_kesehatan', 'created_at', 'updated_at',
        ],
        'candidate_education' => [
            'id', 'candidate_id', 'tingkat_pendidikan_id', 'jurusan_id',
            'nama_institusi', 'tanggal_masuk', 'tanggal_keluar', 'sort_order',
            'created_at', 'updated_at',
        ],
        'candidate_work' => [
            'id', 'candidate_id', 'nama_perusahaan', 'perusahaan_penanggung',
            'bidang_pekerjaan_id', 'tanggal_masuk', 'tanggal_keluar', 'sort_order',
            'created_at', 'updated_at',
        ],
        'candidate_qual_english' => [
            'id', 'candidate_id', 'jenis_id', 'tanggal_akuisisi', 'skor',
            'url_file', 'created_at', 'updated_at',
        ],
        'candidate_qual_japanese' => [
            'id', 'candidate_id', 'jenis_id', 'tanggal_akuisisi', 'skor',
            'url_file', 'created_at', 'updated_at',
        ],
        'candidate_qual_ssw' => [
            'id', 'candidate_id', 'skill_ssw_id', 'tanggal_akuisisi', 'url_file',
            'created_at', 'updated_at',
        ],
        'candidate_qual_driving' => [
            'id', 'candidate_id', 'kualifikasi_mengemudi_id', 'tanggal_akuisisi',
            'created_at', 'updated_at',
        ],
        'candidate_qual_other' => [
            'id', 'candidate_id', 'kualifikasi_keahlian_lainnya_id',
            'tanggal_akuisisi', 'url_file', 'created_at', 'updated_at',
        ],
        'candidate_self_promo' => [
            'id', 'candidate_id', 'skor_iq', 'skor_matematika',
            'bidang_diminati_id', 'video_jikoshokai_url', 'video_keahlian_url',
            'final_laporan_psikotes', 'created_at', 'updated_at',
        ],
        'candidate_family' => [
            'id', 'candidate_id', 'status_keluarga_id', 'nama', 'tanggal_lahir',
            'sort_order', 'created_at', 'updated_at',
        ],
        'candidate_family_contact' => [
            'id', 'candidate_id', 'status_keluarga_id', 'nama', 'phone', 'alamat',
            'created_at', 'updated_at',
        ],
        'candidate_immigration' => [
            'id', 'candidate_id', 'nomor_paspor', 'masa_berlaku_paspor',
            'nomor_zairyu', 'alamat_zairyu', 'jenis_visa_id', 'pernah_ke_jepang',
            'catatan', 'created_at', 'updated_at',
        ],
        'candidate_document' => [
            'id', 'candidate_id', 'jenis_dokumen_id', 'url_dokumen', 'nama_file',
            'catatan', 'uploaded_by', 'sort_order', 'created_at', 'updated_at',
        ],
        'candidate_photo' => [
            'id', 'candidate_id', 'object_key', 'mime_type', 'size_bytes',
            'uploaded_by', 'created_at', 'updated_at',
        ],
        'nik_counter' => ['year', 'last_value', 'updated_at'],
    ];

    public function test_candidate_schema_contains_only_the_final_domain_tables_and_columns(): void
    {
        $this->assertCount(16, self::TABLE_COLUMNS);

        foreach (self::TABLE_COLUMNS as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), "{$table} is missing required columns");

            $actual = collect(DB::select(
                'SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                 ORDER BY ordinal_position',
                [$table]
            ))->pluck('column_name')->all();

            $this->assertSame($columns, $actual, "{$table} contains unexpected columns");

            if ($table !== 'nik_counter') {
                $this->assertIdentity($table);
                $this->assertTimestampColumns($table);
            }
        }

        $this->assertFalse(Schema::hasTable('candidate_participation'));
        $this->assertFalse(Schema::hasTable('candidate_identity_doc'));
    }

    public function test_candidate_defaults_and_required_fields_are_enforced_on_postgresql(): void
    {
        [$user, $country] = $this->candidateDependencies();
        $candidateId = DB::table('candidate')->insertGetId($this->candidateRow($user->getKey(), $country));
        $candidate = DB::table('candidate')->where('id', $candidateId)->sole();

        $this->assertNull($candidate->nomor_induk);
        $this->assertSame('TERSEDIA', $candidate->status_ketersediaan);
        $this->assertSame('Draft', $candidate->status_approval);
        $this->assertSame(0, $candidate->version);
        $this->assertNull($candidate->catatan_penolakan_terakhir);
        $this->assertNull($candidate->catatan_tambahan);
        $this->assertNotNull($candidate->created_at);
        $this->assertNotNull($candidate->updated_at);

        DB::table('nik_counter')->insert(['year' => 2026]);
        $counter = DB::table('nik_counter')->where('year', 2026)->sole();

        $this->assertSame(0, $counter->last_value);
        $this->assertNotNull($counter->updated_at);

        foreach (['nama_alphabet', 'tanggal_lahir', 'kewarganegaraan_id', 'jenis_kelamin', 'created_by'] as $column) {
            $row = $this->candidateRow($user->getKey(), $country);
            unset($row[$column]);

            $this->assertDatabaseViolation(
                fn () => DB::table('candidate')->insert($row),
                "null value in column \"{$column}\""
            );
        }
    }

    public function test_candidate_enum_and_maker_checker_constraints_reject_invalid_rows(): void
    {
        [$maker, $country] = $this->candidateDependencies();
        $checker = User::factory()->create();

        foreach ([
            ['jenis_kelamin' => 'X'],
            ['status_pernikahan' => 'UNKNOWN'],
            ['status_ketersediaan' => 'UNKNOWN'],
            ['status_approval' => 'UNKNOWN'],
        ] as $override) {
            $this->assertDatabaseViolation(
                fn () => DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country, $override)),
                'violates check constraint'
            );
        }

        foreach ([
            ['status_approval' => 'Disetujui'],
            ['status_approval' => 'Disetujui', 'approved_by' => $maker->getKey()],
        ] as $override) {
            $this->assertDatabaseViolation(
                fn () => DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country, $override)),
                'candidate_maker_checker'
            );
        }

        $approvedId = DB::table('candidate')->insertGetId($this->candidateRow($maker->getKey(), $country, [
            'status_approval' => 'Disetujui',
            'approved_by' => $checker->getKey(),
        ]));

        $this->assertDatabaseHas('candidate', [
            'id' => $approvedId,
            'status_approval' => 'Disetujui',
            'approved_by' => $checker->getKey(),
        ]);

        $this->assertDatabaseViolation(
            fn () => DB::table('candidate_physical')->insert([
                'candidate_id' => $approvedId,
                'dominan_tangan' => 'AMBIDEXTROUS',
            ]),
            'candidate_physical_dominan_tangan_check'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('candidate_physical')->insert([
                'candidate_id' => $approvedId,
                'buta_warna' => 'MAYBE',
            ]),
            'candidate_physical_buta_warna_check'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('candidate_immigration')->insert([
                'candidate_id' => $approvedId,
                'pernah_ke_jepang' => 'MAYBE',
            ]),
            'candidate_immigration_pernah_ke_jepang_check'
        );
    }

    public function test_nomor_induk_and_active_revision_constraints_are_database_backed(): void
    {
        [$maker, $country] = $this->candidateDependencies();
        $checker = User::factory()->create();

        DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country));
        DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country));

        $mainId = DB::table('candidate')->insertGetId($this->candidateRow($maker->getKey(), $country, [
            'nomor_induk' => 'K-2026-00001',
            'status_approval' => 'Disetujui',
            'approved_by' => $checker->getKey(),
        ]));

        $this->assertDatabaseViolation(
            fn () => DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country, [
                'nomor_induk' => 'K-2026-00001',
            ])),
            'candidate_nomor_induk_unique'
        );

        $revisionId = DB::table('candidate')->insertGetId($this->candidateRow($maker->getKey(), $country, [
            'parent_candidate_id' => $mainId,
        ]));

        $this->assertDatabaseViolation(
            fn () => DB::table('candidate')->insert($this->candidateRow($maker->getKey(), $country, [
                'parent_candidate_id' => $mainId,
                'status_approval' => 'Menunggu Tinjauan-REVISI',
            ])),
            'uq_candidate_one_active_revision'
        );

        DB::table('candidate')->where('id', $revisionId)->update(['status_approval' => 'Ditolak']);

        $nextRevision = DB::table('candidate')->insertGetId($this->candidateRow($maker->getKey(), $country, [
            'parent_candidate_id' => $mainId,
        ]));

        $this->assertDatabaseHas('candidate', ['id' => $nextRevision, 'parent_candidate_id' => $mainId]);
    }

    public function test_foreign_keys_restrict_references_and_cascade_candidate_children(): void
    {
        [$user, $country] = $this->candidateDependencies();
        $candidateId = DB::table('candidate')->insertGetId($this->candidateRow($user->getKey(), $country));

        DB::table('candidate_physical')->insert(['candidate_id' => $candidateId]);

        $this->assertDatabaseViolation(
            fn () => DB::table('negara')->where('id', $country)->delete(),
            'candidate_kewarganegaraan_id_foreign'
        );
        $this->assertDatabaseViolation(
            fn () => DB::table('candidate')->insert($this->candidateRow($user->getKey(), PHP_INT_MAX)),
            'candidate_kewarganegaraan_id_foreign'
        );

        foreach ([
            'candidate_parent_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_physical_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_education_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_work_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_qual_english_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_qual_japanese_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_qual_ssw_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_qual_driving_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_qual_other_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_self_promo_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_family_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_family_contact_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_immigration_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_document_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_photo_candidate_id_foreign' => 'ON DELETE CASCADE',
            'candidate_tempat_lahir_kota_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_kewarganegaraan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_asal_rekrutmen_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_agama_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_alamat_provinsi_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_alamat_kota_kabupaten_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_alamat_kecamatan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_created_by_foreign' => 'ON DELETE RESTRICT',
            'candidate_approved_by_foreign' => 'ON DELETE RESTRICT',
            'candidate_physical_golongan_darah_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_physical_ukuran_sepatu_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_physical_penglihatan_kiri_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_physical_penglihatan_kanan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_education_tingkat_pendidikan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_education_jurusan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_work_bidang_pekerjaan_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_qual_english_jenis_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_qual_japanese_jenis_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_qual_ssw_skill_ssw_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_qual_driving_kualifikasi_mengemudi_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_qual_other_kualifikasi_keahlian_lainnya_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_self_promo_bidang_diminati_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_family_status_keluarga_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_family_contact_status_keluarga_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_immigration_jenis_visa_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_document_jenis_dokumen_id_foreign' => 'ON DELETE RESTRICT',
            'candidate_document_uploaded_by_foreign' => 'ON DELETE RESTRICT',
            'candidate_photo_uploaded_by_foreign' => 'ON DELETE RESTRICT',
        ] as $constraint => $deleteRule) {
            $definition = $this->constraintDefinition($constraint);

            $this->assertStringContainsString($deleteRule, $definition);
            $this->assertStringContainsString('ON UPDATE RESTRICT', $definition);
        }

        DB::table('candidate')->where('id', $candidateId)->delete();

        $this->assertDatabaseMissing('candidate_physical', ['candidate_id' => $candidateId]);
    }

    public function test_candidate_postgresql_indexes_match_the_contract(): void
    {
        $indexes = collect(DB::select(
            "SELECT indexname, indexdef
             FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename LIKE 'candidate%'"
        ))->keyBy('indexname');

        foreach ([
            'candidate_nomor_induk_unique',
            'uq_candidate_one_active_revision',
            'idx_candidate_nama_alpha_trgm',
            'idx_candidate_nama_kana_trgm',
            'idx_candidate_list',
            'idx_candidate_avail',
            'idx_candidate_document_candidate',
            'candidate_physical_candidate_id_unique',
            'candidate_self_promo_candidate_id_unique',
            'candidate_family_contact_candidate_id_unique',
            'candidate_immigration_candidate_id_unique',
            'candidate_photo_candidate_id_unique',
        ] as $index) {
            $this->assertTrue($indexes->has($index), "{$index} must exist");
        }

        $this->assertStringContainsString('USING gin (lower(nama_alphabet) gin_trgm_ops)', $indexes['idx_candidate_nama_alpha_trgm']->indexdef);
        $this->assertStringContainsString('USING gin (nama_katakana gin_trgm_ops)', $indexes['idx_candidate_nama_kana_trgm']->indexdef);
        $this->assertStringContainsString('UNIQUE INDEX', $indexes['uq_candidate_one_active_revision']->indexdef);
        $this->assertStringContainsString('parent_candidate_id IS NOT NULL', $indexes['uq_candidate_one_active_revision']->indexdef);
        $this->assertStringContainsString("'Menunggu Tinjauan-REVISI'::text", $indexes['uq_candidate_one_active_revision']->indexdef);
        $this->assertStringContainsString('deleted_at IS NULL', $indexes['idx_candidate_list']->indexdef);
        $this->assertStringContainsString('pii_anonymized_at IS NULL', $indexes['idx_candidate_avail']->indexdef);
    }

    /**
     * @return array{User, int}
     */
    private function candidateDependencies(): array
    {
        $country = DB::table('negara')->insertGetId([
            'code' => 'ID',
            'label_id' => 'Indonesia',
            'label_ja' => 'インドネシア',
        ]);

        return [User::factory()->create(), $country];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function candidateRow(int $creator, int $country, array $overrides = []): array
    {
        return array_merge([
            'nama_alphabet' => 'Taro Kakehashi',
            'tanggal_lahir' => '2000-01-02',
            'kewarganegaraan_id' => $country,
            'jenis_kelamin' => 'M',
            'created_by' => $creator,
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

    private function assertTimestampColumns(string $table): void
    {
        $columns = collect(DB::select(
            'SELECT column_name, data_type, is_nullable, column_default
             FROM information_schema.columns
             WHERE table_schema = current_schema()
               AND table_name = ?
               AND column_name IN (?, ?)',
            [$table, 'created_at', 'updated_at']
        ))->keyBy('column_name');

        foreach (['created_at', 'updated_at'] as $column) {
            $this->assertSame('timestamp with time zone', $columns[$column]->data_type);
            $this->assertSame('NO', $columns[$column]->is_nullable);
            $this->assertStringContainsString('CURRENT_TIMESTAMP', $columns[$column]->column_default);
        }
    }

    private function constraintDefinition(string $constraint): string
    {
        $row = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE connamespace = current_schema()::regnamespace
               AND conname = ?',
            [$constraint]
        );

        $this->assertNotNull($row, "{$constraint} must exist");

        return $row->definition;
    }

    private function assertDatabaseViolation(Closure $callback, string $message): void
    {
        static $counter = 0;

        $savepoint = 'candidate_violation_'.++$counter;
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
