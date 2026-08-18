<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stable API error codes (docs/10 rule 14).
 *
 * Clients branch on these strings, so a value here is a public contract:
 * add new cases, never rename or repurpose existing ones. The human-readable
 * message may change freely; the code may not.
 */
enum ErrorCode: string
{
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case CONFLICT = 'CONFLICT';
    case RATE_LIMITED = 'RATE_LIMITED';
    case INVALID_STATE_TRANSITION = 'INVALID_STATE_TRANSITION';
    case DUPLICATE_RECEIPT = 'DUPLICATE_RECEIPT';
    case UNSUPPORTED_FILE_TYPE = 'UNSUPPORTED_FILE_TYPE';
    case TARIFF_OVERLAP = 'TARIFF_OVERLAP';
    case SERVER_ERROR = 'SERVER_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::UNAUTHENTICATED => 401,
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            self::CONFLICT, self::DUPLICATE_RECEIPT, self::INVALID_STATE_TRANSITION, self::TARIFF_OVERLAP => 409,
            self::VALIDATION_FAILED, self::UNSUPPORTED_FILE_TYPE => 422,
            self::RATE_LIMITED => 429,
            self::SERVER_ERROR => 500,
        };
    }
}
