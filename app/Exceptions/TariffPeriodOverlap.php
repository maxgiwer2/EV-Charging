<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a tariff version would cover an instant another already covers
 * (docs/04 -> validate overlap).
 *
 * MySQL cannot express a non-overlap constraint over a date range, so this is
 * enforced in TariffService. Two versions covering the same moment would make
 * resolution ambiguous and let row order decide the price.
 *
 * Surfaces as TARIFF_OVERLAP / HTTP 409.
 */
class TariffPeriodOverlap extends RuntimeException {}
