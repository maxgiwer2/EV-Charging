<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AC vs DC current type (docs/02 FR-003, docs/06 -> AC/DC ratio).
 */
enum ChargingMode: string
{
    case AC = 'AC';
    case DC = 'DC';
    case OTHER = 'OTHER';
}
