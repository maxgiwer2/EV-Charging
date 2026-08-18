<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'charging_session_id' => null,
            'uploaded_by' => User::factory(),
            'file_path' => 'receipts/'.fake()->uuid().'.jpg',
            'original_filename' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(50_000, 3_000_000),
            'sha256' => hash('sha256', Str::random(40)),
            'receipt_number' => fake()->optional()->bothify('INV#######'),
            'status' => ReceiptStatus::OCR_PENDING,
            'verified_by' => null,
            'verified_at' => null,
            'uploaded_at' => now(),
        ];
    }

    public function awaitingReview(): static
    {
        return $this->state(fn (): array => ['status' => ReceiptStatus::OCR_REVIEW]);
    }

    /**
     * A receipt a human has confirmed. verified_by/verified_at are set
     * together, because a verified receipt without an actor would break the
     * audit question "who approved this" (AT-004, AT-010).
     */
    public function verified(?User $verifier = null): static
    {
        return $this->state(fn (): array => [
            'status' => ReceiptStatus::VERIFIED,
            'verified_by' => $verifier === null ? User::factory() : $verifier->id,
            'verified_at' => now(),
        ]);
    }

    /** Same content hash as $other: the duplicate scenario in AT-005. */
    public function duplicateOf(Receipt $other): static
    {
        return $this->state(fn (): array => ['sha256' => $other->sha256]);
    }
}
