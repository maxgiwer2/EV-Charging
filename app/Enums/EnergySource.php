<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the energy figure came from (docs/02 FR-009).
 *
 * FR-009 fixes the precedence: verified receipt / charger reading > manual
 * entry > SOC estimate. priority() encodes that order so the cost engine can
 * decide whether an incoming value is allowed to replace the stored one, and
 * so reports can disclose how trustworthy a number is.
 */
enum EnergySource: string
{
    case RECEIPT = 'RECEIPT';
    case CHARGER = 'CHARGER';
    case MANUAL = 'MANUAL';
    case SOC_ESTIMATE = 'SOC_ESTIMATE';

    /**
     * Higher wins. Equal values mean the newer entry may overwrite.
     */
    public function priority(): int
    {
        return match ($this) {
            self::RECEIPT, self::CHARGER => 3,
            self::MANUAL => 2,
            self::SOC_ESTIMATE => 1,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->priority() > $other->priority();
    }

    /**
     * A SOC estimate is derived from battery capacity and charge percentages,
     * not measured. Metrics built on it are approximate and must be labelled
     * as such rather than presented as billed fact.
     */
    public function isEstimate(): bool
    {
        return $this === self::SOC_ESTIMATE;
    }
}
