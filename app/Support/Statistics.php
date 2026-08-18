<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decimal-safe descriptive statistics for anomaly detection.
 *
 * Everything works on decimal strings through bcmath, for the same reason the
 * rest of the money handling does (docs/10 rule 4): these figures come from
 * DECIMAL columns and feed decisions a user sees.
 *
 * The methods here are deliberately **robust** ones -- median and median
 * absolute deviation rather than mean and standard deviation. That choice is
 * the whole point of the file: a single unusually expensive charge inflates
 * the standard deviation enough to hide itself, so a mean-based detector gets
 * quieter exactly when there is something to report. The median barely moves.
 */
final class Statistics
{
    /** Working precision, wider than any stored scale. */
    private const SCALE = 8;

    /**
     * Middle value. For an even count, the mean of the two central values.
     *
     * @param  list<string>  $values
     */
    public static function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        usort($values, fn (string $a, string $b): int => bccomp($a, $b, self::SCALE));

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$middle];
        }

        return bcdiv(bcadd($values[$middle - 1], $values[$middle], self::SCALE), '2', self::SCALE);
    }

    /**
     * Median absolute deviation: the median of each value's distance from the
     * median.
     *
     * A spread measure that an outlier cannot inflate, which is what makes it
     * usable as the baseline for detecting outliers.
     *
     * @param  list<string>  $values
     */
    public static function medianAbsoluteDeviation(array $values, ?string $median = null): ?string
    {
        $median ??= self::median($values);

        if ($median === null) {
            return null;
        }

        $deviations = array_map(
            fn (string $value): string => self::absolute(bcsub($value, $median, self::SCALE)),
            $values,
        );

        return self::median($deviations);
    }

    /**
     * Modified z-score: 0.6745 × (value − median) ÷ MAD.
     *
     * The constant scales MAD so the score is comparable to a standard z-score
     * for normally distributed data, which is why the conventional threshold
     * of 3.5 is meaningful.
     *
     * Returns null when MAD is zero -- every observation identical, so
     * "how unusual is this" has no answer. Callers must treat that as
     * "cannot tell", never as "not unusual".
     */
    public static function modifiedZScore(string $value, string $median, string $mad): ?string
    {
        if (bccomp($mad, '0', self::SCALE) === 0) {
            return null;
        }

        $numerator = bcmul('0.6745', bcsub($value, $median, self::SCALE), self::SCALE);

        return bcdiv($numerator, $mad, self::SCALE);
    }

    /**
     * Arithmetic mean. Used for reporting, never for outlier thresholds.
     *
     * @param  list<string>  $values
     */
    public static function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $sum = '0';

        foreach ($values as $value) {
            $sum = bcadd($sum, $value, self::SCALE);
        }

        return bcdiv($sum, (string) count($values), self::SCALE);
    }

    public static function absolute(string $value): string
    {
        return bccomp($value, '0', self::SCALE) === -1
            ? bcmul($value, '-1', self::SCALE)
            : $value;
    }
}
