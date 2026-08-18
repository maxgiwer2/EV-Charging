<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to edit a tariff version a charging session has
 * already been priced against (AT-006).
 *
 * Such a version is evidence, not configuration: changing its rates would
 * silently rewrite historical totals. The correction is a new version.
 *
 * Surfaces as CONFLICT / HTTP 409.
 */
class ImmutableTariffVersion extends RuntimeException
{
    public function __construct(public readonly int $versionId)
    {
        parent::__construct(
            "Tariff version {$versionId} has priced a charging session and can no longer be edited. Publish a new version instead."
        );
    }
}
