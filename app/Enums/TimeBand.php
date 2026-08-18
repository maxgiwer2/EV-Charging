<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tariff time band (docs/02 FR-007, FR-008 -> TOU).
 *
 * Peak/off-peak windows are themselves tariff data and are never hard-coded
 * (docs/10 rule 9); this enum only names the bands.
 */
enum TimeBand: string
{
    case NORMAL = 'NORMAL';
    case PEAK = 'PEAK';
    case OFF_PEAK = 'OFF_PEAK';
    case OTHER = 'OTHER';
}
