<?php

namespace Modules\Placement\Enums;

enum PlacementContainerStatus: string
{
    case DRAFT = 'Draft';
    case PENDING_APPROVAL = 'Menunggu Approval';
    case ACTIVE = 'Aktif';
    case ARCHIVED = 'Arsip';
    case CANCELLED = 'Dibatalkan';

    public function isTerminal(): bool
    {
        return in_array($this, [self::ARCHIVED, self::CANCELLED], true);
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
