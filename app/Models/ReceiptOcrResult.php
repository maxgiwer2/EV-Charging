<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OcrResultStatus;
use Database\Factories\ReceiptOcrResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One OCR provider run. Append-only: rows are inserted, never updated, so the
 * original provider output stays available for audit (docs/05).
 *
 * @property array<string, mixed>|null $extracted_data
 * @property OcrResultStatus $status
 * @property Carbon $processed_at
 */
class ReceiptOcrResult extends Model
{
    /** @use HasFactory<ReceiptOcrResultFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'receipt_id',
        'provider',
        'model',
        'raw_payload',
        'extracted_data',
        'confidence',
        'status',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'extracted_data' => 'array',
            'confidence' => 'decimal:4',
            'status' => OcrResultStatus::class,
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Receipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * Field names whose confidence falls below the configured threshold, for
     * highlighting in the review UI (docs/05).
     *
     * This never gates verification -- a human confirms regardless
     * (FR-005, AT-004).
     *
     * @return list<string>
     */
    public function lowConfidenceFields(): array
    {
        $threshold = (float) config('ocr.review_threshold');
        $fields = [];

        foreach ($this->extracted_data ?? [] as $field => $payload) {
            if (! is_array($payload) || ! array_key_exists('confidence', $payload)) {
                continue;
            }

            if ((float) $payload['confidence'] < $threshold) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }
}
