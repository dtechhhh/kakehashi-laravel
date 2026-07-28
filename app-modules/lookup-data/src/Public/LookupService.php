<?php

namespace Modules\LookupData\Public;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LookupService
{
    private const TABLES = [
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

    public function resolve(string $table, ?string $code, ?string $lang = null): string
    {
        $this->assertTable($table);

        if (blank($code)) {
            return '';
        }

        $row = DB::table($table)
            ->where('code', $code)
            ->first(['code', 'label_id', 'label_ja']);

        if ($row === null) {
            return $code;
        }

        return $this->label($row, $lang, $code);
    }

    /**
     * @return array<string, string>
     */
    public function options(string $table, ?string $lang = null): array
    {
        $this->assertTable($table);
        $lang = $this->language($lang);

        return Cache::store('redis')->remember(
            "lookup:{$table}:{$lang}",
            now()->addDay(),
            fn (): array => DB::table($table)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['code', 'label_id', 'label_ja'])
                ->mapWithKeys(fn (object $row): array => [$row->code => $this->label($row, $lang, $row->code)])
                ->all(),
        );
    }

    public function assertActive(string $table, string $code): void
    {
        $this->assertTable($table);

        if (! DB::table($table)->where('code', $code)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Lookup tidak aktif atau tidak ditemukan.',
            ]);
        }
    }

    public function flush(string $table): void
    {
        $this->assertTable($table);

        foreach (['id', 'ja'] as $lang) {
            Cache::store('redis')->forget("lookup:{$table}:{$lang}");
        }
    }

    private function assertTable(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown lookup table.');
        }
    }

    private function language(?string $lang): string
    {
        return strtolower($lang ?? app()->getLocale()) === 'ja' ? 'ja' : 'id';
    }

    private function label(object $row, ?string $lang, string $code): string
    {
        $label = $this->language($lang) === 'ja' ? $row->label_ja : $row->label_id;

        return filled($label) ? $label : ($row->label_id ?: $row->label_ja ?: $code);
    }
}
