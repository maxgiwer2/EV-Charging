<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChargingConnector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChargingConnector
 */
class ChargingConnectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'connector_type' => $this->connector_type,
            'charging_mode' => $this->charging_mode->value,
            'max_power_kw' => $this->max_power_kw,
            'status' => $this->status->value,
        ];
    }
}
