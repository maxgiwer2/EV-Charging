<?php

declare(strict_types=1);

namespace App\Support\Ocr;

use InvalidArgumentException;

/**
 * One field pulled off a receipt, with the provider's confidence in it.
 *
 * docs/05 requires every extracted field to carry a confidence in 0..1. The
 * value is kept as a nullable string rather than a float or a parsed amount:
 * a provider that could not read a figure must be able to say so, and money
 * read from an image must not pass through a float on its way to a DECIMAL
 * column (docs/10 rule 4).
 */
final readonly class ExtractedField
{
    public function __construct(
        public ?string $value,
        public float $confidence,
    ) {
        // A confidence outside 0..1 means the adapter mis-mapped its provider's
        // scale (some report 0..100). Failing loudly here beats silently
        // treating a 92 as certain.
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException(
                "Confidence must be between 0 and 1, got {$confidence}."
            );
        }
    }

    /**
     * A field the provider did not find. Confidence is zero, never a default
     * such as 1.0 -- absence must never look like certainty.
     */
    public static function missing(): self
    {
        return new self(null, 0.0);
    }

    public function isPresent(): bool
    {
        return $this->value !== null && $this->value !== '';
    }

    /**
     * Whether a human must look at this field (docs/05 -> highlight low
     * confidence). Never decides verification, only highlighting.
     */
    public function needsReview(?float $threshold = null): bool
    {
        $threshold ??= (float) config('ocr.review_threshold');

        return ! $this->isPresent() || $this->confidence < $threshold;
    }

    /**
     * @return array{value: string|null, confidence: float}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @param  array{value?: string|null, confidence?: float|int|string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['value']) ? (string) $data['value'] : null,
            (float) ($data['confidence'] ?? 0.0),
        );
    }
}
