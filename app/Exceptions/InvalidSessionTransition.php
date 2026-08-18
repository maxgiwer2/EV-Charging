<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\SessionStatus;
use RuntimeException;

/**
 * Raised on an illegal charging session status change.
 *
 * Surfaces as INVALID_STATE_TRANSITION / HTTP 409.
 */
class InvalidSessionTransition extends RuntimeException
{
    public function __construct(
        public readonly SessionStatus $from,
        public readonly SessionStatus $to,
    ) {
        parent::__construct("A charging session cannot move from {$from->value} to {$to->value}.");
    }
}
