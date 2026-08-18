<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The derived metrics from docs/06, as a value object.
 *
 * Every field is nullable on purpose. docs/06 states plainly: "Do not calculate
 * metrics when denominator is zero/null." A null here means *not knowable from
 * this data*, which is a different fact from zero.
 *
 * That distinction is the whole point. A `cost_per_km` of 0 on a session with
 * no odometer reading reads as free driving, and once it is averaged into a
 * monthly figure the error is invisible and unrecoverable. Null propagates
 * instead, and the aggregate simply excludes the session.
 */
final readonly class SessionMetrics
{
    private function __construct(
        /** Amount of money per kWh delivered. */
        public ?string $costPerKwh,
        /** Amount of money per kilometre driven. */
        public ?string $costPerKm,
        /** Energy consumed per 100 km. */
        public ?string $kwhPer100Km,
        /** Distance obtained per kWh. */
        public ?string $kmPerKwh,
        /** Amount of money per 100 km. */
        public ?string $costPer100Km,
    ) {}

    /**
     * Compute from a total cost, an energy figure and a distance.
     *
     * Any of the three may be null (not recorded). Each metric is produced only
     * when both of its operands are present and its denominator is non-zero.
     *
     * @param  string|null  $totalCost  money, decimal string
     * @param  string|null  $energyKwh  kWh, decimal string
     * @param  string|null  $distanceKm  km, decimal string
     */
    public static function calculate(
        ?string $totalCost,
        ?string $energyKwh,
        ?string $distanceKm,
    ): self {
        $cost = Money::ofNullable($totalCost);
        $energy = Money::ofNullable($energyKwh);
        $distance = Money::ofNullable($distanceKm);

        // A negative distance is a data error (odometer entered backwards), not
        // a meaningful denominator; treat it as unknown rather than emitting a
        // negative efficiency.
        if ($distance !== null && $distance->isNegative()) {
            $distance = null;
        }

        return new self(
            costPerKwh: self::ratio($cost, $energy, 4),
            costPerKm: self::ratio($cost, $distance, 4),
            kwhPer100Km: self::per100($energy, $distance),
            kmPerKwh: self::ratio($distance, $energy, 4),
            costPer100Km: self::per100($cost, $distance),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'cost_per_kwh' => $this->costPerKwh,
            'cost_per_km' => $this->costPerKm,
            'kwh_per_100km' => $this->kwhPer100Km,
            'km_per_kwh' => $this->kmPerKwh,
            'cost_per_100km' => $this->costPer100Km,
        ];
    }

    /**
     * $numerator / $denominator, or null when either is absent or the
     * denominator is zero.
     */
    private static function ratio(?Money $numerator, ?Money $denominator, int $scale): ?string
    {
        if ($numerator === null || $denominator === null) {
            return null;
        }

        // Money::divide() already returns null for a zero divisor.
        return $numerator->divide($denominator->amount)?->toScale($scale);
    }

    /**
     * $value / $distance * 100, or null when it cannot be computed.
     *
     * Multiplication happens before rounding so the scaling does not amplify a
     * rounding error.
     */
    private static function per100(?Money $value, ?Money $distance): ?string
    {
        if ($value === null || $distance === null) {
            return null;
        }

        return $value->divide($distance->amount)?->multiply(100)->toScale(4);
    }
}
