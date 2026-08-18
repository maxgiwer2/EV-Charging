<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OcrResultStatus;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptOcrResult>
 */
class ReceiptOcrResultFactory extends Factory
{
    protected $model = ReceiptOcrResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_id' => Receipt::factory(),
            'provider' => 'none',
            'model' => null,
            'raw_payload' => ['text' => fake()->paragraph()],
            // Shape matches docs/05: every extracted field carries its own
            // confidence alongside the value.
            'extracted_data' => [
                'total' => ['value' => '350.00', 'confidence' => 0.97],
                'energy_kwh' => ['value' => '42.500', 'confidence' => 0.93],
                'station' => ['value' => fake()->streetName(), 'confidence' => 0.71],
            ],
            'confidence' => 0.9000,
            'status' => OcrResultStatus::SUCCESS,
            'processed_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => OcrResultStatus::FAILED,
            'extracted_data' => null,
            'confidence' => null,
        ]);
    }

    /** All fields below the review threshold, for highlight tests. */
    public function lowConfidence(): static
    {
        return $this->state(fn (): array => [
            'confidence' => 0.4000,
            'extracted_data' => [
                'total' => ['value' => '350.00', 'confidence' => 0.42],
                'energy_kwh' => ['value' => '42.500', 'confidence' => 0.38],
            ],
        ]);
    }
}
