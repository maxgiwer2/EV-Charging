<?php

declare(strict_types=1);

namespace App\Http\Requests\Tariff;

use App\Enums\TimeBand;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A priced version of a tariff (docs/02 FR-007, docs/04 Admin Tariff).
 */
class StoreTariffVersionRequest extends FormRequest
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
            // 4dp: unit rates are quoted more precisely than money is rounded.
            'energy_rate' => ['required', 'numeric', 'min:0', 'max:999999.9999'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'parking_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // Nullable on purpose: null means "this tariff does not state a
            // VAT rate", which is different from an explicit 0%.
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'time_band' => ['sometimes', Rule::enum(TimeBand::class)],
            'power_min_kw' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'power_max_kw' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('power_min_kw');
            $max = $this->input('power_max_kw');

            // An inverted band would match nothing, silently leaving sessions
            // in that range unpriced.
            if (is_numeric($min) && is_numeric($max) && (float) $max <= (float) $min) {
                $validator->errors()->add('power_max_kw', 'The upper power bound must be above the lower bound.');
            }
        });
    }
}
