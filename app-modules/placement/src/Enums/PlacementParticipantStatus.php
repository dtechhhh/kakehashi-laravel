<?php

namespace Modules\Placement\Enums;

enum PlacementParticipantStatus: string
{
    case WORKING = 'Bekerja';
    case CONTRACT_COMPLETED = 'Selesai Kontrak';
    case WITHDRAWN = 'Mengundurkan Diri';
    case EXPELLED = 'Dikeluarkan';

    public function isActive(): bool
    {
        return $this === self::WORKING;
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isTerminal()),
        );
    }
}
