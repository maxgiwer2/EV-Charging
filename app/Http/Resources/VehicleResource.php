<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'trim' => $this->trim,
            'display_name' => $this->displayName(),
            'model_year' => $this->model_year,
            'plate_no' => $this->plate_no,
            'vin' => $this->vin,
            // Decimal columns stay strings end to end so no float rounding is
            // introduced between the database and the client.
            'battery_kwh' => $this->battery_kwh,
            'ac_max_kw' => $this->ac_max_kw,
            'dc_max_kw' => $this->dc_max_kw,
            'initial_odometer_km' => $this->initial_odometer_km,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
