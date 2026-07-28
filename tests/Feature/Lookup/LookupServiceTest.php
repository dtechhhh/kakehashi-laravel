<?php

namespace Tests\Feature\Lookup;

use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\LookupData\Public\LookupService;
use Tests\TestCase;

class LookupServiceTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearLookupCache();
    }

    public function test_resolve_returns_bilingual_labels_and_renders_disabled_values(): void
    {
        $this->seed(LookupSeeder::class);
        $service = app(LookupService::class);

        $this->assertSame('Jepang', $service->resolve('negara', 'JP', 'id'));
        $this->assertSame('日本', $service->resolve('negara', 'JP', 'ja'));

        DB::table('negara')->where('code', 'JP')->update(['is_active' => false]);

        $this->assertSame('Jepang', $service->resolve('negara', 'JP', 'id'));
        $this->assertSame('UNKNOWN', $service->resolve('negara', 'UNKNOWN', 'ja'));
    }

    public function test_options_are_bilingual_and_exclude_inactive_values(): void
    {
        $this->seed(LookupSeeder::class);
        $service = app(LookupService::class);

        DB::table('negara')->where('code', 'JP')->update(['is_active' => false]);

        $this->assertArrayNotHasKey('JP', $service->options('negara', 'id'));
        $this->assertSame('Indonesia', $service->options('negara', 'id')['ID']);
        $this->assertSame('インドネシア', $service->options('negara', 'ja')['ID']);
    }

    public function test_assert_active_reads_business_truth_and_rejects_missing_or_inactive_values(): void
    {
        $this->seed(LookupSeeder::class);
        $service = app(LookupService::class);

        $this->assertArrayHasKey('JP', $service->options('negara', 'id'));

        DB::table('negara')->where('code', 'JP')->update(['is_active' => false]);

        try {
            $service->assertActive('negara', 'JP');
            $this->fail('Expected an HTTP 422 validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_assert_active_rejects_unknown_values_with_http_422_exception(): void
    {
        $this->seed(LookupSeeder::class);

        try {
            app(LookupService::class)->assertActive('negara', 'UNKNOWN');
            $this->fail('Expected an HTTP 422 validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
        }
    }

    public function test_lookup_seeder_invalidates_both_languages_after_write(): void
    {
        $this->seed(LookupSeeder::class);

        DB::table('agama')->where('code', 'ISLAM')->update([
            'label_id' => 'Cache Lama',
            'label_ja' => '古いキャッシュ',
        ]);

        $service = app(LookupService::class);
        $this->assertSame('Cache Lama', $service->options('agama', 'id')['ISLAM']);
        $this->assertSame('古いキャッシュ', $service->options('agama', 'ja')['ISLAM']);

        $this->seed(LookupSeeder::class);

        $this->assertTrue(Cache::store('redis')->missing('lookup:agama:id'));
        $this->assertTrue(Cache::store('redis')->missing('lookup:agama:ja'));
        $this->assertSame('Islam', $service->options('agama', 'id')['ISLAM']);
        $this->assertSame('イスラム教', $service->options('agama', 'ja')['ISLAM']);
    }

    public function test_resolve_and_options_use_the_application_locale_when_language_is_omitted(): void
    {
        $this->seed(LookupSeeder::class);
        $service = app(LookupService::class);
        $locale = app()->getLocale();

        try {
            app()->setLocale('ja');
            $this->assertSame('日本', $service->resolve('negara', 'JP'));
            $this->assertSame('インドネシア', $service->options('negara')['ID']);

            app()->setLocale('id');
            $this->assertSame('Jepang', $service->resolve('negara', 'JP'));
            $this->assertSame('Indonesia', $service->options('negara')['ID']);
        } finally {
            app()->setLocale($locale);
        }
    }

    public function test_unknown_table_is_rejected_before_querying_database(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(LookupService::class)->options('users', 'id');
    }

    protected function tearDown(): void
    {
        try {
            $this->clearLookupCache();
        } finally {
            parent::tearDown();
        }
    }

    private function clearLookupCache(): void
    {
        foreach (self::LOOKUP_TABLES as $table) {
            foreach (['id', 'ja'] as $lang) {
                Cache::store('redis')->forget("lookup:{$table}:{$lang}");
            }
        }
    }
}
