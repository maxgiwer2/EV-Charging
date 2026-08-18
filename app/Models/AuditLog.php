<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit record (docs/02 FR-015, AT-010).
 *
 * Written once by AuditLogService and never modified. There is no updated_at
 * because an audit row that can change is not evidence.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_DELETE = 'DELETE';

    public const ACTION_RESTORE = 'RESTORE';

    public const ACTION_VERIFY = 'VERIFY';

    public const ACTION_REJECT = 'REJECT';

    public const ACTION_LOGIN = 'LOGIN';

    public const ACTION_LOGOUT = 'LOGOUT';

    public const ACTION_EXPORT = 'EXPORT';

    /**
     * Only created_at is maintained; see the migration.
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'before_data',
        'after_data',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<$this> $query */
    public function scopeForEntity(Builder $query, string $type, int $id): void
    {
        $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
