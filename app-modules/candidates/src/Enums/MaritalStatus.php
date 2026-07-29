<?php

namespace Modules\Candidates\Enums;

/** PRD §5.2 / MODULE_CANDIDATES — status_pernikahan kanonik. */
enum MaritalStatus: string
{
    case Married = 'MARRIED';
    case Single = 'SINGLE';
}
