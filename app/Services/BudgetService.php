<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\Notification;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Budget tracking and threshold alerts (docs/02 FR-013, FR-014).
 *
 * Spend comes from AnalyticsService, so a budget is measured against exactly
 * the same figure the dashboard shows -- a budget that disagreed with the
 * dashboard would be worse than no budget at all.
 *
 * Thresholds are per budget (defaulting to 50/80/100) rather than constants in
 * code, because docs/02 calls them configurable and docs/10 rule 9 forbids
 * hard-coding business values.
 */
class BudgetService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Measure a budget against actual spend.
     *
     * @return array<string, mixed>
     */
    public function evaluate(Budget $budget): array
    {
        $spent = $this->spendFor($budget);
        $amount = Money::of($budget->amount);

        // A zero budget cannot yield a percentage. Validation forbids one, but
        // the guard stays: dividing here would be the same class of mistake
        // the metrics rules exist to prevent (docs/06).
        $percentage = $amount->isZero()
            ? null
            : $spent->divide($amount->amount)?->multiply(100)->toScale(2);

        $remaining = $amount->subtract($spent);

        return [
            'budget_id' => $budget->id,
            'period' => $budget->period->value,
            'period_start' => $budget->period_start->toDateString(),
            'period_end' => $budget->period_end->toDateString(),
            'amount' => $amount->toScale(),
            'spent' => $spent->toScale(),
            // Negative once the budget is passed, which is the useful reading:
            // "how far over" rather than clamping to zero.
            'remaining' => $remaining->toScale(),
            'percentage_used' => $percentage,
            'thresholds' => $budget->thresholds(),
            'thresholds_reached' => $this->thresholdsReached($budget, $percentage),
            'is_over_budget' => $remaining->isNegative(),
        ];
    }

    /**
     * Evaluate every active budget for a user.
     *
     * @return list<array<string, mixed>>
     */
    public function evaluateAll(User $user, ?Carbon $on = null): array
    {
        $on ??= Carbon::now((string) config('app.display_timezone'));

        return $user->budgets()
            ->whereDate('period_start', '<=', $on)
            ->whereDate('period_end', '>=', $on)
            ->orderBy('period_start')
            ->get()
            ->map(fn (Budget $budget): array => $this->evaluate($budget))
            ->all();
    }

    /**
     * Evaluate and raise an alert for each newly crossed threshold
     * (FR-014 -> budget threshold notification).
     *
     * One notification per threshold per budget, ever. Without that check a
     * user sitting just above 80% would be told again on every evaluation,
     * and alerts people learn to ignore are worse than none.
     *
     * @return list<array<string, mixed>>
     */
    public function evaluateAndNotify(User $user, ?Carbon $on = null): array
    {
        $evaluations = $this->evaluateAll($user, $on);

        foreach ($evaluations as $evaluation) {
            foreach ($evaluation['thresholds_reached'] as $threshold) {
                if ($this->alreadyNotified($user, $evaluation['budget_id'], $threshold)) {
                    continue;
                }

                Notification::create([
                    'user_id' => $user->id,
                    'type' => Notification::TYPE_BUDGET_THRESHOLD,
                    'title' => $threshold >= 100
                        ? 'Charging budget exceeded'
                        : "Charging budget {$threshold}% used",
                    'body' => sprintf(
                        'Spent %s of %s for %s to %s.',
                        $evaluation['spent'],
                        $evaluation['amount'],
                        $evaluation['period_start'],
                        $evaluation['period_end'],
                    ),
                    'context' => [
                        'budget_id' => $evaluation['budget_id'],
                        'threshold' => $threshold,
                        'spent' => $evaluation['spent'],
                        'amount' => $evaluation['amount'],
                        'percentage_used' => $evaluation['percentage_used'],
                    ],
                ]);
            }
        }

        return $evaluations;
    }

    /**
     * Confirmed spend inside the budget's window.
     *
     * Only CONFIRMED sessions count, so a budget reconciles with the dashboard
     * and a draft entry cannot trigger an alert (AT-009).
     */
    private function spendFor(Budget $budget): Money
    {
        $tz = (string) config('app.display_timezone');

        // period_start/period_end are plain dates: they mean those days in the
        // user's local calendar, not UTC instants. So the date string is
        // re-parsed *in* the display timezone rather than converting a
        // UTC-midnight Carbon -- doing the latter shifts the window by the
        // offset and silently drops the last hours of the final day, which is
        // precisely when a budget is being watched (docs/10 rule 7).
        $from = Carbon::parse($budget->period_start->toDateString(), $tz)->startOfDay();

        // Exclusive upper bound at local midnight after the final day, so a
        // charge on the last evening is counted exactly once.
        $to = Carbon::parse($budget->period_end->toDateString(), $tz)->addDay()->startOfDay();

        $filter = new AnalyticsFilter(
            userId: $budget->user_id,
            from: $from->utc(),
            to: $to->utc(),
        );

        return Money::of($this->analytics->totals($filter)['total_cost']);
    }

    /**
     * Which configured thresholds the current spend has passed.
     *
     * @return list<int>
     */
    private function thresholdsReached(Budget $budget, ?string $percentage): array
    {
        if ($percentage === null) {
            return [];
        }

        $reached = [];

        foreach ($budget->thresholds() as $threshold) {
            if (bccomp($percentage, (string) $threshold, 2) >= 0) {
                $reached[] = $threshold;
            }
        }

        sort($reached);

        return $reached;
    }

    private function alreadyNotified(User $user, int $budgetId, int $threshold): bool
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('type', Notification::TYPE_BUDGET_THRESHOLD)
            ->whereJsonContains('context->budget_id', $budgetId)
            ->whereJsonContains('context->threshold', $threshold)
            ->exists();
    }
}
