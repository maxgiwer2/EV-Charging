<?php

declare(strict_types=1);

use App\Support\SessionMetrics;

/*
 * docs/06 derived metrics, and above all its closing rule:
 * "Do not calculate metrics when denominator is zero/null."
 */

it('computes every metric from the formulas in docs/06', function (): void {
    // 341.06 THB, 42.5 kWh, 250 km
    $m = SessionMetrics::calculate('341.06', '42.500', '250.0');

    expect($m->costPerKwh)->toBe('8.0249')     // 341.06 / 42.5
        ->and($m->costPerKm)->toBe('1.3642')   // 341.06 / 250
        ->and($m->kwhPer100Km)->toBe('17.0000') // 42.5 / 250 * 100
        ->and($m->kmPerKwh)->toBe('5.8824')    // 250 / 42.5
        ->and($m->costPer100Km)->toBe('136.4240'); // 341.06 / 250 * 100
});

it('returns null, never zero, when distance is missing', function (): void {
    // A zero here would read as free driving and silently corrupt every
    // average built on top of it.
    $m = SessionMetrics::calculate('341.06', '42.500', null);

    expect($m->costPerKm)->toBeNull()
        ->and($m->kwhPer100Km)->toBeNull()
        ->and($m->kmPerKwh)->toBeNull()
        ->and($m->costPer100Km)->toBeNull()
        // The metric that does not depend on distance still works.
        ->and($m->costPerKwh)->toBe('8.0249');
});

it('returns null when distance is zero', function (): void {
    // Charging without moving: a real scenario, and division by zero.
    $m = SessionMetrics::calculate('341.06', '42.500', '0');

    expect($m->costPerKm)->toBeNull()
        ->and($m->kwhPer100Km)->toBeNull()
        ->and($m->costPer100Km)->toBeNull();
});

it('returns null when energy is zero or missing', function (): void {
    $zeroEnergy = SessionMetrics::calculate('341.06', '0', '250');
    $noEnergy = SessionMetrics::calculate('341.06', null, '250');

    expect($zeroEnergy->costPerKwh)->toBeNull()
        ->and($zeroEnergy->kmPerKwh)->toBeNull()
        ->and($noEnergy->costPerKwh)->toBeNull()
        ->and($noEnergy->kmPerKwh)->toBeNull();
});

it('still reports efficiency for a free charge', function (): void {
    // FREE charging has a genuine cost of zero. Energy and distance were still
    // real, so the efficiency metrics must be produced -- only the cost ones
    // are legitimately zero.
    $m = SessionMetrics::calculate('0', '42.500', '250');

    expect($m->costPerKwh)->toBe('0.0000')
        ->and($m->costPerKm)->toBe('0.0000')
        ->and($m->kwhPer100Km)->toBe('17.0000')
        ->and($m->kmPerKwh)->toBe('5.8824');
});

it('treats a negative distance as unknown rather than emitting negative efficiency', function (): void {
    // Odometer readings entered the wrong way round.
    $m = SessionMetrics::calculate('341.06', '42.500', '-50');

    expect($m->costPerKm)->toBeNull()
        ->and($m->kmPerKwh)->toBeNull()
        ->and($m->kwhPer100Km)->toBeNull();
});

it('returns all nulls when nothing is known', function (): void {
    $m = SessionMetrics::calculate(null, null, null);

    expect(array_filter($m->toArray(), fn ($v): bool => $v !== null))->toBe([]);
});

it('does not lose precision to floating point', function (): void {
    // 0.1 + 0.2 style drift would show up in the fourth decimal place.
    $m = SessionMetrics::calculate('0.30', '3', '3');

    expect($m->costPerKwh)->toBe('0.1000')
        ->and($m->costPerKm)->toBe('0.1000');
});

it('exposes the docs/06 field names', function (): void {
    // The API contract and the docs must not drift apart.
    expect(array_keys(SessionMetrics::calculate(null, null, null)->toArray()))
        ->toBe(['cost_per_kwh', 'cost_per_km', 'kwh_per_100km', 'km_per_kwh', 'cost_per_100km']);
});
