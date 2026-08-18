<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChargingMode;
use App\Enums\ConnectorStatus;
use App\Models\ChargingConnector;
use App\Models\ChargingStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingConnector>
 */
class ChargingConnectorFactory extends Factory
{
    protected $model = ChargingConnector::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'station_id' => ChargingStation::factory(),
            'connector_type' => fake()->randomElement(['CCS2', 'CHAdeMO', 'Type 2', 'GB/T']),
            'charging_mode' => fake()->randomElement(ChargingMode::cases()),
            'max_power_kw' => fake()->randomElement([7.4, 22, 50, 120, 180]),
            'status' => ConnectorStatus::AVAILABLE,
        ];
    }

    public function dc(): static
    {
        return $this->state(fn (): array => ['charging_mode' => ChargingMode::DC]);
    }

    public function ac(): static
    {
        return $this->state(fn (): array => ['charging_mode' => ChargingMode::AC]);
    }
}
