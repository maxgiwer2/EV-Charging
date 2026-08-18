<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChargingStation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChargingStation
 */
class ChargingStationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'province' => $this->province,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
            // whenLoaded keeps this endpoint from triggering N+1 queries; the
            // controller decides what to eager load (docs/03).
            'network' => new ChargingNetworkResource($this->whenLoaded('network')),
            'connectors' => ChargingConnectorResource::collection($this->whenLoaded('connectors')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
