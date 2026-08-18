<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChargingNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingNetwork>
 */
class ChargingNetworkFactory extends Factory
{
    protected $model = ChargingNetwork::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->bothify('NET???##')),
            'is_active' => true,
        ];
    }
}
