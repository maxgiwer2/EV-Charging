<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the energy was taken from (docs/02 FR-003, FR-008).
 */
enum ChargingType: string
{
    case HOME = 'HOME';
    case PUBLIC = 'PUBLIC';
    case WORKPLACE = 'WORKPLACE';
    case DESTINATION = 'DESTINATION';
    case FREE = 'FREE';
    case OTHER = 'OTHER';

    /**
     * Home charging is billed by a utility tariff (MEA/PEA, normal or TOU)
     * rather than by a charging network (docs/02 FR-008).
     */
    public function isHome(): bool
    {
        return $this === self::HOME;
    }

    /**
     * Drives the home-vs-public split reported on the dashboard
     * (docs/06 -> home/public ratio).
     */
    public function isPublic(): bool
    {
        return in_array($this, [self::PUBLIC, self::DESTINATION], true);
    }

    /**
     * A free session is still recorded: it contributes energy and distance to
     * efficiency metrics even though its cost is zero. It must not be dropped
     * from analytics, only from spend.
     */
    public function isFree(): bool
    {
        return $this === self::FREE;
    }
}
