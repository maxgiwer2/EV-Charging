<?php

declare(strict_types=1);

namespace App\Http\Requests\Reference;

use App\Enums\ChargingMode;
use App\Enums\ConnectorStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChargingConnectorRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'station_id' => [$required, 'integer', Rule::exists('charging_stations', 'id')->whereNull('deleted_at')],
            'connector_type' => [$required, 'string', 'max:50'],
            'charging_mode' => [$required, Rule::enum(ChargingMode::class)],
            'max_power_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],
            'status' => ['sometimes', Rule::enum(ConnectorStatus::class)],
        ];
    }
}
