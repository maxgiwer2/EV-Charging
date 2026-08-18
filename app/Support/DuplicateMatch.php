<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Receipt;

/**
 * One suspected duplicate, with why it was suspected.
 *
 * AT-005 requires the system to *flag* a probable duplicate, not to decide
 * one, so the reasons travel with the match and a human makes the call.
 */
final readonly class DuplicateMatch
{
    /** Identical file contents: the same image uploaded twice. */
    public const REASON_IDENTICAL_FILE = 'IDENTICAL_FILE';

    /** Same receipt number. */
    public const REASON_RECEIPT_NUMBER = 'RECEIPT_NUMBER';

    /** Same station, close in time, same amount and energy. */
    public const REASON_SIMILAR_TRANSACTION = 'SIMILAR_TRANSACTION';

    /**
     * @param  list<string>  $reasons
     * @param  float  $score  0..1, how strongly this looks like a duplicate
     */
    public function __construct(
        public Receipt $receipt,
        public array $reasons,
        public float $score,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'receipt_id' => $this->receipt->id,
            'uploaded_at' => $this->receipt->uploaded_at->toIso8601String(),
            'status' => $this->receipt->status->value,
            'reasons' => $this->reasons,
            'score' => $this->score,
        ];
    }
}
