<?php

declare(strict_types=1);

namespace App\Http\Requests\Tariff;

use App\Enums\ChargingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A tariff is the stable identity of a pricing scheme; rates live in versions
 * (docs/02 FR-007), so no rate is accepted here.
 */
class StoreTariffRequest extends FormRequest
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
            'name' => [$required, 'string', 'max:200'],
            'charging_type' => [$required, Rule::enum(ChargingType::class)],
            'network_id' => ['nullable', 'integer', Rule::exists('charging_networks', 'id')->whereNull('deleted_at')],
            'station_id' => ['nullable', 'integer', Rule::exists('charging_stations', 'id')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
