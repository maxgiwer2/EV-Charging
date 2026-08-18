<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operating status of a physical connector (docs/02 FR-006).
 */
enum ConnectorStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case BUSY = 'BUSY';
    case OUT_OF_SERVICE = 'OUT_OF_SERVICE';
    case UNKNOWN = 'UNKNOWN';
}
