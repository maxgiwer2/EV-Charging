<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Ocr\OcrResult;

/**
 * Provider-independent OCR contract
 * (architecture/system-architecture.md -> OCR Provider Adapter).
 *
 * Domain code depends on this interface only. Implementations wrap a vendor
 * SDK or HTTP API and are selected by config('ocr.driver'), so swapping
 * providers never touches the receipt or cost logic.
 *
 * Implementations must:
 *  - return normalised fields per docs/05, each with a 0..1 confidence;
 *  - preserve the untouched vendor response in OcrResult::$rawPayload;
 *  - never invent a value they did not read (docs/05 -> AI Rules);
 *  - never throw for a business failure -- return OcrResult::failed() so the
 *    receipt lands in human review instead of the job retrying forever.
 */
interface OcrProviderInterface
{
    /**
     * Extract fields from a receipt file.
     *
     * @param  string  $contents  raw bytes of the receipt (image or PDF)
     * @param  string  $mimeType  validated MIME type of those bytes
     */
    public function extract(string $contents, string $mimeType): OcrResult;

    /**
     * Identifier stored on receipt_ocr_results.provider, so a stored result
     * can always be traced back to what produced it (docs/05).
     */
    public function name(): string;
}
