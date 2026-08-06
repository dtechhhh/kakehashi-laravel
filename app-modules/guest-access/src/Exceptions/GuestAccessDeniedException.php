<?php

namespace Modules\GuestAccess\Exceptions;

use RuntimeException;

/**
 * Generic gate failure (W6-T2). The message is deliberately identical for
 * every failure reason so the surface never helps enumeration; only the HTTP
 * status differs when the failure is a rate limit (429) vs a plain denial.
 */
final class GuestAccessDeniedException extends RuntimeException
{
    public function __construct(public readonly bool $isThrottled = false)
    {
        parent::__construct('GUEST_DENIED');
    }
}
