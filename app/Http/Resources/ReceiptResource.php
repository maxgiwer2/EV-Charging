<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Receipt
 *
 * file_path is never exposed (docs/07 -> never expose private file paths).
 * Clients fetch the image through the authorized download route instead.
 */
class ReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'charging_session_id' => $this->charging_session_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            // The content hash is shown so a client can recognise a file it
            // already holds; it reveals nothing about storage layout.
            'sha256' => $this->sha256,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status->value,
            'awaits_review' => $this->status->awaitsReview(),
            'is_verified' => $this->status->isVerified(),

            // Populated only after a human confirms (AT-004).
            'verified_data' => $this->verified_data,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),

            // Present when the upload looked like something already on file
            // (AT-005). Advisory: a human decides.
            'duplicate_matches' => $this->duplicate_matches,

            'download_url' => route('receipts.download', ['receipt' => $this->id]),

            'ocr_results' => ReceiptOcrResultResource::collection($this->whenLoaded('ocrResults')),

            'uploaded_at' => $this->uploaded_at->toIso8601String(),
        ];
    }
}
