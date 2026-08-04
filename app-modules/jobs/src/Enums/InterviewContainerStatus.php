<?php

namespace Modules\Jobs\Enums;

enum InterviewContainerStatus: string
{
    case DRAFT = 'Draft';
    case PENDING_APPROVAL = 'Menunggu Approval';
    case ACTIVE = 'Aktif';
    case CLOSED = 'Ditutup';
    case CANCELLED = 'Dibatalkan';

    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOSED, self::CANCELLED], true);
    }

    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
