<?php

declare(strict_types=1);

namespace App\Http\Requests\ChargingSession;

/**
 * Same domain constraints as creation, but every field optional.
 *
 * Inherits the cross-field checks (vehicle ownership, SOC direction,
 * connector/station consistency) so a partial update cannot bypass a rule that
 * creation enforces.
 */
class UpdateChargingSessionRequest extends StoreChargingSessionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach (['vehicle_id', 'started_at', 'charging_type'] as $required) {
            array_unshift($rules[$required], 'sometimes');
            $rules[$required] = array_values(array_diff($rules[$required], ['required']));
        }

        return $rules;
    }
}
