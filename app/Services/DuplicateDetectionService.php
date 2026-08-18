<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Support\DuplicateMatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Flags probable duplicate receipts (docs/05 -> Duplicate Detection, AT-005).
 *
 * Deliberately advisory. The database does not enforce a unique hash: a unique
 * index would make the second upload fail with an integrity error, whereas
 * AT-005 requires the system to flag a probable duplicate and let a human
 * decide. Re-uploading the same image to correct a mis-keyed session is a
 * legitimate action, so nothing here blocks an upload.
 */
class DuplicateDetectionService
{
    /**
     * How close in time two transactions must be to count as the same one.
     * Wide enough to absorb clock skew between a charger and a phone, narrow
     * enough that two genuine charges at one station are not merged.
     */
    private const TRANSACTION_WINDOW_MINUTES = 90;

    /**
     * Find receipts that look like $receipt, most likely first.
     *
     * Scoped to the receipt owner: receipts belonging to different users are
     * never compared, both because such a duplicate is meaningless and because
     * surfacing one would reveal that the other exists (AT-007).
     *
     * @param  array<string, mixed>  $extracted  optional parsed fields, when known
     * @return list<DuplicateMatch>
     */
    public function detect(Receipt $receipt, array $extracted = []): array
    {
        $matches = [];

        foreach ($this->byFileHash($receipt) as $candidate) {
            // Byte-identical: as certain as this gets without a human.
            $matches[$candidate->id] = new DuplicateMatch(
                $candidate,
                [DuplicateMatch::REASON_IDENTICAL_FILE],
                1.0,
            );
        }

        foreach ($this->byReceiptNumber($receipt) as $candidate) {
            $matches[$candidate->id] = $this->merge(
                $matches[$candidate->id] ?? null,
                $candidate,
                DuplicateMatch::REASON_RECEIPT_NUMBER,
                0.9,
            );
        }

        foreach ($this->bySimilarTransaction($receipt, $extracted) as $candidate) {
            $matches[$candidate->id] = $this->merge(
                $matches[$candidate->id] ?? null,
                $candidate,
                DuplicateMatch::REASON_SIMILAR_TRANSACTION,
                0.7,
            );
        }

        $result = array_values($matches);

        usort($result, fn (DuplicateMatch $a, DuplicateMatch $b): int => $b->score <=> $a->score);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    public function hasDuplicates(Receipt $receipt, array $extracted = []): bool
    {
        return $this->detect($receipt, $extracted) !== [];
    }

    /**
     * Exact content match: the strongest signal available.
     *
     * @return Collection<int, Receipt>
     */
    private function byFileHash(Receipt $receipt): Collection
    {
        return $this->baseQuery($receipt)
            ->where('sha256', $receipt->sha256)
            ->get();
    }

    /**
     * @return Collection<int, Receipt>
     */
    private function byReceiptNumber(Receipt $receipt): Collection
    {
        if ($receipt->receipt_number === null || $receipt->receipt_number === '') {
            return collect();
        }

        return $this->baseQuery($receipt)
            ->where('receipt_number', $receipt->receipt_number)
            ->get();
    }

    /**
     * Same station, same money and energy, close in time.
     *
     * Compared against linked charging sessions, because that is where the
     * confirmed transaction values live. Requires both a total and an energy
     * figure: matching on station and timestamp alone would flag every second
     * charge at a driver regular station.
     *
     * @param  array<string, mixed>  $extracted
     * @return Collection<int, Receipt>
     */
    private function bySimilarTransaction(Receipt $receipt, array $extracted): Collection
    {
        $total = $this->numeric($extracted['total'] ?? null);
        $energy = $this->numeric($extracted['energy_kwh'] ?? null);
        // Falls back to the upload time when OCR could not read a
        // transaction date; uploaded_at is never null.
        $occurredAt = $this->date($extracted['transaction_date'] ?? null) ?? $receipt->uploaded_at;

        if ($total === null || $energy === null) {
            return collect();
        }

        return $this->baseQuery($receipt)
            ->whereHas('chargingSession', function (Builder $query) use ($total, $energy, $occurredAt): void {
                // Compared as DECIMAL by MySQL, so the comparison itself
                // introduces no float rounding.
                $query->where('total_amount', $total)
                    ->where('energy_kwh', $energy)
                    ->whereBetween('started_at', [
                        $occurredAt->copy()->subMinutes(self::TRANSACTION_WINDOW_MINUTES),
                        $occurredAt->copy()->addMinutes(self::TRANSACTION_WINDOW_MINUTES),
                    ]);
            })
            ->get();
    }

    /**
     * Candidates: same owner, not this receipt, not already rejected.
     *
     * A rejected receipt is one a human already dismissed, so re-flagging it
     * would reproduce noise the reviewer has already dealt with.
     *
     * @return Builder<Receipt>
     */
    private function baseQuery(Receipt $receipt): Builder
    {
        return Receipt::query()
            ->where('uploaded_by', $receipt->uploaded_by)
            ->whereKeyNot($receipt->getKey())
            ->where('status', '!=', ReceiptStatus::REJECTED);
    }

    private function merge(?DuplicateMatch $existing, Receipt $candidate, string $reason, float $score): DuplicateMatch
    {
        if ($existing === null) {
            return new DuplicateMatch($candidate, [$reason], $score);
        }

        // Several independent signals agreeing is stronger than any one alone,
        // but certainty stays capped at 1.0.
        return new DuplicateMatch(
            $existing->receipt,
            [...$existing->reasons, $reason],
            min(1.0, max($existing->score, $score) + 0.05),
        );
    }

    private function numeric(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }

    private function date(mixed $value): ?Carbon
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // An unparseable provider date is a reason to skip this heuristic,
            // not to fail the upload.
            return null;
        }
    }
}
