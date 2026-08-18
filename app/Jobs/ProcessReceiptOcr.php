<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Receipt;
use App\Services\Ocr\OcrProviderManager;
use App\Services\ReceiptService;
use App\Services\ReceiptStorageService;
use App\Support\Ocr\OcrResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs OCR for one receipt off the request cycle
 * (docs/03 -> async OCR jobs, architecture -> queue/worker).
 *
 * Idempotent (docs/03): ShouldBeUnique stops a second job for the same receipt
 * being queued, and markProcessing() refuses a receipt that is not in a state
 * to be processed. A replay is therefore a no-op rather than a duplicate OCR
 * result or a rewound status.
 */
class ProcessReceiptOcr implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $receiptId) {}

    /**
     * One in-flight job per receipt.
     */
    public function uniqueId(): string
    {
        return 'receipt-ocr-'.$this->receiptId;
    }

    public function tries(): int
    {
        return (int) config('ocr.max_attempts', 3);
    }

    /**
     * Exponential-ish backoff from config (docs/03 -> retry with backoff).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('ocr.backoff_seconds', [5, 15, 60]);

        return $backoff;
    }

    public function handle(
        ReceiptService $receipts,
        ReceiptStorageService $storage,
        OcrProviderManager $providers,
    ): void {
        $receipt = Receipt::find($this->receiptId);

        if ($receipt === null) {
            // Deleted between dispatch and execution; nothing to do.
            return;
        }

        if (! $receipts->markProcessing($receipt)) {
            // Already processed, already reviewed, or already terminal.
            return;
        }

        $provider = $providers->driver();

        try {
            $result = $provider->extract($storage->contents($receipt), $receipt->mime_type);
        } catch (Throwable $e) {
            // The receipt still needs to reach a human, so a provider failure
            // is recorded as a failed run rather than left to retry forever.
            // The message is logged, never the file contents (docs/10 rule 13).
            Log::warning('OCR provider failed', [
                'receipt_id' => $receipt->id,
                'provider' => $provider->name(),
                'exception' => $e->getMessage(),
            ]);

            $result = OcrResult::failed($provider->name(), null, ['error' => 'provider_exception']);
        }

        $receipts->recordOcrResult($receipt, $result);
    }
}
