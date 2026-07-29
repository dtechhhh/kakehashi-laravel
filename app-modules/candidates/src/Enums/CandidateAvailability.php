<?php

namespace Modules\Candidates\Enums;

/**
 * DATABASE_SCHEMA — status_ketersediaan. Mutasi hanya via public service (W3-T6).
 */
enum CandidateAvailability: string
{
    case Tersedia = 'TERSEDIA';
    case SedangDipakai = 'SEDANG_DIPAKAI';
}
