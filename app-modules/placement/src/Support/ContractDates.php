<?php

namespace Modules\Placement\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * F-019 — tanggal akhir kontrak default inklusif = mulai + durasi bulan − 1
 * hari; override wajib >= tanggal mulai. Satu implementasi, dipakai batch
 * normal dan Force-Majeur.
 */
final class ContractDates
{
    public static function endDate(string $start, int $months, ?string $override = null): string
    {
        $startDate = Carbon::parse($start);

        if ($override !== null) {
            $overrideDate = Carbon::parse($override);
            if ($overrideDate->lt($startDate)) {
                throw ValidationException::withMessages([
                    'tanggal_berakhir_kontrak' => 'PC_END_BEFORE_START',
                ]);
            }

            return $overrideDate->toDateString();
        }

        return $startDate->addMonths($months)->subDay()->toDateString();
    }
}
