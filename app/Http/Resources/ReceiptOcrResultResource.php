<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReceiptOcrResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReceiptOcrResult
 */
class ReceiptOcrResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'confidence' => $this->confidence,
            // What the provider read, with per-field confidence. Never
            // overwritten by a reviewer's corrections (docs/05).
            'extracted_data' => $this->extracted_data,
            // Drives the highlighting in the review UI.
            'low_confidence_fields' => $this->lowConfidenceFields(),
            'processed_at' => $this->processed_at->toIso8601String(),
            // raw_payload is deliberately omitted: it is provider-shaped, can
            // be large, and may echo the receipt's full text.
        ];
    }
}
