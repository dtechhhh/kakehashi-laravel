<?php

namespace Shared\Approval;

/**
 * DATABASE_SCHEMA §5.7 — lifecycle pending_request (mirrors the DB CHECK).
 */
enum PendingStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
