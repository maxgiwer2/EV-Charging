<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BudgetPeriod;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<int, int|string>|null $alert_thresholds
 * @property BudgetPeriod $period
 */
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Fallback alert levels when a budget does not specify its own
     * (docs/02 FR-013 -> 50/80/100%, configurable).
     */
    public const DEFAULT_THRESHOLDS = [50, 80, 100];

    /** @var list<string> */
    protected $fillable = [
        'amount',
        'period',
        'period_start',
        'period_end',
        'alert_thresholds',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period' => BudgetPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'alert_thresholds' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<int> */
    public function thresholds(): array
    {
        $configured = $this->alert_thresholds;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_THRESHOLDS;
        }

        return array_values(array_map(intval(...), $configured));
    }
}
