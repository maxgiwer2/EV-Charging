<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Forecast;
use App\Support\Money;
use App\Support\Statistics;
use Illuminate\Support\Carbon;

/**
 * Projects spend for the current period (docs/02 FR-018).
 *
 * Deliberately a run-rate projection rather than anything cleverer. Personal
 * charging data is a handful of points per month with no seasonality worth
 * modelling, so a regression would produce a more precise-looking number
 * without being a more accurate one -- and precision that is not accuracy is
 * exactly what misleads someone budgeting.
 *
 * The service refuses to answer more often than it answers, which is the
 * point: a month projected from two days is a confident-looking figure with
 * nothing behind it, and people act on those.
 */
class ForecastService
{
    /**
     * Days that must have elapsed before projecting. Below this the daily rate
     * is dominated by whether the user happened to charge yesterday.
     */
    private const MINIMUM_ELAPSED_DAYS = 5;

    /** Sessions needed in the period for the rate to mean anything. */
    private const MINIMUM_SESSIONS = 3;

    /** Prior months considered when comparing against past behaviour. */
    private const HISTORY_MONTHS = 6;

    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Project this calendar month's spend.
     *
     * The month is the user's local one (docs/10 rule 7): "this month" means
     * their month, and a UTC boundary would move late-evening charges.
     */
    public function projectCurrentMonth(User $user): Forecast
    {
        $tz = (string) config('app.display_timezone');
        $now = Carbon::now($tz);
        $start = $now->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        $totalDays = (int) $start->diffInDays($end);
        // Counted as completed days plus the one in progress, so a projection
        // on the 1st divides by 1 rather than 0.
        $elapsedDays = (int) $start->diffInDays($now) + 1;

        $filter = new AnalyticsFilter(
            userId: $user->isAdmin() ? null : $user->id,
            from: $start->copy()->utc(),
            to: $end->copy()->utc(),
        );

        $totals = $this->analytics->totals($filter);
        $spent = Money::of($totals['total_cost']);
        $previous = $this->previousMonthTotal($user, $start);

        if ($elapsedDays < self::MINIMUM_ELAPSED_DAYS) {
            return Forecast::unavailable('too_early_in_period');
        }

        if ($totals['session_count'] < self::MINIMUM_SESSIONS) {
            return Forecast::unavailable('not_enough_sessions');
        }

        $dailyRate = $spent->divide((string) $elapsedDays);

        if ($dailyRate === null) {
            return Forecast::unavailable('no_spend_recorded');
        }

        return new Forecast(
            available: true,
            projectedTotal: $dailyRate->multiply((string) $totalDays)->toScale(),
            spentToDate: $spent->toScale(),
            dailyRate: $dailyRate->toScale(),
            previousPeriodTotal: $previous?->toScale(),
            elapsedDays: $elapsedDays,
            totalDays: $totalDays,
            caveats: $this->caveats($elapsedDays, $totalDays, $totals['session_count'], $previous, $spent),
        );
    }

    /**
     * What would make this projection unreliable, stated rather than buried.
     *
     * A user comparing a projection with their budget deserves to know it
     * rests on four days of data, not to discover it later.
     *
     * @return list<string>
     */
    private function caveats(
        int $elapsedDays,
        int $totalDays,
        int $sessionCount,
        ?Money $previous,
        Money $spent,
    ): array {
        $caveats = [];

        // Under a third of the month, a single large charge dominates.
        if ($elapsedDays * 3 < $totalDays) {
            $caveats[] = 'early_in_period';
        }

        if ($sessionCount < 6) {
            $caveats[] = 'few_sessions';
        }

        // No prior month means nothing to sanity-check the rate against.
        if ($previous === null || $previous->isZero()) {
            $caveats[] = 'no_previous_period';
        } elseif ($spent->isZero() === false) {
            // A run rate already far past last month is more likely to reflect
            // an unusual month than a sustained change.
            $ratio = $spent->divide($previous->amount);

            if ($ratio !== null && bccomp($ratio->amount, '1.5', 4) === 1) {
                $caveats[] = 'well_above_previous_period';
            }
        }

        return $caveats;
    }

    /**
     * Spend in the calendar month before $start.
     */
    private function previousMonthTotal(User $user, Carbon $start): ?Money
    {
        $filter = new AnalyticsFilter(
            userId: $user->isAdmin() ? null : $user->id,
            from: $start->copy()->subMonth()->utc(),
            to: $start->copy()->utc(),
        );

        $totals = $this->analytics->totals($filter);

        return $totals['session_count'] === 0 ? null : Money::of($totals['total_cost']);
    }

    /**
     * Typical monthly spend over recent history, for context alongside a
     * projection.
     *
     * The median, not the mean: one month with a road trip in it should not
     * redefine "typical".
     */
    public function typicalMonthlySpend(User $user): ?string
    {
        $tz = (string) config('app.display_timezone');
        $start = Carbon::now($tz)->startOfMonth();
        $totals = [];

        for ($i = 1; $i <= self::HISTORY_MONTHS; $i++) {
            $from = $start->copy()->subMonths($i);
            $filter = new AnalyticsFilter(
                userId: $user->isAdmin() ? null : $user->id,
                from: $from->copy()->utc(),
                to: $from->copy()->addMonth()->utc(),
            );

            $month = $this->analytics->totals($filter);

            // Months with no charging are skipped rather than counted as zero:
            // a month the user was away should not drag the typical figure
            // down as though they charged nothing while driving normally.
            if ($month['session_count'] > 0) {
                $totals[] = Money::of($month['total_cost'])->toScale();
            }
        }

        if (count($totals) < 2) {
            return null;
        }

        $median = Statistics::median($totals);

        return $median === null ? null : Money::of($median)->toScale();
    }
}
