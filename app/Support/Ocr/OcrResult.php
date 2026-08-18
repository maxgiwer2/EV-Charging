<?php

declare(strict_types=1);

namespace App\Support\Ocr;

use App\Enums\OcrResultStatus;

/**
 * The normalised output of one OCR run (docs/05 -> OCR Output).
 *
 * Every provider adapter maps its vendor response into this shape, so domain
 * code never sees a vendor payload. The raw response travels alongside in
 * $rawPayload and is stored verbatim -- docs/05 requires the original to be
 * preserved and never overwritten.
 */
final readonly class OcrResult
{
    /**
     * Field names docs/05 requires an adapter to normalise. Anything else a
     * provider returns is still kept in $rawPayload, just not promoted to a
     * first-class field.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'merchant',
        'station',
        'receipt_number',
        'transaction_date',
        'transaction_time',
        'energy_kwh',
        'unit_price',
        'subtotal',
        'service_fee',
        'parking_fee',
        'discount',
        'vat',
        'total',
        'payment_method',
        'connector',
    ];

    /**
     * @param  array<string, ExtractedField>  $fields
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public OcrResultStatus $status,
        public array $fields = [],
        public array $rawPayload = [],
        public ?string $rawText = null,
    ) {}

    /**
     * A run that produced nothing. Used by the `none` driver and by adapters
     * whose provider errored.
     *
     * It deliberately carries no fields at all rather than empty ones: an
     * empty string in `total` could later be read as zero, and inventing a
     * financial value is exactly what docs/05 forbids.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public static function failed(string $provider, ?string $model = null, array $rawPayload = []): self
    {
        return new self($provider, $model, OcrResultStatus::FAILED, [], $rawPayload);
    }

    public function field(string $name): ExtractedField
    {
        return $this->fields[$name] ?? ExtractedField::missing();
    }

    /**
     * Mean confidence across the fields that were actually found.
     *
     * Missing fields are excluded rather than counted as zero: a receipt with
     * no parking fee should not look less reliable than one with it. Returns
     * null when nothing was extracted, which is different from 0.0 confidence.
     */
    public function overallConfidence(): ?float
    {
        $present = array_filter($this->fields, fn (ExtractedField $f): bool => $f->isPresent());

        if ($present === []) {
            return null;
        }

        $sum = array_sum(array_map(fn (ExtractedField $f): float => $f->confidence, $present));

        return round($sum / count($present), 4);
    }

    /**
     * Fields a reviewer must check (docs/05 -> highlight low confidence).
     *
     * @return list<string>
     */
    public function lowConfidenceFields(?float $threshold = null): array
    {
        $flagged = [];

        foreach ($this->fields as $name => $field) {
            if ($field->needsReview($threshold)) {
                $flagged[] = $name;
            }
        }

        return $flagged;
    }

    /**
     * Shape persisted to receipt_ocr_results.extracted_data.
     *
     * @return array<string, array{value: string|null, confidence: float}>
     */
    public function toExtractedData(): array
    {
        return array_map(fn (ExtractedField $f): array => $f->toArray(), $this->fields);
    }
}
