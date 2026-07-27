<?php

namespace Modules\Auth;

/**
 * Final step-up trigger catalog (PRD Lampiran D + §7.9). Exactly five actions.
 */
final class StepUpAction
{
    /** 1. Ubah role / nonaktifkan akun — Super Admin */
    public const USER_ROLE_OR_DEACTIVATE = 'USER_ROLE_OR_DEACTIVATE';

    /** 2. Setujui penutupan kontainer wawancara */
    public const APPROVE_INTERVIEW_CLOSE = 'APPROVE_INTERVIEW_CLOSE';

    /** 3. Setujui keluarkan kandidat (wawancara) / cabut penempatan */
    public const APPROVE_CANDIDATE_EXPEL = 'APPROVE_CANDIDATE_EXPEL';

    /** 4. Kelola lookup/config + master perusahaan */
    public const MANAGE_LOOKUP_OR_COMPANY = 'MANAGE_LOOKUP_OR_COMPANY';

    /** 5. Anonimisasi PII kandidat */
    public const ANONYMIZE_PII = 'ANONYMIZE_PII';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::USER_ROLE_OR_DEACTIVATE,
            self::APPROVE_INTERVIEW_CLOSE,
            self::APPROVE_CANDIDATE_EXPEL,
            self::MANAGE_LOOKUP_OR_COMPANY,
            self::ANONYMIZE_PII,
        ];
    }

    public static function isValid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}
