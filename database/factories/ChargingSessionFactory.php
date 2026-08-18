<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingSession>
 */
class ChargingSessionFactory extends Factory
{
    protected $model = ChargingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-6 months', 'now');
        $energyKwh = fake()->randomFloat(3, 5, 60);
        $unitPrice = fake()->randomFloat(2, 4, 9);

        // Totals are computed here rather than randomised independently, so
        // seeded data satisfies the reconciliation the dashboard asserts
        // (AT-009) instead of quietly contradicting it.
        $subtotal = round($energyKwh * $unitPrice, 2);
        $vat = round($subtotal * 0.07, 2);

        return [
            'user_id' => User::factory(),
            'vehicle_id' => Vehicle::factory(),
            'station_id' => ChargingStation::factory(),
            'connector_id' => null,
            'tariff_version_id' => null,
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify('+45 minutes'),
            'duration_minutes' => 45,
            'charging_type' => ChargingType::PUBLIC,
            'charging_mode' => ChargingMode::DC,
            'power_kw' => fake()->randomElement([50, 60, 120]),
            'soc_before' => fake()->numberBetween(10, 40),
            'soc_after' => fake()->numberBetween(60, 100),
            'energy_kwh' => $energyKwh,
            'energy_source' => EnergySource::MANUAL,
            'odometer_before_km' => null,
            'odometer_after_km' => null,
            'distance_km' => fake()->randomFloat(1, 50, 400),
            'subtotal' => $subtotal,
            'discount_amount' => 0,
            'vat_amount' => $vat,
            'total_amount' => round($subtotal + $vat, 2),
            'status' => SessionStatus::CONFIRMED,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => SessionStatus::DRAFT]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => SessionStatus::CANCELLED]);
    }

    public function home(): static
    {
        return $this->state(fn (): array => [
            'charging_type' => ChargingType::HOME,
            'charging_mode' => ChargingMode::AC,
            'station_id' => null,
        ]);
    }

    /** Free charging: energy and distance still count, spend does not. */
    public function free(): static
    {
        return $this->state(fn (): array => [
            'charging_type' => ChargingType::FREE,
            'subtotal' => 0,
            'vat_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
        ]);
    }
}
