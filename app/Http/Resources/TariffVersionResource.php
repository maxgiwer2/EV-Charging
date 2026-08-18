<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TariffVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TariffVersion
 */
class TariffVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'charging_tariff_id' => $this->charging_tariff_id,
            // Rates as strings: DECIMAL precision must survive the round trip.
            'energy_rate' => $this->energy_rate,
            'service_fee' => $this->service_fee,
            'parking_fee' => $this->parking_fee,
            // null means the tariff does not state a VAT rate, which is not
            // the same as 0%.
            'vat_rate' => $this->vat_rate,
            'time_band' => $this->time_band->value,
            'power_min_kw' => $this->power_min_kw,
            'power_max_kw' => $this->power_max_kw,
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            // Tells a client whether editing is still possible (AT-006).
            'is_locked' => $this->isReferencedBySession(),
        ];
    }
}
