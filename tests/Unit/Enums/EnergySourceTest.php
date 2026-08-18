<?php

declare(strict_types=1);

use App\Enums\EnergySource;

/*
 * docs/02 FR-009 fixes the precedence:
 * verified receipt / charger reading > manual entry > SOC estimate.
 */

it('ranks a receipt and a charger reading above manual entry', function (): void {
    expect(EnergySource::RECEIPT->outranks(EnergySource::MANUAL))->toBeTrue()
        ->and(EnergySource::CHARGER->outranks(EnergySource::MANUAL))->toBeTrue();
});

it('ranks manual entry above a SOC estimate', function (): void {
    expect(EnergySource::MANUAL->outranks(EnergySource::SOC_ESTIMATE))->toBeTrue();
});

it('does not let a SOC estimate override a measured value', function (): void {
    // The dangerous direction: an estimate must never silently replace a
    // figure that was actually metered or billed.
    expect(EnergySource::SOC_ESTIMATE->outranks(EnergySource::RECEIPT))->toBeFalse()
        ->and(EnergySource::SOC_ESTIMATE->outranks(EnergySource::CHARGER))->toBeFalse()
        ->and(EnergySource::SOC_ESTIMATE->outranks(EnergySource::MANUAL))->toBeFalse();
});

it('treats a receipt and a charger reading as equally authoritative', function (): void {
    // Neither outranks the other, so the newer entry may replace it.
    expect(EnergySource::RECEIPT->outranks(EnergySource::CHARGER))->toBeFalse()
        ->and(EnergySource::CHARGER->outranks(EnergySource::RECEIPT))->toBeFalse();
});

it('marks only the SOC estimate as an estimate', function (): void {
    expect(EnergySource::SOC_ESTIMATE->isEstimate())->toBeTrue()
        ->and(EnergySource::RECEIPT->isEstimate())->toBeFalse()
        ->and(EnergySource::CHARGER->isEstimate())->toBeFalse()
        ->and(EnergySource::MANUAL->isEstimate())->toBeFalse();
});
