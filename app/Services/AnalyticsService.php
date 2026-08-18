<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Models\ChargingSession;
use App\Support\Money;
use App\Support\SessionMetrics;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

/**
 * Dashboard and report aggregates (docs/02 FR-010/FR-011, docs/06).
 *
 * Two invariants hold across everything here:
 *
 *  1. **Only CONFIRMED sessions count** (AT-009). Every query starts from
 *     baseQuery(), which applies the scope, so a draft or cancelled entry can
 *     never inflate a total. Reconciliation is the acceptance criterion, so the
 *     filter cannot be optional.
 *
 *  2. **Aggregates come from SQL, metrics from Money.** SUM/COUNT run in MySQL
 *     over DECIMAL columns, which is exact and avoids loading rows; the ratios
 *     are then derived with bcmath. Computing an average as AVG(cost/energy)
 *     in SQL would both divide by zero and average ratios rather than ratio
 *     the totals.
 */
class AnalyticsService
{
    /**
     * Headline KPIs for a period (docs/06 -> KPIs).
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsFilter $filter): array
    {
        $totals = $this->totals($filter);

        $metrics = SessionMetrics::calculate(
            $totals['total_cost'],
            $totals['total_kwh'],
            $totals['total_distance_km'],
        );

        return [
            ...$totals,
            ...$metrics->toArray(),
            'home_public_ratio' => $this->homePublicSplit($filter),
            'ac_dc_ratio' => $this->acDcSplit($filter),
        ];
    }

    /**
     * Raw sums for a period.
     *
     * distance is summed only over sessions that recorded one, so a month with
     * partial odometer data still yields a usable cost/km rather than a figure
     * divided by an understated distance... and when no session recorded a
     * distance at all the sum is null, which propagates to a null metric
     * rather than a zero (docs/06).
     *
     * @return array<string, string|int|null>
     */
    public function totals(AnalyticsFilter $filter): array
    {
        // toBase() keeps every applied scope but returns the query builder, so
        // the selectRaw aliases come back as a plain object rather than being
        // mistaken for model attributes.
        $row = $this->baseQuery($filter)->toBase()
            ->selectRaw('COUNT(*) AS session_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total_cost')
            ->selectRaw('SUM(energy_kwh) AS total_kwh')
            ->selectRaw('SUM(distance_km) AS total_distance_km')
            ->first();

        return [
            'session_count' => (int) ($row->session_count ?? 0),
            // Cost defaults to zero: no sessions means nothing was spent, which
            // is a known figure rather than an unknown one.
            'total_cost' => Money::of($row->total_cost ?? 0)->toScale(),
            // Energy and distance stay null when nothing recorded them, so the
            // derived metrics correctly refuse to compute.
            'total_kwh' => $row->total_kwh === null ? null : (string) $row->total_kwh,
            'total_distance_km' => $row->total_distance_km === null ? null : (string) $row->total_distance_km,
        ];
    }

    /**
     * A time series for charts (docs/02 FR-010 -> charts by date/month).
     *
     * @return list<array<string, mixed>>
     */
    public function trends(AnalyticsFilter $filter, string $granularity = 'month'): array
    {
        $bucket = $this->bucketExpression($granularity);

        $rows = $this->baseQuery($filter)->toBase()
            ->selectRaw("{$bucket} AS bucket")
            ->selectRaw('COUNT(*) AS session_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total_cost')
            ->selectRaw('SUM(energy_kwh) AS total_kwh')
            ->selectRaw('SUM(distance_km) AS total_distance_km')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(function (stdClass $row): array {
            $metrics = SessionMetrics::calculate(
                (string) $row->total_cost,
                $row->total_kwh === null ? null : (string) $row->total_kwh,
                $row->total_distance_km === null ? null : (string) $row->total_distance_km,
            );

            return [
                'bucket' => (string) $row->bucket,
                'session_count' => (int) $row->session_count,
                'total_cost' => Money::of($row->total_cost)->toScale(),
                'total_kwh' => $row->total_kwh === null ? null : (string) $row->total_kwh,
                'cost_per_kwh' => $metrics->costPerKwh,
            ];
        })->values()->all();
    }

    /**
     * Spend grouped by a dimension (docs/06 -> Dimensions).
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(AnalyticsFilter $filter, string $dimension): array
    {
        ['select' => $select, 'group' => $group, 'joins' => $joins] = $this->dimensionExpression($dimension);

        $query = $this->baseQuery($filter)->toBase();

        foreach ($joins as $join) {
            // LEFT so a session without the dimension (a home charge has no
            // station) is still counted, under "Unspecified".
            $query->leftJoin(...$join);
        }

        $rows = $query
            ->selectRaw("{$select} AS label")
            ->selectRaw('COUNT(*) AS session_count')
            ->selectRaw('COALESCE(SUM(charging_sessions.total_amount), 0) AS total_cost')
            ->selectRaw('SUM(charging_sessions.energy_kwh) AS total_kwh')
            ->groupByRaw($group)
            ->orderByDesc('total_cost')
            ->get();

        return $rows->map(fn (stdClass $row): array => [
            // A null label means the dimension was not recorded (a home charge
            // has no station); shown as such rather than dropped, so the parts
            // still sum to the whole.
            'label' => $row->label === null ? 'Unspecified' : (string) $row->label,
            'session_count' => (int) $row->session_count,
            'total_cost' => Money::of($row->total_cost)->toScale(),
            'total_kwh' => $row->total_kwh === null ? null : (string) $row->total_kwh,
        ])->values()->all();
    }

    /**
     * Current period against the one before it (docs/06 -> Comparisons).
     *
     * @return array<string, mixed>
     */
    public function comparison(AnalyticsFilter $current, AnalyticsFilter $previous): array
    {
        $now = $this->totals($current);
        $then = $this->totals($previous);

        return [
            'current' => $now,
            'previous' => $then,
            'change' => [
                'total_cost' => Money::of($now['total_cost'])->subtract(Money::of($then['total_cost']))->toScale(),
                'session_count' => $now['session_count'] - $then['session_count'],
                // Percentage is undefined against a zero base -- reporting
                // "+100%" for a first month of spending would be meaningless.
                'total_cost_pct' => $this->percentageChange($then['total_cost'], $now['total_cost']),
            ],
        ];
    }

    /**
     * Home versus public spend (docs/06 -> home/public ratio).
     *
     * @return array<string, string>
     */
    private function homePublicSplit(AnalyticsFilter $filter): array
    {
        $home = $this->baseQuery($filter)
            ->where('charging_type', ChargingType::HOME)
            ->sum('total_amount');

        $total = $this->baseQuery($filter)->sum('total_amount');

        return [
            'home' => Money::of($home)->toScale(),
            'public' => Money::of($total)->subtract(Money::of($home))->toScale(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function acDcSplit(AnalyticsFilter $filter): array
    {
        $ac = $this->baseQuery($filter)->where('charging_mode', ChargingMode::AC)->sum('energy_kwh');
        $dc = $this->baseQuery($filter)->where('charging_mode', ChargingMode::DC)->sum('energy_kwh');

        return [
            'ac_kwh' => (string) $ac,
            'dc_kwh' => (string) $dc,
        ];
    }

    /**
     * Only confirmed, non-deleted sessions the caller may see (AT-009, AT-007).
     *
     * @return Builder<ChargingSession>
     */
    private function baseQuery(AnalyticsFilter $filter): Builder
    {
        return $filter->apply(ChargingSession::query()->confirmed());
    }

    private function bucketExpression(string $granularity): string
    {
        // Whitelisted: the value reaches a raw expression, so it must never be
        // taken from input unchecked.
        return match ($granularity) {
            'day' => "DATE_FORMAT(started_at, '%Y-%m-%d')",
            'year' => "DATE_FORMAT(started_at, '%Y')",
            default => "DATE_FORMAT(started_at, '%Y-%m')",
        };
    }

    /**
     * Column, GROUP BY expression and the joins each dimension needs.
     *
     * The expressions are chosen from a fixed set here rather than built from
     * input, because they reach a raw SQL fragment.
     *
     * @return array{select: string, group: string, joins: list<array<int, string>>}
     */
    private function dimensionExpression(string $dimension): array
    {
        return match ($dimension) {
            'station' => [
                'select' => 'charging_stations.name',
                'group' => 'charging_stations.id, charging_stations.name',
                'joins' => [['charging_stations', 'charging_stations.id', '=', 'charging_sessions.station_id']],
            ],
            // Two hops: a session references a station, which belongs to a
            // network. Sessions with no station (home charging) survive the
            // LEFT JOINs and group under "Unspecified".
            'network' => [
                'select' => 'charging_networks.name',
                'group' => 'charging_networks.id, charging_networks.name',
                'joins' => [
                    ['charging_stations', 'charging_stations.id', '=', 'charging_sessions.station_id'],
                    ['charging_networks', 'charging_networks.id', '=', 'charging_stations.network_id'],
                ],
            ],
            'vehicle' => [
                'select' => "CONCAT(vehicles.make, ' ', vehicles.model)",
                'group' => 'vehicles.id, vehicles.make, vehicles.model',
                'joins' => [['vehicles', 'vehicles.id', '=', 'charging_sessions.vehicle_id']],
            ],
            'charging_mode' => [
                'select' => 'charging_sessions.charging_mode',
                'group' => 'charging_sessions.charging_mode',
                'joins' => [],
            ],
            default => [
                'select' => 'charging_sessions.charging_type',
                'group' => 'charging_sessions.charging_type',
                'joins' => [],
            ],
        };
    }

    private function percentageChange(string $from, string $to): ?string
    {
        $base = Money::of($from);

        if ($base->isZero()) {
            return null;
        }

        return Money::of($to)->subtract($base)->divide($base->amount)?->multiply(100)->toScale(2);
    }
}
