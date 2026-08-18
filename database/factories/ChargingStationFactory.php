<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChargingNetwork;
use App\Models\ChargingStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingStation>
 */
class ChargingStationFactory extends Factory
{
    protected $model = ChargingStation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'network_id' => ChargingNetwork::factory(),
            'name' => fake()->streetName().' Station',
            'code' => strtoupper(fake()->unique()->bothify('ST####')),
            'address' => fake()->address(),
            'province' => fake()->randomElement(['Bangkok', 'Chiang Mai', 'Phuket', 'Khon Kaen']),
            'latitude' => fake()->latitude(5, 20),
            'longitude' => fake()->longitude(97, 105),
            'is_active' => true,
        ];
    }

    /** A station with no operator, e.g. an independent charger. */
    public function withoutNetwork(): static
    {
        return $this->state(fn (): array => ['network_id' => null]);
    }
}
