<?php

namespace Modules\GuestAccess;

use RuntimeException;

/**
 * Immutable per-request guest context resolved from a validated session.
 * The container id always comes from the token session, never from the client.
 */
final readonly class GuestSession
{
    public function __construct(
        public int $linkId,
        public int $containerId,
        public string $tokenHash,
    ) {}

    public function requireContainerId(int $expected): void
    {
        if ($this->containerId !== $expected) {
            throw new RuntimeException('GUEST_SCOPE_MISMATCH');
        }
    }
}
