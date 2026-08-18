<?php

declare(strict_types=1);

namespace App\Http\Requests\Budget;

use App\Enums\BudgetPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/02 FR-013: monthly budget with configurable thresholds.
 */
class StoreBudgetRequest extends FormRequest
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
            // Above zero: a zero budget has no meaningful percentage, and
            // every charge would immediately be "over budget".
            'amount' => [$required, 'numeric', 'gt:0', 'max:9999999999.99'],
            'period' => [$required, Rule::enum(BudgetPeriod::class)],
            'period_start' => [$required, 'date'],
            'period_end' => [$required, 'date', 'after_or_equal:period_start'],

            // Configurable per docs/02; defaults to 50/80/100 when omitted.
            'alert_thresholds' => ['sometimes', 'array', 'max:10'],
            'alert_thresholds.*' => ['integer', 'min:1', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $thresholds = $this->input('alert_thresholds');

            if (is_array($thresholds) && count($thresholds) !== count(array_unique($thresholds))) {
                // A repeated threshold would fire twice for one crossing.
                $validator->errors()->add('alert_thresholds', 'Thresholds must be unique.');
            }
        });
    }
}
