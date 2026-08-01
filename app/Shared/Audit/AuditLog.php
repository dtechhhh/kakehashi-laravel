<?php

namespace Shared\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only audit row. Mutations blocked at model + DB trigger.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_id',
        'actor_role_snapshot',
        'action_type',
        'entity_type',
        'entity_id',
        'detail',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
            'action_type' => ActionType::class,
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('audit_log is immutable');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('audit_log is immutable');
        });
    }
}
