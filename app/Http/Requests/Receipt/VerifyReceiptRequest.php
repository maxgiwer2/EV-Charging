<?php

declare(strict_types=1);

namespace App\Http\Requests\Receipt;

use App\Enums\ChargingType;
use App\Models\Vehicle;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The human confirmation step (AT-004).
 *
 * These are the values a person approved, which may differ from what OCR read.
 * They are validated as strictly as a manual entry, because from here they
 * become financial fact: OCR provenance grants no leniency.
 */
class VerifyReceiptRequest extends FormRequest
{
    /**
     * Authorize before validating.
     *
     * FormRequest resolution runs authorize() first and rules() second, so
     * putting the policy check here means a caller who may not verify this
     * receipt gets 403 (or 404 for another owner) rather than a 422 that
     * would describe the payload they were never allowed to submit.
     *
     * Returning the policy's Response, rather than a bool, preserves the
     * deliberate 404-vs-403 split (AT-007).
     */
    public function authorize(Gate $gate): Response
    {
        return $gate->inspect('verify', $this->route('receipt'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required when the receipt is not already attached to a session,
            // since verification must produce one (docs/04).
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->whereNull('deleted_at')],
            'station_id' => ['nullable', 'integer', Rule::exists('charging_stations', 'id')->whereNull('deleted_at')],
            'charging_type' => ['required', Rule::enum(ChargingType::class)],

            'transaction_date' => ['required', 'date'],
            'receipt_number' => ['nullable', 'string', 'max:150'],

            'energy_kwh' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],

            // Money: bounded by the DECIMAL(12,2) columns so a value cannot be
            // silently truncated by MySQL.
            'subtotal' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'parking_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'vat' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'total' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],

            'payment_method' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateVehicleOwnership($validator);
            $this->validateTotalsAddUp($validator);
        });
    }

    /**
     * The vehicle must belong to the actor (AT-007).
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
     * subtotal - discount + fees + vat should equal total.
     *
     * Compared with bcmath at 2dp, never with float ==, and with a one-satang
     * tolerance: real receipts round each line independently, so an exact
     * match would reject legitimate paperwork. A larger gap means a figure was
     * mistyped, which matters because this total becomes the charged amount.
     */
    private function validateTotalsAddUp(Validator $validator): void
    {
        $total = $this->input('total');
        $subtotal = $this->input('subtotal');

        if (! is_numeric($total) || ! is_numeric($subtotal)) {
            return;
        }

        $expected = bcadd((string) $subtotal, '0', 2);

        foreach (['service_fee', 'parking_fee', 'vat'] as $addition) {
            $value = $this->input($addition);

            if (is_numeric($value)) {
                $expected = bcadd($expected, (string) $value, 2);
            }
        }

        $discount = $this->input('discount');

        if (is_numeric($discount)) {
            $expected = bcsub($expected, (string) $discount, 2);
        }

        $difference = bcsub(bcadd((string) $total, '0', 2), $expected, 2);

        if (bccomp(ltrim($difference, '-'), '0.01', 2) === 1) {
            $validator->errors()->add(
                'total',
                "The total does not match the breakdown (expected {$expected})."
            );
        }
    }
}
