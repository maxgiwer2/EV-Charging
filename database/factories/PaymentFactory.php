<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChargingSession;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charging_session_id' => ChargingSession::factory(),
            'method' => fake()->randomElement(['CREDIT_CARD', 'E_WALLET', 'APP_CREDIT', 'CASH']),
            'amount' => fake()->randomFloat(2, 50, 500),
            'reference_no' => fake()->optional()->bothify('REF########'),
            'paid_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
