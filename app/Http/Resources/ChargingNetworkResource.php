<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChargingNetwork;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChargingNetwork
 */
class ChargingNetworkResource extends JsonResource
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
            'is_active' => $this->is_active,
            'stations_count' => $this->whenCounted('stations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
