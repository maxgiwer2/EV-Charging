<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnergySource;
use App\Enums\ReceiptStatus;
use App\Enums\SessionStatus;
use App\Exceptions\InvalidReceiptTransition;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Notification;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use App\Models\User;
use App\Support\DuplicateMatch;
use App\Support\Ocr\OcrResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of receipt status (docs/05, FR-005, AT-004).
 *
 * Concentrating every transition here is what makes "OCR never auto-verifies"
 * enforceable rather than merely conventional: there is one code path to
 * VERIFIED, it requires a User, and it is reachable only from an explicit
 * human action. A controller or job able to set the column directly would
 * reduce the rule to a habit.
 */
class ReceiptService
{
    public function __construct(
        private readonly ReceiptStorageService $storage,
        private readonly DuplicateDetectionService $duplicates,
        private readonly AuditLogService $audit,
        private readonly ChargingSessionService $sessions,
    ) {}

    /**
     * Store an uploaded file and create its receipt record (AT-003).
     *
     * Duplicates are flagged, never blocked (AT-005): the same image may
     * legitimately be re-uploaded to correct a mis-keyed session, so the
     * decision belongs to a human.
     */
    public function upload(UploadedFile $file, User $uploader, ?int $chargingSessionId = null): Receipt
    {
        $stored = $this->storage->store($file, $uploader->id);

        return DB::transaction(function () use ($stored, $file, $uploader, $chargingSessionId): Receipt {
            $receipt = new Receipt([
                'charging_session_id' => $chargingSessionId,
            ]);

            // Storage metadata comes from the stored file, never from the
            // request: a client-declared size or type is not evidence.
            $receipt->uploaded_by = $uploader->id;
            $receipt->file_path = $stored['path'];
            $receipt->original_filename = mb_substr($file->getClientOriginalName(), 0, 255);
            $receipt->mime_type = $stored['mime'];
            $receipt->file_size = $stored['size'];
            $receipt->sha256 = $stored['sha256'];
            $receipt->status = ReceiptStatus::OCR_PENDING;
            $receipt->uploaded_at = now();
            $receipt->save();

            $matches = $this->duplicates->detect($receipt);

            if ($matches !== []) {
                $receipt->duplicate_matches = array_map(
                    fn (DuplicateMatch $m): array => $m->toArray(),
                    $matches,
                );
                $receipt->save();

                $this->notifyDuplicate($receipt, $matches);
            }

            $this->audit->logCreate($receipt);

            return $receipt;
        });
    }

    /**
     * Claim a receipt for processing.
     *
     * Returns false when the receipt is not in a state that permits it, which
     * is how a replayed or out-of-order job becomes a no-op rather than an
     * error (docs/03 -> idempotent OCR jobs).
     */
    public function markProcessing(Receipt $receipt): bool
    {
        if (! $receipt->canTransitionTo(ReceiptStatus::OCR_PROCESSING)) {
            return false;
        }

        $this->transition($receipt, ReceiptStatus::OCR_PROCESSING);

        return true;
    }

    /**
     * Persist an OCR run and send the receipt to human review.
     *
     * Always OCR_REVIEW, never VERIFIED, whatever the confidence -- that is
     * FR-005 and AT-004. A failed run also goes to review, because a human can
     * still key the values in from the stored image; discarding it would
     * strand the receipt with nothing to act on.
     */
    public function recordOcrResult(Receipt $receipt, OcrResult $result): ReceiptOcrResult
    {
        return DB::transaction(function () use ($receipt, $result): ReceiptOcrResult {
            // Appended, never updated: the previous attempt stays as evidence
            // (docs/05 -> preserve raw OCR).
            $ocrResult = $receipt->ocrResults()->create([
                'provider' => $result->provider,
                'model' => $result->model,
                'raw_payload' => $result->rawPayload,
                'extracted_data' => $result->toExtractedData(),
                'confidence' => $result->overallConfidence(),
                'status' => $result->status,
                'processed_at' => now(),
            ]);

            // A receipt number read from the image sharpens duplicate
            // detection, so it is promoted onto the receipt. It stays advisory
            // until a human confirms it at review.
            $number = $result->field('receipt_number');

            if ($number->isPresent() && $receipt->receipt_number === null) {
                $receipt->receipt_number = mb_substr((string) $number->value, 0, 150);
                $receipt->save();
            }

            $this->refreshDuplicateFlags($receipt, $result);

            if ($receipt->canTransitionTo(ReceiptStatus::OCR_REVIEW)) {
                $this->transition($receipt, ReceiptStatus::OCR_REVIEW);
                $this->notifyReviewRequired($receipt);
            }

            return $ocrResult;
        });
    }

    /**
     * Confirm a receipt (AT-004) and write its values onto a charging session.
     *
     * $confirmed holds what the human approved, which may differ from what OCR
     * read. It is stored in receipts.verified_data, leaving the OCR result
     * untouched, so a disputed figure can be traced both to what the provider
     * read and to what a person approved (docs/05, README rule 1).
     *
     * @param  array<string, mixed>  $confirmed
     *
     * @throws InvalidReceiptTransition
     */
    public function verify(Receipt $receipt, array $confirmed, User $verifier): Receipt
    {
        if (! $receipt->canTransitionTo(ReceiptStatus::VERIFIED)) {
            throw new InvalidReceiptTransition($receipt->status, ReceiptStatus::VERIFIED);
        }

        return DB::transaction(function () use ($receipt, $confirmed, $verifier): Receipt {
            $session = $this->applyToChargingSession($receipt, $confirmed, $verifier);

            $receipt->verified_data = $confirmed;
            $receipt->verified_by = $verifier->id;
            $receipt->verified_at = now();
            $receipt->charging_session_id = $session->id;

            if (isset($confirmed['receipt_number'])) {
                $receipt->receipt_number = mb_substr((string) $confirmed['receipt_number'], 0, 150);
            }

            $this->transition($receipt, ReceiptStatus::VERIFIED, AuditLog::ACTION_VERIFY, $verifier->id);

            return $receipt->refresh();
        });
    }

    /**
     * Dismiss a receipt: unreadable, wrong, or a confirmed duplicate.
     *
     * @throws InvalidReceiptTransition
     */
    public function reject(Receipt $receipt, User $actor, ?string $reason = null): Receipt
    {
        if (! $receipt->canTransitionTo(ReceiptStatus::REJECTED)) {
            throw new InvalidReceiptTransition($receipt->status, ReceiptStatus::REJECTED);
        }

        return DB::transaction(function () use ($receipt, $actor, $reason): Receipt {
            if ($reason !== null) {
                $receipt->verified_data = ['rejection_reason' => $reason];
            }

            $receipt->verified_by = $actor->id;
            $receipt->verified_at = now();

            $this->transition($receipt, ReceiptStatus::REJECTED, AuditLog::ACTION_REJECT, $actor->id);

            return $receipt->refresh();
        });
    }

    /**
     * Create or update the charging session a verified receipt describes
     * (docs/04 -> Confirm -> create/update charging session).
     *
     * energy_source becomes RECEIPT, the highest precedence in FR-009: a
     * billed figure outranks a manual entry or a SOC estimate.
     *
     * @param  array<string, mixed>  $confirmed
     */
    private function applyToChargingSession(Receipt $receipt, array $confirmed, User $verifier): ChargingSession
    {
        $session = $receipt->chargingSession;

        if ($session === null) {
            $session = new ChargingSession;
            $session->user_id = $receipt->uploaded_by;
            $session->vehicle_id = (int) $confirmed['vehicle_id'];
            $session->charging_type = $confirmed['charging_type'];
            $session->started_at = $confirmed['transaction_date'];
            // Set explicitly rather than relying on the column default: a
            // freshly saved model does not load database defaults into memory,
            // so the status would read as null when confirm() inspects it.
            $session->status = SessionStatus::DRAFT;
        }

        $before = $session->exists ? $session->getOriginal() : null;

        $session->station_id = $confirmed['station_id'] ?? $session->station_id;
        $session->save();

        // Money and energy go through the cost engine, which applies the FR-009
        // precedence and composes the totals with decimal-safe arithmetic.
        // RECEIPT is the highest precedence, so a billed figure always wins
        // over a manual entry or a SOC estimate.
        $this->sessions->applyAmounts(
            $session,
            [
                'energy_kwh' => $this->stringOrNull($confirmed['energy_kwh'] ?? null),
                'unit_price' => $this->stringOrNull($confirmed['unit_price'] ?? null),
                'subtotal' => $this->stringOrNull($confirmed['subtotal'] ?? null),
                'service_fee' => $this->stringOrNull($confirmed['service_fee'] ?? null),
                'parking_fee' => $this->stringOrNull($confirmed['parking_fee'] ?? null),
                'discount' => $this->stringOrNull($confirmed['discount'] ?? null),
                'vat' => $this->stringOrNull($confirmed['vat'] ?? null),
                // The receipt's own total is authoritative even if it differs
                // from the sum by a satang: the amount actually billed is the
                // fact (docs/10 rule 6).
                'total' => $this->stringOrNull($confirmed['total'] ?? null),
            ],
            EnergySource::RECEIPT,
        );

        // A verified receipt is confirmed financial fact, so the session now
        // counts toward dashboard totals (AT-009).
        $this->sessions->confirm($session, $verifier);

        $before === null
            ? $this->audit->logCreate($session, $verifier->id)
            : $this->audit->logUpdate($session, $before, $verifier->id);

        return $session;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Re-run duplicate detection now that OCR has produced comparable values.
     */
    private function refreshDuplicateFlags(Receipt $receipt, OcrResult $result): void
    {
        $matches = $this->duplicates->detect($receipt, $result->toExtractedData());

        if ($matches === []) {
            return;
        }

        $receipt->duplicate_matches = array_map(
            fn (DuplicateMatch $m): array => $m->toArray(),
            $matches,
        );
        $receipt->save();

        $this->notifyDuplicate($receipt, $matches);
    }

    /**
     * Apply a status change and record it (AT-010).
     *
     * @throws InvalidReceiptTransition
     */
    private function transition(
        Receipt $receipt,
        ReceiptStatus $to,
        string $action = AuditLog::ACTION_UPDATE,
        ?int $actorId = null,
    ): void {
        if (! $receipt->canTransitionTo($to)) {
            throw new InvalidReceiptTransition($receipt->status, $to);
        }

        $before = $receipt->getOriginal();
        $receipt->status = $to;
        $receipt->save();

        $action === AuditLog::ACTION_UPDATE
            ? $this->audit->logUpdate($receipt, $before, $actorId)
            : $this->audit->log($action, $receipt, $before, $receipt->getChanges(), $actorId);
    }

    /**
     * @param  list<DuplicateMatch>  $matches
     */
    private function notifyDuplicate(Receipt $receipt, array $matches): void
    {
        Notification::create([
            'user_id' => $receipt->uploaded_by,
            'type' => Notification::TYPE_DUPLICATE_RECEIPT,
            'title' => 'Possible duplicate receipt',
            'body' => 'An uploaded receipt looks like one already on file. Review before confirming.',
            'context' => [
                'receipt_id' => $receipt->id,
                'matches' => array_map(fn (DuplicateMatch $m): array => $m->toArray(), $matches),
            ],
        ]);
    }

    private function notifyReviewRequired(Receipt $receipt): void
    {
        Notification::create([
            'user_id' => $receipt->uploaded_by,
            'type' => Notification::TYPE_OCR_REVIEW,
            'title' => 'Receipt ready for review',
            'body' => 'Extracted values need to be checked and confirmed.',
            'context' => ['receipt_id' => $receipt->id],
        ]);
    }
}
