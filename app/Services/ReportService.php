<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChargingSession;
use App\Support\Money;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Row-level reporting over confirmed sessions (docs/02 FR-011, docs/06).
 *
 * Shares AnalyticsFilter with the dashboard, which is what makes AT-008 hold:
 * an export must contain exactly the records the filter selected, and that is
 * only guaranteed if both paths run the same filter code rather than two
 * similar-looking query chains.
 */
class ReportService
{
    /**
     * Column order for the charging report. Shared by every export format so a
     * CSV and an XLSX of the same data agree column for column.
     *
     * @var array<string, string>
     */
    public const CHARGING_COLUMNS = [
        'started_at' => 'Date',
        'vehicle' => 'Vehicle',
        'station' => 'Station',
        'network' => 'Network',
        'charging_type' => 'Type',
        'charging_mode' => 'Mode',
        'energy_kwh' => 'Energy (kWh)',
        'energy_source' => 'Energy source',
        'distance_km' => 'Distance (km)',
        'subtotal' => 'Subtotal',
        'discount_amount' => 'Discount',
        'vat_amount' => 'VAT',
        'total_amount' => 'Total',
        'cost_per_kwh' => 'Cost/kWh',
        'cost_per_km' => 'Cost/km',
    ];

    public function __construct(private readonly CostCalculationService $costs) {}

    /**
     * Session rows for the given filter.
     *
     * Streamed with a lazy cursor: an export of several years of charging must
     * not load every row into memory at once (docs/03 -> performance).
     * Relations are eager loaded on each chunk to avoid an N+1 over stations.
     *
     * @return LazyCollection<int, array<string, string|null>>
     */
    public function chargingRows(AnalyticsFilter $filter): LazyCollection
    {
        $query = $filter->apply(
            ChargingSession::query()
                ->confirmed()
                ->with(['vehicle', 'station.network'])
        )->orderBy('started_at');

        // Generator-backed rather than ->map(): rows are produced one at a
        // time as the export writes them, so memory stays flat regardless of
        // how many years the filter covers.
        return LazyCollection::make(function () use ($query): Generator {
            foreach ($query->lazy(500) as $session) {
                yield $this->chargingRow($session);
            }
        });
    }

    /**
     * One report row, with the derived metrics attached (docs/06).
     *
     * A metric that cannot be computed stays null and renders as an empty
     * cell, never as 0 -- a spreadsheet full of zeroes would be averaged by
     * whoever opens it.
     *
     * @return array<string, string|null>
     */
    public function chargingRow(ChargingSession $session): array
    {
        $metrics = $this->costs->metricsFor($session);
        $tz = (string) config('app.display_timezone');

        return [
            // Rendered in the display timezone: a spreadsheet has no offset
            // information, so a UTC timestamp would read as the wrong local
            // time (docs/10 rule 7).
            'started_at' => $session->started_at->timezone($tz)->format('Y-m-d H:i'),
            'vehicle' => $session->vehicle?->displayName(),
            'station' => $session->station?->name,
            'network' => $session->station?->network?->name,
            'charging_type' => $session->charging_type->value,
            'charging_mode' => $session->charging_mode?->value,
            'energy_kwh' => $session->energy_kwh,
            'energy_source' => $session->energy_source?->value,
            'distance_km' => $session->resolvedDistanceKm(),
            'subtotal' => $session->subtotal,
            'discount_amount' => $session->discount_amount,
            'vat_amount' => $session->vat_amount,
            'total_amount' => $session->total_amount,
            'cost_per_kwh' => $metrics->costPerKwh,
            'cost_per_km' => $metrics->costPerKm,
        ];
    }

    /**
     * Totals row appended to an export, so a reader can check the file against
     * the dashboard without re-adding the column themselves (AT-009).
     *
     * @param  Collection<int, array<string, string|null>>|LazyCollection<int, array<string, string|null>>  $rows
     * @return array<string, string|null>
     */
    public function totalsRow(iterable $rows): array
    {
        $energy = Money::zero();
        $total = Money::zero();
        $count = 0;

        foreach ($rows as $row) {
            $count++;
            $energy = $energy->add(Money::of($row['energy_kwh'] ?? 0));
            $total = $total->add(Money::of($row['total_amount'] ?? 0));
        }

        return [
            'started_at' => "TOTAL ({$count} sessions)",
            'energy_kwh' => $energy->toScale(3),
            'total_amount' => $total->toScale(),
        ];
    }
}
