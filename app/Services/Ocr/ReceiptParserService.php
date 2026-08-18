<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Support\Ocr\ExtractedField;

/**
 * Turns OCR text into the normalised receipt fields of docs/05
 * (architecture/system-architecture.md -> ReceiptParserService).
 *
 * Typhoon OCR returns layout-aware Markdown, not structured data, so the
 * fields have to be recovered from text. That is done deterministically here
 * rather than by asking a model for JSON, for two reasons:
 *
 *  - docs/05 forbids inventing financial values. A regex either matches the
 *    characters on the receipt or it does not; a language model asked for
 *    "the total" will happily produce a plausible number when the line is
 *    unreadable.
 *  - Confidence has to mean something. Here it reflects *how* a value was
 *    found -- an explicitly labelled amount scores higher than one inferred
 *    from position -- and never reaches 1.0, because a value read out of OCR
 *    text is always an inference.
 *
 * Labels cover Thai and English, since Thai charging receipts mix both.
 */
class ReceiptParserService
{
    /**
     * Confidence for a value found next to an unambiguous label
     * ("ยอดรวมทั้งสิ้น", "Grand Total"). High, but never certain: the OCR
     * characters themselves may be wrong.
     */
    private const CONFIDENCE_EXPLICIT_LABEL = 0.88;

    /** A label that is correct but less specific ("รวม", "Total"). */
    private const CONFIDENCE_WEAK_LABEL = 0.72;

    /** Recovered by structure or arithmetic rather than a label. */
    private const CONFIDENCE_INFERRED = 0.55;

    /**
     * Label patterns per field, strongest first. The first match wins and
     * carries the confidence of the group it matched in.
     *
     * @var array<string, array<int, array{patterns: list<string>, confidence: float}>>
     */
    private const LABELS = [
        'total' => [
            [
                'patterns' => ['ยอดรวมทั้งสิ้น', 'รวมทั้งสิ้น', 'ยอดสุทธิ', 'จำนวนเงินรวม', 'grand total', 'net total', 'amount due', 'total amount'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
            [
                'patterns' => ['รวม', 'total', 'ยอดเงิน'],
                'confidence' => self::CONFIDENCE_WEAK_LABEL,
            ],
        ],
        'subtotal' => [
            [
                'patterns' => ['ยอดก่อนภาษี', 'ราคาก่อนภาษี', 'มูลค่าสินค้า', 'subtotal', 'sub total', 'amount before vat'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'vat' => [
            [
                'patterns' => ['ภาษีมูลค่าเพิ่ม', 'vat', 'tax'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
            [
                // Bare Thai "tax" only after the specific forms have been
                // tried: it is a substring of the "amount before VAT" label.
                'patterns' => ['ภาษี'],
                'confidence' => self::CONFIDENCE_WEAK_LABEL,
            ],
        ],
        'service_fee' => [
            [
                'patterns' => ['ค่าบริการ', 'ค่าธรรมเนียม', 'service fee', 'service charge'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'parking_fee' => [
            [
                'patterns' => ['ค่าจอดรถ', 'ค่าที่จอด', 'parking fee', 'parking'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'discount' => [
            [
                'patterns' => ['ส่วนลด', 'discount', 'promotion'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'energy_kwh' => [
            [
                'patterns' => ['หน่วยไฟฟ้า', 'พลังงาน', 'energy', 'kwh delivered', 'total kwh'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'unit_price' => [
            [
                'patterns' => ['ราคาต่อหน่วย', 'อัตราค่าไฟ', 'unit price', 'rate', 'price/kwh', 'baht/kwh'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'receipt_number' => [
            [
                'patterns' => ['เลขที่ใบเสร็จ', 'เลขที่', 'receipt no', 'receipt number', 'invoice no', 'ref no', 'transaction id'],
                'confidence' => self::CONFIDENCE_EXPLICIT_LABEL,
            ],
        ],
        'station' => [
            [
                'patterns' => ['สถานี', 'สถานีชาร์จ', 'station', 'location', 'charging station'],
                'confidence' => self::CONFIDENCE_WEAK_LABEL,
            ],
        ],
        'payment_method' => [
            [
                'patterns' => ['ชำระโดย', 'วิธีชำระเงิน', 'payment method', 'paid by'],
                'confidence' => self::CONFIDENCE_WEAK_LABEL,
            ],
        ],
        'connector' => [
            [
                'patterns' => ['หัวชาร์จ', 'connector', 'plug type'],
                'confidence' => self::CONFIDENCE_WEAK_LABEL,
            ],
        ],
    ];

    /**
     * Wording that disqualifies a line for a field, even when that field's own
     * label appears in it. The Thai label for "amount before VAT" contains the
     * word for "tax", so without this the subtotal would be read as the VAT.
     *
     * @var array<string, list<string>>
     */
    private const EXCLUDE = [
        'vat' => ['ก่อนภาษี', 'before vat', 'excl. vat', 'excluding vat'],
        'total' => ['ยอดก่อนภาษี', 'subtotal', 'sub total'],
    ];

    /**
     * Parse OCR text into normalised fields.
     *
     * Fields that cannot be found are simply absent, never present-with-zero:
     * an unreadable total must not become 0.00 on a financial record.
     *
     * @return array<string, ExtractedField>
     */
    public function parse(string $text): array
    {
        $lines = $this->normaliseLines($text);
        $fields = [];

        foreach (self::LABELS as $field => $groups) {
            $found = $this->findLabelled($lines, $groups, $field);

            if ($found !== null) {
                $fields[$field] = $found;
            }
        }

        $this->addDateTime($lines, $fields);
        $this->addMerchant($lines, $fields);
        $this->inferMissingAmounts($fields);

        return $fields;
    }

    /**
     * Strip Markdown decoration and blank lines, keeping the reading order
     * that gives a label and its value a chance of sharing a line.
     *
     * @return list<string>
     */
    private function normaliseLines(string $text): array
    {
        // Table pipes become spaces so "| Total | 341.06 |" reads as one line.
        $text = str_replace(['|', '*', '#', '`'], ' ', $text);

        $lines = preg_split('/\R/u', $text) ?: [];
        $clean = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');

            if ($line !== '') {
                $clean[] = $line;
            }
        }

        return $clean;
    }

    /**
     * Find a value on or just after a labelled line.
     *
     * @param  list<string>  $lines
     * @param  array<int, array{patterns: list<string>, confidence: float}>  $groups
     */
    private function findLabelled(array $lines, array $groups, string $field): ?ExtractedField
    {
        foreach ($groups as $group) {
            // Patterns outer, lines inner: a specific label must be tried
            // against the whole document before a looser one is considered
            // anywhere in it.
            foreach ($group['patterns'] as $pattern) {
                foreach ($lines as $index => $line) {
                    $lower = mb_strtolower($line);

                    if (! str_contains($lower, mb_strtolower($pattern))) {
                        continue;
                    }

                    if ($this->isExcluded($field, $lower)) {
                        continue;
                    }

                    $value = $this->valueFor($field, $line, $pattern, $lines, $index);

                    if ($value !== null) {
                        return new ExtractedField($value, $group['confidence']);
                    }
                }
            }
        }

        return null;
    }

    private function isExcluded(string $field, string $lowercaseLine): bool
    {
        foreach (self::EXCLUDE[$field] ?? [] as $exclusion) {
            if (str_contains($lowercaseLine, mb_strtolower($exclusion))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the value belonging to a label, first from the same line and
     * then from the line below (receipts often wrap).
     *
     * @param  list<string>  $lines
     */
    private function valueFor(string $field, string $line, string $pattern, array $lines, int $index): ?string
    {
        $numeric = in_array($field, [
            'total', 'subtotal', 'vat', 'service_fee', 'parking_fee',
            'discount', 'energy_kwh', 'unit_price',
        ], true);

        // Take what follows the label, so a number in the label itself
        // ("VAT 7%") is not mistaken for the amount.
        $after = $this->afterLabel($line, $pattern);

        if ($numeric) {
            return $this->firstAmount($after)
                ?? $this->firstAmount($lines[$index + 1] ?? '');
        }

        $text = trim($after);

        if ($text === '' && isset($lines[$index + 1])) {
            $text = trim($lines[$index + 1]);
        }

        // Strip leading separators left over from a table or colon layout.
        $text = trim($text, " :\t-–—");

        return $text === '' ? null : mb_substr($text, 0, 190);
    }

    private function afterLabel(string $line, string $pattern): string
    {
        $position = mb_stripos($line, $pattern);

        if ($position === false) {
            return $line;
        }

        return mb_substr($line, $position + mb_strlen($pattern));
    }

    /**
     * First plausible amount in a fragment.
     *
     * Thousands separators are removed and a trailing "%" is rejected, so a
     * VAT *rate* is never captured as a VAT *amount*.
     */
    private function firstAmount(string $fragment): ?string
    {
        if (preg_match_all('/(-?\d[\d,]*(?:\.\d+)?)\s*%?/u', $fragment, $matches, PREG_SET_ORDER) !== 1
            && $matches === []) {
            return null;
        }

        foreach ($matches as $match) {
            // A percentage is a rate, not an amount.
            if (str_contains($match[0], '%')) {
                continue;
            }

            $value = str_replace(',', '', $match[1]);

            if (is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Transaction date and time.
     *
     * Kept as separate fields per docs/05. Both Thai (dd/mm/yyyy) and ISO
     * layouts appear on real receipts; the Buddhist-era year that Thai
     * receipts often carry is converted, since a 2568 would otherwise be
     * stored as a year far in the future.
     *
     * @param  list<string>  $lines
     * @param  array<string, ExtractedField>  $fields
     */
    private function addDateTime(array $lines, array &$fields): void
    {
        foreach ($lines as $line) {
            if (preg_match('/(\d{4})-(\d{2})-(\d{2})/u', $line, $m) === 1) {
                $fields['transaction_date'] = new ExtractedField(
                    $this->normaliseYear((int) $m[1]).'-'.$m[2].'-'.$m[3],
                    self::CONFIDENCE_EXPLICIT_LABEL,
                );
                break;
            }

            if (preg_match('#(\d{1,2})[/-](\d{1,2})[/-](\d{4})#u', $line, $m) === 1) {
                $fields['transaction_date'] = new ExtractedField(
                    sprintf('%04d-%02d-%02d', $this->normaliseYear((int) $m[3]), (int) $m[2], (int) $m[1]),
                    self::CONFIDENCE_WEAK_LABEL,
                );
                break;
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?\b/u', $line, $m) === 1) {
                $fields['transaction_time'] = new ExtractedField(
                    sprintf('%02d:%02d', (int) $m[1], (int) $m[2]),
                    self::CONFIDENCE_WEAK_LABEL,
                );
                break;
            }
        }
    }

    /**
     * Thai receipts commonly print the Buddhist year (2568 = 2025).
     */
    private function normaliseYear(int $year): int
    {
        return $year > 2400 ? $year - 543 : $year;
    }

    /**
     * The merchant is usually the first substantial line of the receipt.
     * Inferred from position, so it carries the lower confidence.
     *
     * @param  list<string>  $lines
     * @param  array<string, ExtractedField>  $fields
     */
    private function addMerchant(array $lines, array &$fields): void
    {
        foreach ($lines as $line) {
            // Skip lines that are mostly digits: those are headers like a tax
            // id, not a trading name.
            if (mb_strlen($line) < 4 || preg_match('/^[\d\s.,:-]+$/u', $line) === 1) {
                continue;
            }

            $fields['merchant'] = new ExtractedField(mb_substr($line, 0, 190), self::CONFIDENCE_INFERRED);

            return;
        }
    }

    /**
     * Recover a subtotal that was not printed, when the other parts are known.
     *
     * This is arithmetic on values actually read from the receipt, not a
     * guess, so it is allowed under docs/05 -- but it is marked as inferred so
     * a reviewer knows it was not printed on the paper.
     *
     * @param  array<string, ExtractedField>  $fields
     */
    private function inferMissingAmounts(array &$fields): void
    {
        if (isset($fields['subtotal']) || ! isset($fields['total'], $fields['vat'])) {
            return;
        }

        $total = $fields['total']->value;
        $vat = $fields['vat']->value;

        if ($total === null || $vat === null) {
            return;
        }

        $subtotal = bcsub($total, $vat, 2);

        if (bccomp($subtotal, '0', 2) === 1) {
            $fields['subtotal'] = new ExtractedField($subtotal, self::CONFIDENCE_INFERRED);
        }
    }
}
