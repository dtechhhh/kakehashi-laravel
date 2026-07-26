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

    /**
     * Kolom keputusan (`checker_id`, `note_checker`, `decided_at`) sengaja TIDAK
     * fillable: satu-satunya penulisnya adalah PendingRequestService::decide(),
     * yang memakai Builder::update() (melewati mass-assignment guard) setelah
     * revalidasi BR-APV-07 dan guard BR-APV-01. Tanpa ini, domain bisa
     * $request->update(['status' => 'approved', 'checker_id' => $makerId])
     * dan melewati kedua guard.
     *
     * `status` tetap fillable — submit() menulisnya eksplisit di create().
     */
    protected $fillable = [
        'type',
        'target_type',
        'target_id',
        'requested_by',
        'reason_maker',
        'payload',
        'status',
    ];

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
