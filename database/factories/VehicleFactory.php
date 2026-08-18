<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'make' => fake()->randomElement(['BYD', 'Tesla', 'MG', 'Ora', 'Neta', 'Volvo']),
            'model' => fake()->randomElement(['Atto 3', 'Model 3', 'ZS EV', 'Good Cat', 'V', 'EX30']),
            'trim' => fake()->optional()->word(),
            'model_year' => fake()->numberBetween(2018, 2026),
            'plate_no' => fake()->optional()->bothify('?? ####'),
            // optional()->unique() deadlocks: unique() cannot yield distinct
            // nulls, so it retries until it gives up. Decide nullability first.
            'vin' => fake()->boolean(70) ? fake()->unique()->numerify('#################') : null,
            'battery_kwh' => fake()->randomFloat(3, 30, 100),
            'ac_max_kw' => fake()->randomElement([7.4, 11, 22]),
            'dc_max_kw' => fake()->randomElement([50, 100, 150, 250]),
            'initial_odometer_km' => fake()->randomFloat(1, 0, 50000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
