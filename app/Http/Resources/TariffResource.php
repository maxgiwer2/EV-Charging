<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChargingTariff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChargingTariff
 */
class TariffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'charging_type' => $this->charging_type->value,
            'is_active' => $this->is_active,
            'network' => new ChargingNetworkResource($this->whenLoaded('network')),
            'station' => new ChargingStationResource($this->whenLoaded('station')),
            'versions_count' => $this->whenCounted('versions'),
            'versions' => TariffVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
