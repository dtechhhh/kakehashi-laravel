<?php

namespace Modules\Candidates\Enums;

/**
 * DATABASE_SCHEMA §2 / STATUS_STATE_MACHINE — status_approval kandidat.
 */
enum CandidateApprovalStatus: string
{
    case Draft = 'Draft';
    case MenungguTinjauanBaru = 'Menunggu Tinjauan-BARU';
    case MenungguTinjauanRevisi = 'Menunggu Tinjauan-REVISI';
    case Disetujui = 'Disetujui';
    case Ditolak = 'Ditolak';
    case Diterapkan = 'Diterapkan';

    /** Editable via draft form core (create/update before submit/approve path). */
    public function isDraftEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Ditolak => true,
            default => false,
        };
    }
}
