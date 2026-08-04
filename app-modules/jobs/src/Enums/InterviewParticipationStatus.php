<?php

namespace Modules\Jobs\Enums;

enum InterviewParticipationStatus: string
{
    case WAITING = 'Menunggu Wawancara';
    case PASSED = 'Lulus';
    case DOCUMENT_PROCESS = 'Proses Dokumen';
    case READY_FOR_PLACEMENT = 'Siap Dikirim';
    case SENT = 'Terkirim';
    case FAILED = 'Tidak Lolos';
    case WITHDRAWN = 'Mengundurkan Diri';
    case EXPELLED = 'Dikeluarkan';

    public function isActive(): bool
    {
        return in_array($this, [
            self::WAITING,
            self::PASSED,
            self::DOCUMENT_PROCESS,
            self::READY_FOR_PLACEMENT,
        ], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }
}
