<?php

namespace Modules\LookupData\Public;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LookupService
{
    private const CACHE_LOCK_SECONDS = 30;

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
        $cache = Cache::store('redis');
        $key = "lookup:{$table}:{$lang}";
        $cached = $cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        return $cache->lock($this->cacheLockKey($table), self::CACHE_LOCK_SECONDS)->block(
            self::CACHE_LOCK_SECONDS,
            fn (): array => $cache->remember(
                $key,
                now()->addDay(),
                fn (): array => DB::table($table)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['code', 'label_id', 'label_ja'])
                    ->mapWithKeys(fn (object $row): array => [$row->code => $this->label($row, $lang, $row->code)])
                    ->all(),
            ),
        );
    }

    /**
     * Active lookup values keyed by row id (for FK selects that store ids,
     * e.g. company master). Uncached, read-only.
     *
     * @return array<int, string>
     */
    public function optionsById(string $table, ?string $lang = null): array
    {
        $this->assertTable($table);
        $lang = $this->language($lang);

        return DB::table($table)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'label_id', 'label_ja'])
            ->mapWithKeys(fn (object $row): array => [$row->id => $this->label($row, $lang, $row->code)])
            ->all();
    }

    /**
     * Bilingual label for a row id (FK display). Includes inactive values so
     * old data keeps rendering; falls back to the raw id.
     */
    public function labelById(string $table, ?int $id, ?string $lang = null): string
    {
        $this->assertTable($table);

        if ($id === null) {
            return '';
        }

        $row = DB::table($table)->where('id', $id)->first(['code', 'label_id', 'label_ja']);

        return $row === null ? (string) $id : $this->label($row, $lang, (string) $id);
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

    /**
     * Read-only paginated list for the S1 screen. Includes inactive values so
     * soft-disabled rows keep rendering on old data; active-only behavior is
     * preserved by options().
     *
     * @param  array{
     *     search?: string,
     *     active?: '1'|'0'|'',
     *     sort?: string,
     *     direction?: 'asc'|'desc',
     * }  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(string $table, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->assertTable($table);

        $sortable = ['id', 'code', 'label_id', 'label_ja', 'sort_order', 'is_active', 'updated_at'];
        $sort = $filters['sort'] ?? 'sort_order';
        $direction = $filters['direction'] ?? 'asc';

        if (! in_array($sort, $sortable, true)) {
            $sort = 'sort_order';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $query = DB::table($table)
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('code', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('label_id', 'ilike', '%'.$filters['search'].'%')
                        ->orWhere('label_ja', 'ilike', '%'.$filters['search'].'%');
                });
            })
            ->when(isset($filters['active']) && $filters['active'] !== '', function ($query) use ($filters): void {
                $query->where('is_active', $filters['active'] === '1');
            });

        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Read-only single row lookup for edit prefill (includes inactive values).
     */
    public function find(string $table, int $id): ?object
    {
        $this->assertTable($table);

        return DB::table($table)->where('id', $id)->first();
    }

    public function flush(string $table): void
    {
        $this->assertTable($table);
        $cache = Cache::store('redis');

        $cache->lock($this->cacheLockKey($table), self::CACHE_LOCK_SECONDS)->block(
            self::CACHE_LOCK_SECONDS,
            function () use ($cache, $table): void {
                foreach (['id', 'ja'] as $lang) {
                    $cache->forget("lookup:{$table}:{$lang}");
                }
            },
        );
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return self::TABLES;
    }

    public function assertTable(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown lookup table.');
        }
    }

    private function cacheLockKey(string $table): string
    {
        return "lookup:{$table}:lock";
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
