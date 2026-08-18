<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChargingType;
use App\Models\ChargingNetwork;
use App\Models\ChargingTariff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingTariff>
 */
class ChargingTariffFactory extends Factory
{
    protected $model = ChargingTariff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => ChargingNetwork::factory(),
            'station_id' => null,
            'name' => fake()->words(2, true).' rate',
            'charging_type' => ChargingType::PUBLIC,
            'is_active' => true,
        ];
    }

    public function home(): static
    {
        return $this->state(fn (): array => [
            'charging_type' => ChargingType::HOME,
            'network_id' => null,
        ]);
    }
}
