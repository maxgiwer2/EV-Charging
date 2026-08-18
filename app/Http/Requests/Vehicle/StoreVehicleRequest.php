<?php

declare(strict_types=1);

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/02 FR-002. Authorization is handled by VehiclePolicy via the
 * controller's authorizeResource, so authorize() only needs to defer.
 */
class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'trim' => ['nullable', 'string', 'max:100'],
            // Upper bound is next year, so a model year is a plausible vehicle
            // rather than an arbitrary number.
            'model_year' => ['nullable', 'integer', 'min:1990', 'max:'.((int) date('Y') + 1)],
            'plate_no' => ['nullable', 'string', 'max:30'],
            'vin' => ['nullable', 'string', 'max:100', Rule::unique('vehicles', 'vin')->whereNull('deleted_at')],

            // Capacity must be positive: it divides into SOC-based energy
            // estimates (FR-009), where a zero would be a division by zero.
            'battery_kwh' => ['nullable', 'numeric', 'gt:0', 'max:999.999'],
            'ac_max_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'dc_max_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'initial_odometer_km' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
