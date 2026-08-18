<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-safe money (docs/10 rules 4 and 5).
 *
 * Every amount is held as a fixed-scale decimal string and every operation goes
 * through bcmath, so no value ever passes through a binary float. That matters
 * because `0.1 + 0.2 !== 0.3` in floating point: a total assembled from a dozen
 * receipt lines would drift by satang, and the drift lands in a DECIMAL(12,2)
 * column that is supposed to be exact.
 *
 * Rounding happens once, when a result is materialised -- never on intermediate
 * steps, where repeated rounding compounds the error it is meant to prevent.
 */
final readonly class Money
{
    /** Money columns are DECIMAL(12,2) (docs/10 rule 5). */
    public const SCALE = 2;

    /**
     * Working scale for intermediate arithmetic.
     *
     * Wider than SCALE so that, for example, unit_price (4dp) times energy
     * (3dp) keeps its precision until the final rounding step.
     */
    private const CALC_SCALE = 8;

    private function __construct(public string $amount) {}

    public static function of(int|float|string $value): self
    {
        if (is_float($value)) {
            // Accepted for ergonomics at the boundary (validated request input
            // arrives as float), but converted immediately and never used for
            // arithmetic.
            $value = sprintf('%.'.self::CALC_SCALE.'F', $value);
        }

        $value = trim((string) $value);

        if ($value === '') {
            $value = '0';
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Not a numeric amount: {$value}");
        }

        return new self(self::normalise($value));
    }

    public static function zero(): self
    {
        return new self(self::normalise('0'));
    }

    /**
     * Build from a value that may be absent.
     *
     * Returns null rather than zero for null input: an unknown amount and a
     * zero amount are different facts, and conflating them is how a missing
     * figure becomes a confident 0.00 on a financial record.
     */
    public static function ofNullable(int|float|string|null $value): ?self
    {
        return $value === null || $value === '' ? null : self::of($value);
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::CALC_SCALE));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::CALC_SCALE));
    }

    public function multiply(int|float|string $factor): self
    {
        return new self(bcmul($this->amount, self::of($factor)->amount, self::CALC_SCALE));
    }

    /**
     * Divide, or null when the divisor is zero.
     *
     * docs/06 forbids calculating a metric when the denominator is zero or
     * null, so this returns null instead of throwing: "cannot be computed" is
     * an ordinary outcome here, not an error.
     */
    public function divide(int|float|string $divisor): ?self
    {
        $divisorAmount = self::of($divisor)->amount;

        if (self::isZeroAmount($divisorAmount)) {
            return null;
        }

        return new self(bcdiv($this->amount, $divisorAmount, self::CALC_SCALE));
    }

    public function isZero(): bool
    {
        return self::isZeroAmount($this->amount);
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::CALC_SCALE) === -1;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::CALC_SCALE) === 0;
    }

    /**
     * Absolute difference, for tolerance checks against a receipt total.
     */
    public function differenceFrom(self $other): self
    {
        $difference = bcsub($this->amount, $other->amount, self::CALC_SCALE);

        return new self(ltrim($difference, '-'));
    }

    /**
     * Round to money scale. This is the only place a value loses precision, and
     * it is called when persisting or displaying, not between steps.
     *
     * Half-up: the convention on Thai receipts, and the behaviour MySQL applies
     * when writing to a DECIMAL column, so stored and computed values agree.
     */
    public function toScale(int $scale = self::SCALE): string
    {
        $negative = $this->isNegative();
        $value = ltrim($this->amount, '-');

        // bcadd truncates rather than rounding, so add half a unit first.
        $half = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($value, $half, $scale);

        return ($negative && ! self::isZeroAmount($rounded) ? '-' : '').$rounded;
    }

    public function __toString(): string
    {
        return $this->toScale();
    }

    /**
     * Sum a set of amounts. An empty set is zero, not null: nothing charged is
     * a known total of zero.
     *
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->add($amount);
        }

        return $total;
    }

    private static function normalise(string $value): string
    {
        // bcadd with 0 both validates the scale and strips exponent notation
        // that sprintf may have produced.
        return bcadd($value, '0', self::CALC_SCALE);
    }

    private static function isZeroAmount(string $amount): bool
    {
        return bccomp($amount, '0', self::CALC_SCALE) === 0;
    }
}
