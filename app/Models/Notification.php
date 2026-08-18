<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app notification (docs/02 FR-014). This is the project's own table, not
 * Laravel's database notification channel.
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    public const TYPE_OCR_REVIEW = 'OCR_REVIEW';

    public const TYPE_DUPLICATE_RECEIPT = 'DUPLICATE_RECEIPT';

    public const TYPE_ANOMALOUS_EXPENSE = 'ANOMALOUS_EXPENSE';

    public const TYPE_BUDGET_THRESHOLD = 'BUDGET_THRESHOLD';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'context',
        'read_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<$this> $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }
}
