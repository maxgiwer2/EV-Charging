<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnergySource;
use App\Models\ChargingCostLine;
use App\Models\ChargingSession;
use App\Support\Money;
use App\Support\SessionMetrics;

/**
 * Owns money and energy on a charging session (docs/02 FR-009, docs/06).
 *
 * Two rules live here and nowhere else:
 *
 *  1. **Energy precedence.** FR-009 orders the sources: a verified receipt or
 *     charger reading beats a manual entry, which beats a SOC estimate. Before
 *     this service existed the ordering was modelled on the enum but never
 *     consulted, so a later estimate could silently overwrite a billed figure.
 *
 *  2. **Totals are derived, never accepted.** A client may state what was
 *     charged, but the stored subtotal/VAT/total are assembled here from
 *     decimal-safe arithmetic so the parts always reconcile with the whole.
 */
class CostCalculationService
{
    /**
     * Decide the energy figure for a session.
     *
     * Returns null when the incoming value must not replace what is stored,
     * so callers can tell "rejected" from "wrote the same number".
     *
     * @return array{kwh: string, source: EnergySource}|null
     */
    public function resolveEnergy(
        ChargingSession $session,
        ?string $incomingKwh,
        ?EnergySource $incomingSource,
    ): ?array {
        if ($incomingKwh === null || $incomingSource === null) {
            return null;
        }

        $currentSource = $session->energy_source;

        // Nothing recorded yet, so anything is an improvement.
        if ($currentSource === null || $session->energy_kwh === null) {
            return ['kwh' => $incomingKwh, 'source' => $incomingSource];
        }

        // FR-009: a lower-precedence source must not overwrite a better one.
        // Equal precedence is allowed through -- a corrected manual reading
        // replacing an earlier manual reading is a legitimate edit.
        if ($currentSource->priority() > $incomingSource->priority()) {
            return null;
        }

        return ['kwh' => $incomingKwh, 'source' => $incomingSource];
    }

    /**
     * Derive kWh from the SOC delta and the vehicle's battery capacity.
     *
     * The weakest source in FR-009, and only usable when both the SOC readings
     * and a battery capacity are known. Returns null rather than guessing:
     * an invented energy figure would flow straight into cost/kWh.
     */
    public function estimateEnergyFromSoc(ChargingSession $session): ?string
    {
        $before = $session->soc_before;
        $after = $session->soc_after;
        $capacity = $session->vehicle?->battery_kwh;

        if ($before === null || $after === null || $capacity === null) {
            return null;
        }

        $delta = Money::of($after)->subtract(Money::of($before));

        // A non-positive delta means the readings are wrong or nothing was
        // added; either way there is no energy figure to derive.
        if ($delta->isNegative() || $delta->isZero()) {
            return null;
        }

        // capacity * (delta / 100)
        return Money::of($capacity)->multiply($delta->divide(100)->amount)->toScale(3);
    }

    /**
     * Assemble the money on a session from its component amounts.
     *
     * `total` is the authoritative charged figure when supplied -- a receipt's
     * own total wins even if it differs from the sum by a satang, because the
     * amount actually billed is the fact (docs/10 rule 6). Otherwise the total
     * is computed from the parts.
     *
     * @param  array<string, string|null>  $amounts  subtotal, service_fee, parking_fee, discount, vat, total
     * @return array{subtotal: string, discount_amount: string, vat_amount: string, total_amount: string}
     */
    public function composeTotals(array $amounts): array
    {
        $subtotal = Money::ofNullable($amounts['subtotal'] ?? null) ?? Money::zero();
        $serviceFee = Money::ofNullable($amounts['service_fee'] ?? null) ?? Money::zero();
        $parkingFee = Money::ofNullable($amounts['parking_fee'] ?? null) ?? Money::zero();
        $discount = Money::ofNullable($amounts['discount'] ?? null) ?? Money::zero();
        $vat = Money::ofNullable($amounts['vat'] ?? null) ?? Money::zero();

        // Discounts are recorded as a positive amount by callers and applied as
        // a reduction here, so a caller cannot flip the sign by accident.
        $computed = $subtotal
            ->add($serviceFee)
            ->add($parkingFee)
            ->subtract($discount->isNegative() ? $discount->multiply(-1) : $discount)
            ->add($vat);

        $total = Money::ofNullable($amounts['total'] ?? null) ?? $computed;

        return [
            'subtotal' => $subtotal->add($serviceFee)->add($parkingFee)->toScale(),
            'discount_amount' => $discount->isNegative()
                ? $discount->multiply(-1)->toScale()
                : $discount->toScale(),
            'vat_amount' => $vat->toScale(),
            'total_amount' => $total->toScale(),
        ];
    }

    /**
     * Compute a subtotal from energy and a unit price, for manual entry where
     * the user knows the rate rather than the amount.
     *
     * Returns null when either input is missing -- the caller then keeps
     * whatever the user typed instead.
     */
    public function subtotalFromRate(?string $energyKwh, ?string $unitPrice): ?string
    {
        $energy = Money::ofNullable($energyKwh);
        $rate = Money::ofNullable($unitPrice);

        if ($energy === null || $rate === null) {
            return null;
        }

        // Rounded once, at the end (docs/10 rules 4 and 5).
        return $energy->multiply($rate->amount)->toScale();
    }

    /**
     * Freeze the charged breakdown onto the session (AT-006).
     *
     * Replaced wholesale rather than edited, so the stored lines always
     * describe one coherent state.
     *
     * @param  array<string, string|null>  $amounts
     */
    public function writeCostLines(ChargingSession $session, array $amounts): void
    {
        $session->costLines()->delete();

        $lines = [
            [ChargingCostLine::TYPE_ENERGY, $session->energy_kwh, $amounts['unit_price'] ?? null, $amounts['subtotal'] ?? null],
            [ChargingCostLine::TYPE_SERVICE_FEE, null, null, $amounts['service_fee'] ?? null],
            [ChargingCostLine::TYPE_PARKING_FEE, null, null, $amounts['parking_fee'] ?? null],
            [ChargingCostLine::TYPE_DISCOUNT, null, null, $this->negated($amounts['discount'] ?? null)],
            [ChargingCostLine::TYPE_VAT, null, null, $amounts['vat'] ?? null],
        ];

        foreach ($lines as [$type, $quantity, $unitPrice, $amount]) {
            // A zero is a real charged value; only an absent one is skipped.
            if ($amount === null || $amount === '') {
                continue;
            }

            $session->costLines()->create([
                'line_type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
            ]);
        }
    }

    /**
     * Derived metrics for one session (docs/06).
     *
     * Distance falls back to the odometer delta when it was not recorded
     * directly, and stays null when neither is available.
     */
    public function metricsFor(ChargingSession $session): SessionMetrics
    {
        return SessionMetrics::calculate(
            $session->total_amount,
            $session->energy_kwh,
            $session->resolvedDistanceKm(),
        );
    }

    /**
     * Discounts are stored negative so the lines sum to the subtotal without
     * special-casing by type.
     */
    private function negated(?string $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $money = Money::of($amount);

        return ($money->isNegative() ? $money : $money->multiply(-1))->toScale();
    }
}
