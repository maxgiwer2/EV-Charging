<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Budget
 */
class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'period' => $this->period->value,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            // Resolved rather than raw, so a client sees the defaults in use.
            'alert_thresholds' => $this->thresholds(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
