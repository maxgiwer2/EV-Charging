<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ReceiptStatus;
use RuntimeException;

/**
 * Raised when something attempts an illegal receipt status change
 * (docs/05 -> Review lifecycle).
 *
 * Surfaces as INVALID_STATE_TRANSITION / HTTP 409.
 */
class InvalidReceiptTransition extends RuntimeException
{
    public function __construct(
        public readonly ReceiptStatus $from,
        public readonly ReceiptStatus $to,
    ) {
        parent::__construct("A receipt cannot move from {$from->value} to {$to->value}.");
    }
}
