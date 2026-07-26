<?php

namespace Shared\Approval;

/**
 * DATABASE_SCHEMA §5.7 — whitelist tipe pending_request (mirrors the DB CHECK).
 */
enum PendingType: string
{
    case CANDIDATE_NEW = 'CANDIDATE_NEW';
    case CANDIDATE_REVISION = 'CANDIDATE_REVISION';
    case IC_CREATE = 'IC_CREATE';
    case PC_CREATE = 'PC_CREATE';
    case PLACEMENT_BATCH = 'PLACEMENT_BATCH';
    case IC_CLOSE = 'IC_CLOSE';
    case IC_EXPEL = 'IC_EXPEL';
    case GUEST_LINK = 'GUEST_LINK';
    case PC_CANCEL_ACTIVE = 'PC_CANCEL_ACTIVE';
    case PLACEMENT_RESIGN = 'PLACEMENT_RESIGN';
    case PLACEMENT_EXPEL = 'PLACEMENT_EXPEL';
    case FORCE_MAJEUR = 'FORCE_MAJEUR';

    /**
     * BR-APV-08 — payload snapshot wajib untuk Placement batch, Force-Majeur,
     * expel, resign, dan cancel (constraint pending_payload_required).
     */
    public function requiresPayload(): bool
    {
        return match ($this) {
            self::PLACEMENT_BATCH,
            self::FORCE_MAJEUR,
            self::IC_EXPEL,
            self::PC_CANCEL_ACTIVE,
            self::PLACEMENT_RESIGN,
            self::PLACEMENT_EXPEL => true,
            default => false,
        };
    }
}
