<?php

declare(strict_types=1);

use App\Support\Money;

/*
 * docs/10 rule 4: financial calculations must be decimal-safe. These tests
 * exist to prove the arithmetic does not go through binary floats.
 */

it('adds without floating point drift', function (): void {
    // The canonical float failure: 0.1 + 0.2 === 0.30000000000000004.
    $result = Money::of('0.1')->add(Money::of('0.2'));

    expect($result->toScale())->toBe('0.30');
});

it('keeps a long chain of additions exact', function (): void {
    // Ten receipt lines of 0.1 must be exactly 1.00, not 0.9999999999999999.
    $total = Money::sum(array_fill(0, 10, Money::of('0.1')));

    expect($total->toScale())->toBe('1.00');
});

it('preserves precision through unit price times energy', function (): void {
    // unit_price is 4dp and energy 3dp; the product must not be rounded until
    // the end (docs/10 rule 5).
    $subtotal = Money::of('7.4567')->multiply('42.500');

    expect($subtotal->toScale())->toBe('316.91');
});

it('rounds half up, matching MySQL DECIMAL writes', function (): void {
    expect(Money::of('0.125')->toScale())->toBe('0.13')
        ->and(Money::of('0.135')->toScale())->toBe('0.14')
        ->and(Money::of('2.005')->toScale())->toBe('2.01');
});

it('rounds negative amounts away from zero', function (): void {
    // Discounts are stored negative; they must not round toward zero and
    // quietly shrink.
    expect(Money::of('-0.125')->toScale())->toBe('-0.13');
});

it('never renders negative zero', function (): void {
    expect(Money::of('-0.001')->toScale())->toBe('0.00');
});

it('returns null when dividing by zero rather than throwing', function (): void {
    // docs/06: a metric with a zero denominator is not computed. That is an
    // ordinary outcome, not an error.
    expect(Money::of('100')->divide(0))->toBeNull()
        ->and(Money::of('100')->divide('0.00'))->toBeNull();
});

it('divides exactly when it can', function (): void {
    expect(Money::of('341.06')->divide('42.5')->toScale(4))->toBe('8.0249');
});

it('distinguishes an absent amount from zero', function (): void {
    // Conflating them is how a missing figure becomes a confident 0.00.
    expect(Money::ofNullable(null))->toBeNull()
        ->and(Money::ofNullable(''))->toBeNull()
        ->and(Money::ofNullable(0)?->toScale())->toBe('0.00');
});

it('computes an absolute difference for tolerance checks', function (): void {
    expect(Money::of('341.06')->differenceFrom(Money::of('341.07'))->toScale())->toBe('0.01')
        ->and(Money::of('341.07')->differenceFrom(Money::of('341.06'))->toScale())->toBe('0.01');
});

it('accepts a float at the boundary without losing the value', function (): void {
    // Validated request input arrives as a float; it must be captured
    // faithfully even though no arithmetic uses floats.
    expect(Money::of(341.06)->toScale())->toBe('341.06')
        ->and(Money::of(0.1)->add(Money::of(0.2))->toScale())->toBe('0.30');
});

it('rejects a non-numeric amount', function (): void {
    expect(fn (): Money => Money::of('abc'))->toThrow(InvalidArgumentException::class);
});

it('treats an empty sum as zero, not null', function (): void {
    // Nothing charged is a known total of zero.
    expect(Money::sum([])->toScale())->toBe('0.00');
});

it('compares by value', function (): void {
    expect(Money::of('10.00')->equals(Money::of('10.000')))->toBeTrue()
        ->and(Money::of('10.00')->equals(Money::of('10.01')))->toBeFalse();
});
