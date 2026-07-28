<?php

namespace Tests\Feature\Lookup;

use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LookupSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDED_COUNTS = [
        'negara' => 7,
        'bahasa' => 3,
        'provinsi' => 38,
        'agama' => 6,
        'golongan_darah' => 4,
        'tingkat_pendidikan' => 6,
        'bidang_pekerjaan' => 6,
        'skill_ssw' => 5,
        'kualifikasi_mengemudi' => 4,
        'jenis_visa' => 5,
        'jenis_dokumen' => 7,
        'status_keluarga' => 5,
        'tingkat_penglihatan' => 4,
        'kategori_force_majeur' => 6,
    ];

    public function test_database_seeder_populates_authoritative_bilingual_lookup_values(): void
    {
        $this->seed();

        foreach (self::SEEDED_COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
            $this->assertFalse(
                DB::table($table)
                    ->whereRaw('length(btrim(code)) = 0 OR length(btrim(label_id)) = 0 OR length(btrim(label_ja)) = 0')
                    ->exists(),
                "{$table} contains an empty canonical or bilingual value",
            );
        }

        $indonesiaId = DB::table('negara')->where('code', 'ID')->value('id');
        $kaigoId = DB::table('bidang_pekerjaan')->where('code', 'KAIGO')->value('id');

        $this->assertDatabaseHas('negara', [
            'code' => 'JP',
            'label_id' => 'Jepang',
            'label_ja' => '日本',
        ]);
        $this->assertDatabaseHas('provinsi', [
            'code' => 'JABAR',
            'label_id' => 'Jawa Barat',
            'label_ja' => '西ジャワ州',
            'negara_id' => $indonesiaId,
        ]);
        $this->assertDatabaseHas('skill_ssw', [
            'code' => 'SSW_KAIGO',
            'bidang_id' => $kaigoId,
            'is_shareable' => true,
        ]);
        $this->assertDatabaseHas('kategori_force_majeur', ['code' => 'MASALAH_HUKUM_IMIGRASI']);
        $this->assertDatabaseHas('jenis_dokumen', ['code' => 'ZAIRYU_CARD']);
    }

    public function test_lookup_seeder_can_run_repeatedly_without_duplicates_or_deleting_other_values(): void
    {
        $this->seed(LookupSeeder::class);

        $ids = [];

        foreach (self::SEEDED_COUNTS as $table => $count) {
            $ids[$table] = DB::table($table)->orderBy('code')->pluck('id', 'code')->all();
            $this->assertCount($count, $ids[$table]);
        }

        $this->seed(LookupSeeder::class);

        foreach (self::SEEDED_COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
            $this->assertSame($ids[$table], DB::table($table)->orderBy('code')->pluck('id', 'code')->all());
            $this->assertFalse(
                DB::table($table)
                    ->select('code')
                    ->groupBy('code')
                    ->havingRaw('count(*) > 1')
                    ->exists(),
                "{$table} contains duplicate codes after repeated seeding",
            );
        }

        DB::table('agama')->insert([
            'code' => 'CUSTOM_PENDING_AUTHORITY',
            'label_id' => 'Nilai Tambahan',
            'label_ja' => '追加値',
        ]);

        $this->seed(LookupSeeder::class);

        $this->assertDatabaseCount('agama', self::SEEDED_COUNTS['agama'] + 1);
        $this->assertDatabaseHas('agama', [
            'code' => 'CUSTOM_PENDING_AUTHORITY',
            'label_id' => 'Nilai Tambahan',
            'label_ja' => '追加値',
        ]);
    }

    public function test_reseeding_preserves_a_soft_disabled_seeded_value(): void
    {
        $this->seed(LookupSeeder::class);

        DB::table('agama')->where('code', 'ISLAM')->update(['is_active' => false]);

        $this->seed(LookupSeeder::class);

        $this->assertDatabaseHas('agama', [
            'code' => 'ISLAM',
            'is_active' => false,
        ]);
        $this->assertSame(1, DB::table('agama')->where('code', 'ISLAM')->count());
        $this->assertFalse(
            DB::table('agama')
                ->select('code')
                ->groupBy('code')
                ->havingRaw('count(*) > 1')
                ->exists(),
        );
    }
}
