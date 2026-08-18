<?php

declare(strict_types=1);

use App\Contracts\OcrProviderInterface;
use App\Enums\OcrResultStatus;
use App\Enums\ReceiptStatus;
use App\Jobs\ProcessReceiptOcr;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use App\Models\User;
use App\Services\Ocr\NoneOcrProvider;
use App\Services\Ocr\OcrProviderManager;
use App\Services\ReceiptService;
use App\Services\ReceiptStorageService;
use App\Support\Ocr\ExtractedField;
use App\Support\Ocr\OcrResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * docs/03 -> async, idempotent OCR jobs with retry/backoff.
 */

/** Store a real file so the job has something to read. */
function receiptWithFile(?User $owner = null): Receipt
{
    Storage::fake(config('receipts.disk'));

    $owner ??= User::factory()->create();
    $path = 'test/receipt.jpg';

    // Held in a variable: the fake file is deleted once the UploadedFile is
    // garbage-collected, so reading it inline can race.
    $source = UploadedFile::fake()->image('r.jpg', 200, 200);

    Storage::disk(config('receipts.disk'))->put(
        $path,
        (string) file_get_contents((string) $source->getRealPath()),
    );

    return Receipt::factory()->create([
        'uploaded_by' => $owner->id,
        'file_path' => $path,
        'status' => ReceiptStatus::OCR_PENDING,
    ]);
}

it('moves a pending receipt to review and stores the result', function (): void {
    $receipt = receiptWithFile();

    (new ProcessReceiptOcr($receipt->id))->handle(
        app(ReceiptService::class),
        app(ReceiptStorageService::class),
        app(OcrProviderManager::class),
    );

    $receipt->refresh();

    expect($receipt->status)->toBe(ReceiptStatus::OCR_REVIEW)
        ->and(ReceiptOcrResult::where('receipt_id', $receipt->id)->count())->toBe(1);
});

it('is a no-op when replayed (docs/03 -> idempotent)', function (): void {
    // A retried or duplicated job must not produce a second OCR result or
    // rewind a receipt that has moved on.
    $receipt = receiptWithFile();

    $run = fn (): mixed => (new ProcessReceiptOcr($receipt->id))->handle(
        app(ReceiptService::class),
        app(ReceiptStorageService::class),
        app(OcrProviderManager::class),
    );

    $run();
    $run();

    expect(ReceiptOcrResult::where('receipt_id', $receipt->id)->count())->toBe(1)
        ->and($receipt->refresh()->status)->toBe(ReceiptStatus::OCR_REVIEW);
});

it('does nothing for a receipt that no longer exists', function (): void {
    // Deleted between dispatch and execution.
    (new ProcessReceiptOcr(999_999))->handle(
        app(ReceiptService::class),
        app(ReceiptStorageService::class),
        app(OcrProviderManager::class),
    );

    expect(ReceiptOcrResult::count())->toBe(0);
});

it('records a failed run instead of retrying forever when the provider throws', function (): void {
    // The receipt must still reach a human, who can key the values in.
    $receipt = receiptWithFile();

    app()->bind(OcrProviderManager::class, function () {
        $provider = new class implements OcrProviderInterface
        {
            public function extract(string $contents, string $mimeType): OcrResult
            {
                throw new RuntimeException('provider exploded');
            }

            public function name(): string
            {
                return 'exploding';
            }
        };

        return new class($provider) extends OcrProviderManager
        {
            public function __construct(private OcrProviderInterface $stub)
            {
                parent::__construct(app());
            }

            public function driver(?string $name = null): OcrProviderInterface
            {
                return $this->stub;
            }
        };
    });

    (new ProcessReceiptOcr($receipt->id))->handle(
        app(ReceiptService::class),
        app(ReceiptStorageService::class),
        app(OcrProviderManager::class),
    );

    $receipt->refresh();

    expect($receipt->status)->toBe(ReceiptStatus::OCR_REVIEW)
        ->and(ReceiptOcrResult::where('receipt_id', $receipt->id)->first()->status)
        ->toBe(OcrResultStatus::FAILED);
});

it('uses the configured retry policy', function (): void {
    $job = new ProcessReceiptOcr(1);

    expect($job->tries())->toBe((int) config('ocr.max_attempts'))
        ->and($job->backoff())->toBe(config('ocr.backoff_seconds'))
        // One in-flight job per receipt.
        ->and($job->uniqueId())->toBe('receipt-ocr-1');
});

it('extracts nothing with the default provider rather than inventing values', function (): void {
    // docs/05 -> never invent missing financial values. An unconfigured system
    // must not put a fabricated zero onto a financial record.
    $result = (new NoneOcrProvider)->extract('bytes', 'image/jpeg');

    expect($result->status)->toBe(OcrResultStatus::FAILED)
        ->and($result->fields)->toBe([])
        ->and($result->overallConfidence())->toBeNull()
        // A missing field reads as absent, never as a confident zero.
        ->and($result->field('total')->isPresent())->toBeFalse()
        ->and($result->field('total')->confidence)->toBe(0.0);
});

it('averages confidence over found fields only', function (): void {
    // A receipt with no parking fee should not look less reliable than one
    // that has it.
    $result = new OcrResult(
        provider: 'test',
        model: null,
        status: OcrResultStatus::PARTIAL,
        fields: [
            'total' => new ExtractedField('100.00', 0.9),
            'energy_kwh' => new ExtractedField('20.000', 0.7),
            'parking_fee' => ExtractedField::missing(),
        ],
    );

    expect($result->overallConfidence())->toBe(0.8);
});

it('flags fields below the review threshold', function (): void {
    $result = new OcrResult(
        provider: 'test',
        model: null,
        status: OcrResultStatus::SUCCESS,
        fields: [
            'total' => new ExtractedField('100.00', 0.99),
            'station' => new ExtractedField('Somewhere', 0.40),
            'vat' => ExtractedField::missing(),
        ],
    );

    $flagged = $result->lowConfidenceFields();

    expect($flagged)->toContain('station')
        // A field that was not read at all always needs a human.
        ->and($flagged)->toContain('vat')
        ->and($flagged)->not->toContain('total');
});

it('refuses a confidence outside 0..1', function (): void {
    // Catches an adapter that forwards a 0..100 scale unmapped, which would
    // otherwise make everything look certain.
    expect(fn (): ExtractedField => new ExtractedField('x', 92.0))
        ->toThrow(InvalidArgumentException::class);
});

it('fails loudly on an unknown OCR driver', function (): void {
    // Silently falling back to `none` would look like "OCR read nothing" and
    // hide a misconfiguration.
    config()->set('ocr.driver', 'does-not-exist');

    expect(fn (): OcrProviderInterface => app(OcrProviderManager::class)->driver())
        ->toThrow(InvalidArgumentException::class);
});
