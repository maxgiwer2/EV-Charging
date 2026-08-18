<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome of one OCR provider run (database/schema.sql receipt_ocr_results).
 *
 * PARTIAL means the provider returned some fields but not all; it is still
 * sent to human review rather than discarded, because a reviewer can fill the
 * gaps from the stored image.
 */
enum OcrResultStatus: string
{
    case SUCCESS = 'SUCCESS';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';

    public function producedData(): bool
    {
        return $this !== self::FAILED;
    }
}
