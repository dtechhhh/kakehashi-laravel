<?php

namespace Shared\Approval;

use Illuminate\Database\Eloquent\Model;

/**
 * Entitas Maker-Checker (PRD §7.4). Keputusan hanya lewat PendingRequestService
 * agar revalidasi in-transaction dan guard BR-APV tidak bisa dilewati.
 *
 * @property PendingType $type
 * @property PendingStatus $status
 */
class PendingRequest extends Model
{
    protected $table = 'pending_request';

    /** Writes are reserved for PendingRequestService. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => PendingType::class,
            'status' => PendingStatus::class,
            'payload' => 'array',
            'target_id' => 'integer',
            'requested_by' => 'integer',
            'checker_id' => 'integer',
            'created_at' => 'datetime',
            'decided_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
