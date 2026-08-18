<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChargingCostLine;
use App\Models\ChargingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargingCostLine>
 */
class ChargingCostLineFactory extends Factory
{
    protected $model = ChargingCostLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charging_session_id' => ChargingSession::factory(),
            'line_type' => ChargingCostLine::TYPE_ENERGY,
            'quantity' => fake()->randomFloat(3, 5, 60),
            'unit_price' => fake()->randomFloat(4, 4, 9),
            'amount' => fake()->randomFloat(2, 50, 400),
        ];
    }

    /** Discounts are stored negative so lines sum to the subtotal. */
    public function discount(float $amount): static
    {
        return $this->state(fn (): array => [
            'line_type' => ChargingCostLine::TYPE_DISCOUNT,
            'quantity' => null,
            'unit_price' => null,
            'amount' => -abs($amount),
        ]);
    }
}
