<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TimeBand;
use App\Models\ChargingTariff;
use App\Models\TariffVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TariffVersion>
 */
class TariffVersionFactory extends Factory
{
    protected $model = TariffVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charging_tariff_id' => ChargingTariff::factory(),
            'energy_rate' => fake()->randomFloat(4, 3, 9),
            'service_fee' => 0,
            'parking_fee' => 0,
            'vat_rate' => 7.000,
            'time_band' => TimeBand::NORMAL,
            'power_min_kw' => null,
            'power_max_kw' => null,
            // Open-ended and already in effect, so a session created "now"
            // resolves to it without further setup.
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ];
    }

    /** A closed historical version, for reproducibility tests (AT-006). */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'effective_from' => now()->subYears(2),
            'effective_to' => now()->subYear(),
        ]);
    }

    public function peak(): static
    {
        return $this->state(fn (): array => ['time_band' => TimeBand::PEAK]);
    }

    public function offPeak(): static
    {
        return $this->state(fn (): array => ['time_band' => TimeBand::OFF_PEAK]);
    }
}
