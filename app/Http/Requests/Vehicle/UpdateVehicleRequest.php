<?php

declare(strict_types=1);

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
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
        $vehicleId = $this->route('vehicle')?->id;

        return [
            'make' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'trim' => ['nullable', 'string', 'max:100'],
            'model_year' => ['nullable', 'integer', 'min:1990', 'max:'.((int) date('Y') + 1)],
            'plate_no' => ['nullable', 'string', 'max:30'],
            'vin' => [
                'nullable', 'string', 'max:100',
                // Ignore this vehicle's own row, otherwise saving an unchanged
                // form would collide with itself.
                Rule::unique('vehicles', 'vin')->ignore($vehicleId)->whereNull('deleted_at'),
            ],
            'battery_kwh' => ['nullable', 'numeric', 'gt:0', 'max:999.999'],
            'ac_max_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'dc_max_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'initial_odometer_km' => ['nullable', 'numeric', 'min:0', 'max:99999999999.9'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
