<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\OcrProviderInterface;
use App\Support\Ocr\OcrResult;

/**
 * No-op adapter, and the default driver (config/ocr.php).
 *
 * It extracts nothing and says so. That is the correct behaviour for an
 * unconfigured system: the receipt still reaches OCR_REVIEW, where a human
 * keys the values in by hand. Returning fabricated or zero-valued fields
 * would violate docs/05 ("never invent missing financial values") and could
 * put a zero total onto a financial record.
 *
 * It also keeps a fresh checkout and the test suite free of network calls.
 */
class NoneOcrProvider implements OcrProviderInterface
{
    public function extract(string $contents, string $mimeType): OcrResult
    {
        return OcrResult::failed($this->name(), null, [
            'reason' => 'No OCR provider configured; manual entry required.',
            'mime_type' => $mimeType,
            'bytes' => strlen($contents),
        ]);
    }

    public function name(): string
    {
        return 'none';
    }
}
