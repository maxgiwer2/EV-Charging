<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChargingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChargingSession
 */
class ChargingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'station_id' => $this->station_id,
            'connector_id' => $this->connector_id,
            'tariff_version_id' => $this->tariff_version_id,

            // UTC on the wire (docs/10 rule 7); the client renders in
            // Asia/Bangkok. Sending a local time without an offset is what
            // makes month-boundary reports disagree.
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,

            'charging_type' => $this->charging_type->value,
            'charging_mode' => $this->charging_mode?->value,
            'power_kw' => $this->power_kw,

            'soc_before' => $this->soc_before,
            'soc_after' => $this->soc_after,

            'energy_kwh' => $this->energy_kwh,
            'energy_source' => $this->energy_source?->value,
            'meter_start_kwh' => $this->meter_start_kwh,
            'meter_end_kwh' => $this->meter_end_kwh,

            'odometer_before_km' => $this->odometer_before_km,
            'odometer_after_km' => $this->odometer_after_km,
            'distance_km' => $this->distance_km,

            // Money as strings, preserving DECIMAL precision.
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'vat_amount' => $this->vat_amount,
            'total_amount' => $this->total_amount,

            'status' => $this->status->value,
            'notes' => $this->notes,

            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'station' => new ChargingStationResource($this->whenLoaded('station')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
