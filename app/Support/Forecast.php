<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A spend projection for the current period (docs/02 FR-018).
 *
 * Every field is nullable and `available` says whether a projection could be
 * made at all. Refusing to answer is a real outcome here: projecting a month
 * from two days of data produces a confident-looking number with nothing
 * behind it, and a user would reasonably act on it.
 */
final readonly class Forecast
{
    /**
     * @param  string|null  $projectedTotal  projected spend for the whole period
     * @param  string|null  $spentToDate  actual spend so far
     * @param  string|null  $dailyRate  average spend per elapsed day
     * @param  string|null  $previousPeriodTotal  the comparable prior period
     * @param  list<string>  $caveats  why the projection may be unreliable
     */
    public function __construct(
        public bool $available,
        public ?string $projectedTotal = null,
        public ?string $spentToDate = null,
        public ?string $dailyRate = null,
        public ?string $previousPeriodTotal = null,
        public int $elapsedDays = 0,
        public int $totalDays = 0,
        public array $caveats = [],
        public ?string $unavailableReason = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(available: false, unavailableReason: $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'projected_total' => $this->projectedTotal,
            'spent_to_date' => $this->spentToDate,
            'daily_rate' => $this->dailyRate,
            'previous_period_total' => $this->previousPeriodTotal,
            'elapsed_days' => $this->elapsedDays,
            'total_days' => $this->totalDays,
            // Stated rather than buried: a projection with caveats is not the
            // same as one without.
            'caveats' => $this->caveats,
            'unavailable_reason' => $this->unavailableReason,
        ];
    }
}
