<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TimeBand;
use App\Exceptions\ImmutableTariffVersion;
use App\Exceptions\TariffPeriodOverlap;
use App\Models\ChargingSession;
use App\Models\ChargingTariff;
use App\Models\TariffVersion;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Versioned tariffs (docs/02 FR-007, docs/04 Admin Tariff, AT-006).
 *
 * Two rules are enforced here and nowhere else:
 *
 *  1. **A version referenced by a session is frozen.** AT-006 requires a
 *     historical session to keep resolving to the rates that applied when it
 *     happened. Editing a version in place would silently rewrite the past;
 *     a rate change publishes a new version instead.
 *
 *  2. **Effective periods must not overlap.** MySQL cannot express a
 *     non-overlap constraint over a date range, so the check lives in the
 *     service. Two versions covering the same instant would make tariff
 *     resolution ambiguous, and the winner would depend on row order.
 */
class TariffService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * Publish a new version of a tariff (docs/04 -> create new version, set
     * effective period, validate overlap, publish).
     *
     * The previous open-ended version is closed at the new one's start rather
     * than left running, so the timeline stays continuous with no gap and no
     * overlap.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws TariffPeriodOverlap
     */
    public function publishVersion(ChargingTariff $tariff, array $attributes, User $actor): TariffVersion
    {
        $from = $this->toDate($attributes['effective_from']);
        /** @var mixed $rawTo */
        $rawTo = $attributes['effective_to'] ?? null;
        $to = $rawTo === null || $rawTo === '' ? null : $this->toDate($rawTo);

        if ($to !== null && $to->lte($from)) {
            throw new TariffPeriodOverlap('A tariff version must end after it starts.');
        }

        return DB::transaction(function () use ($tariff, $attributes, $from, $to, $actor): TariffVersion {
            // Locked for the duration: two admins publishing at once could
            // otherwise both pass the overlap check and both insert.
            $existing = $tariff->versions()
                ->lockForUpdate()
                ->orderBy('effective_from')
                ->get();

            $this->assertNoOverlap($existing, $from, $to, $attributes['time_band'] ?? TimeBand::NORMAL);

            $this->closePrecedingVersion($existing, $from, $attributes['time_band'] ?? TimeBand::NORMAL, $actor);

            $version = $tariff->versions()->create([
                'energy_rate' => $attributes['energy_rate'] ?? 0,
                'service_fee' => $attributes['service_fee'] ?? 0,
                'parking_fee' => $attributes['parking_fee'] ?? 0,
                'vat_rate' => $attributes['vat_rate'] ?? null,
                'time_band' => $attributes['time_band'] ?? TimeBand::NORMAL,
                'power_min_kw' => $attributes['power_min_kw'] ?? null,
                'power_max_kw' => $attributes['power_max_kw'] ?? null,
                'effective_from' => $from,
                'effective_to' => $to,
            ]);

            $this->audit->logCreate($version, $actor->id);

            return $version;
        });
    }

    /**
     * Amend a version that no session has used yet.
     *
     * Once a session references it the version is evidence, not configuration
     * (AT-006), so the only correction available is publishing a replacement.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ImmutableTariffVersion
     */
    public function amendVersion(TariffVersion $version, array $attributes, User $actor): TariffVersion
    {
        if ($version->isReferencedBySession()) {
            throw new ImmutableTariffVersion($version->id);
        }

        return DB::transaction(function () use ($version, $attributes, $actor): TariffVersion {
            $before = $version->getOriginal();
            $version->fill($attributes);
            $version->save();

            $this->audit->logUpdate($version, $before, $actor->id);

            return $version->refresh();
        });
    }

    /**
     * The version that applies to a charge.
     *
     * Resolution narrows by scope, then by the qualifiers a real tariff uses:
     * the power band the session charged at, and the time band the clock fell
     * in. When several remain, the most recently effective wins -- that is the
     * one an operator most recently published for the situation.
     *
     * Returns null rather than a fallback: an unpriced session is a fact the
     * system should surface, not paper over with someone else's rate.
     */
    public function resolveForSession(ChargingSession $session): ?TariffVersion
    {
        $moment = $session->started_at;

        $candidates = TariffVersion::query()
            ->with('tariff')
            ->whereHas('tariff', function ($query) use ($session): void {
                $query->where('is_active', true)
                    ->where('charging_type', $session->charging_type)
                    ->where(function ($scope) use ($session): void {
                        // Station-specific beats network-wide beats global.
                        $scope->where('station_id', $session->station_id)
                            ->orWhereNull('station_id');
                    });
            })
            ->effectiveAt($moment)
            ->get()
            ->filter(fn (TariffVersion $v): bool => $this->matchesPower($v, $session))
            ->filter(fn (TariffVersion $v): bool => $this->matchesTimeBand($v, $moment));

        if ($candidates->isEmpty()) {
            return null;
        }

        // Most specific scope first: a station rate overrides a network rate.
        return $candidates
            ->sortByDesc(fn (TariffVersion $v): int => $v->tariff->station_id !== null ? 1 : 0)
            ->sortByDesc(fn (TariffVersion $v): string => (string) $v->effective_from)
            ->first();
    }

    /**
     * Price a session from a tariff version (FR-007, FR-008).
     *
     * VAT is applied only when the version states a rate: a null vat_rate
     * means "not specified by this tariff", which is different from 0% and
     * must not silently add tax that was never charged (docs/10 rule 9).
     *
     * @return array<string, string|null>
     */
    public function priceSession(TariffVersion $version, string $energyKwh): array
    {
        $energy = Money::of($energyKwh);
        $energyCost = $energy->multiply($version->energy_rate);

        $subtotal = $energyCost
            ->add(Money::of($version->service_fee))
            ->add(Money::of($version->parking_fee));

        $vat = $version->vat_rate === null
            ? null
            // divide(100) cannot return null: it is only null for a zero
            // divisor.
            : $subtotal->multiply((string) Money::of($version->vat_rate)->divide(100)?->amount);

        return [
            'unit_price' => (string) $version->energy_rate,
            'subtotal' => $subtotal->toScale(),
            'service_fee' => Money::of($version->service_fee)->toScale(),
            'parking_fee' => Money::of($version->parking_fee)->toScale(),
            'vat' => $vat?->toScale(),
            'total' => $subtotal->add($vat ?? Money::zero())->toScale(),
        ];
    }

    /**
     * @param  Collection<int, TariffVersion>  $existing
     *
     * @throws TariffPeriodOverlap
     */
    private function assertNoOverlap(
        Collection $existing,
        Carbon $from,
        ?Carbon $to,
        TimeBand|string $timeBand,
    ): void {
        $band = $timeBand instanceof TimeBand ? $timeBand : TimeBand::from($timeBand);

        foreach ($existing as $version) {
            // Different bands legitimately coexist: a peak and an off-peak
            // rate cover the same dates but different hours.
            if ($version->time_band !== $band) {
                continue;
            }

            $versionEnd = $version->effective_to;

            // An open-ended existing version is closed by
            // closePrecedingVersion() rather than treated as a clash.
            if ($versionEnd === null) {
                continue;
            }

            $startsBeforeExistingEnds = $to === null || $from->lt($versionEnd);
            $endsAfterExistingStarts = $to === null
                ? $versionEnd->gt($from)
                : $to->gt($version->effective_from);

            if ($startsBeforeExistingEnds && $endsAfterExistingStarts && $from->lt($versionEnd)) {
                throw new TariffPeriodOverlap(sprintf(
                    'This period overlaps version %d (%s to %s).',
                    $version->id,
                    $version->effective_from->toDateString(),
                    $versionEnd->toDateString(),
                ));
            }
        }
    }

    /**
     * Close the open-ended version of the same band at the new start, so the
     * timeline has no instant covered twice.
     *
     * @param  Collection<int, TariffVersion>  $existing
     */
    private function closePrecedingVersion(
        Collection $existing,
        Carbon $from,
        TimeBand|string $timeBand,
        User $actor,
    ): void {
        $band = $timeBand instanceof TimeBand ? $timeBand : TimeBand::from($timeBand);

        foreach ($existing as $version) {
            if ($version->time_band !== $band || $version->effective_to !== null) {
                continue;
            }

            if ($version->effective_from->gte($from)) {
                throw new TariffPeriodOverlap(
                    'A later version already starts at or after this date; close it first.'
                );
            }

            // Closing a version is not editing its rates, so it is permitted
            // even when sessions reference it: the prices it charged are
            // unchanged, only the window it applies to going forward.
            $before = $version->getOriginal();
            $version->effective_to = $from;
            $version->save();

            $this->audit->logUpdate($version, $before, $actor->id);
        }
    }

    private function matchesPower(TariffVersion $version, ChargingSession $session): bool
    {
        $power = $session->power_kw;

        // A version with no band applies at any power. A banded version cannot
        // be matched against an unknown power, so it is skipped rather than
        // assumed to apply.
        if ($version->power_min_kw === null && $version->power_max_kw === null) {
            return true;
        }

        if ($power === null) {
            return false;
        }

        if ($version->power_min_kw !== null && bccomp((string) $power, (string) $version->power_min_kw, 2) === -1) {
            return false;
        }

        // Upper bound exclusive, so bands can be written as 0-50 and 50-150
        // without 50 belonging to both.
        return ! ($version->power_max_kw !== null
            && bccomp((string) $power, (string) $version->power_max_kw, 2) >= 0);
    }

    /**
     * Whether a version's time band covers the moment of the charge.
     *
     * Peak windows are themselves tariff data and are never hard-coded
     * (docs/10 rule 9), so until per-tariff windows are configurable a NORMAL
     * version matches any time and a banded one is only chosen when a caller
     * has already established the band.
     */
    private function matchesTimeBand(TariffVersion $version, Carbon $moment): bool
    {
        return $version->time_band === TimeBand::NORMAL
            || $version->time_band === TimeBand::OTHER
            || $this->bandFor($moment) === $version->time_band;
    }

    /**
     * Time band of a moment, from configuration rather than constants in code.
     */
    private function bandFor(Carbon $moment): TimeBand
    {
        $local = $moment->copy()->timezone((string) config('app.display_timezone'));

        /** @var array<string, array{days: list<int>, start: string, end: string}> $windows */
        $windows = config('tariffs.peak_windows', []);

        foreach ($windows as $window) {
            if (! in_array($local->dayOfWeekIso, $window['days'], true)) {
                continue;
            }

            $time = $local->format('H:i');

            if ($time >= $window['start'] && $time < $window['end']) {
                return TimeBand::PEAK;
            }
        }

        return TimeBand::OFF_PEAK;
    }

    private function toDate(mixed $value): Carbon
    {
        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }
}
