<?php

declare(strict_types=1);

namespace App\Http\Requests\ChargingSession;

use App\Enums\ChargingMode;
use App\Enums\ChargingType;
use App\Enums\EnergySource;
use App\Models\ChargingConnector;
use App\Models\Vehicle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * docs/02 FR-003.
 *
 * Money is accepted -- a user recording a home charge knows what they paid --
 * but it is validated here and then recomposed by CostCalculationService, so
 * the stored subtotal/VAT/total always reconcile with each other. The columns
 * are not fillable, so no amount can reach the database by mass assignment
 * (docs/10 rules 3 and 10).
 */
class StoreChargingSessionRequest extends FormRequest
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
            // Ownership of the vehicle is enforced in withValidator: a user
            // must not attach a session to someone else's car (AT-007).
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'station_id' => ['nullable', 'integer', Rule::exists('charging_stations', 'id')->whereNull('deleted_at')],
            'connector_id' => ['nullable', 'integer', Rule::exists('charging_connectors', 'id')],

            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],

            'charging_type' => ['required', Rule::enum(ChargingType::class)],
            'charging_mode' => ['nullable', Rule::enum(ChargingMode::class)],
            'power_kw' => ['nullable', 'numeric', 'gt:0', 'max:999999.99'],

            'soc_before' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'soc_after' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'energy_kwh' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'energy_source' => ['nullable', Rule::enum(EnergySource::class)],
            'meter_start_kwh' => ['nullable', 'numeric', 'min:0'],
            'meter_end_kwh' => ['nullable', 'numeric', 'min:0', 'gte:meter_start_kwh'],

            'odometer_before_km' => ['nullable', 'numeric', 'min:0'],
            'odometer_after_km' => ['nullable', 'numeric', 'min:0', 'gte:odometer_before_km'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],

            // Money. Bounded by the DECIMAL(12,2) columns so a value cannot be
            // silently truncated by MySQL.
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'subtotal' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'parking_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            // Supplied as a positive amount; applied as a reduction by the
            // cost engine, so a caller cannot flip the sign.
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateVehicleOwnership($validator);
            $this->validateSocDirection($validator);
            $this->validateConnectorBelongsToStation($validator);
        });
    }

    /**
     * The vehicle must belong to the actor. Without this an attacker could
     * write sessions onto another user's vehicle and pollute their reports,
     * even though they could never read them back (AT-007).
     */
    private function validateVehicleOwnership(Validator $validator): void
    {
        $user = $this->user();
        $vehicleId = $this->integer('vehicle_id');

        if ($user === null || $vehicleId === 0) {
            return;
        }

        $ownerId = Vehicle::whereKey($vehicleId)->value('user_id');

        if ($ownerId !== null && ! $user->canAccessUserData((int) $ownerId)) {
            $validator->errors()->add('vehicle_id', 'The selected vehicle is invalid.');
        }
    }

    /**
     * Charging increases state of charge. An after-value below the
     * before-value would yield a negative SOC-estimated energy figure.
     */
    private function validateSocDirection(Validator $validator): void
    {
        $before = $this->input('soc_before');
        $after = $this->input('soc_after');

        if ($before === null || $after === null) {
            return;
        }

        if ((float) $after < (float) $before) {
            $validator->errors()->add('soc_after', 'State of charge after charging must not be lower than before.');
        }
    }

    /**
     * A connector must belong to the station the session names, otherwise the
     * session would claim a plug that is physically elsewhere.
     */
    private function validateConnectorBelongsToStation(Validator $validator): void
    {
        $connectorId = $this->input('connector_id');
        $stationId = $this->input('station_id');

        if ($connectorId === null) {
            return;
        }

        $actualStationId = ChargingConnector::whereKey($connectorId)->value('station_id');

        if ($actualStationId !== null && (int) $actualStationId !== (int) $stationId) {
            $validator->errors()->add('connector_id', 'The connector does not belong to the selected station.');
        }
    }
}
