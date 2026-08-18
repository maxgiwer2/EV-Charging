<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * OCR review lifecycle (docs/05 -> Review).
 *
 * OCR_PENDING -> OCR_PROCESSING -> OCR_REVIEW -> VERIFIED | REJECTED
 *
 * The transition into VERIFIED is the one that turns extracted text into
 * financial fact, and docs/02 FR-005 plus AT-004 forbid reaching it without a
 * human confirming. No confidence score, however high, may skip OCR_REVIEW.
 */
enum ReceiptStatus: string
{
    case OCR_PENDING = 'OCR_PENDING';
    case OCR_PROCESSING = 'OCR_PROCESSING';
    case OCR_REVIEW = 'OCR_REVIEW';
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';

    /**
     * Allowed next states. Anything not listed here is rejected by the
     * receipt service, so an out-of-order OCR callback cannot, for example,
     * push a REJECTED receipt back into review.
     *
     * OCR_PENDING -> OCR_PENDING is permitted so a retried job (docs/03 ->
     * idempotent OCR callbacks) is a no-op rather than an error.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::OCR_PENDING => [self::OCR_PENDING, self::OCR_PROCESSING, self::REJECTED],
            self::OCR_PROCESSING => [self::OCR_REVIEW, self::OCR_PENDING, self::REJECTED],
            self::OCR_REVIEW => [self::VERIFIED, self::REJECTED],
            // Terminal. A correction to a verified receipt is a new audited
            // record, never an in-place status rewind (docs/10 rule 6).
            self::VERIFIED, self::REJECTED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether the receipt's values may be treated as confirmed financial data.
     */
    public function isVerified(): bool
    {
        return $this === self::VERIFIED;
    }

    /**
     * Whether a human still needs to look at this receipt (docs/02 FR-014 ->
     * notify on OCR review).
     */
    public function awaitsReview(): bool
    {
        return $this === self::OCR_REVIEW;
    }
}
