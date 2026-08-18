<?php

declare(strict_types=1);

use App\Contracts\OcrProviderInterface;
use App\Enums\OcrResultStatus;
use App\Services\Ocr\OcrProviderManager;
use App\Services\Ocr\ReceiptParserService;
use App\Services\Ocr\TyphoonOcrProvider;
use Illuminate\Support\Facades\Http;

/*
 * Typhoon OCR adapter. The API is exercised through Http::fake, so the suite
 * never makes a network call or spends credit.
 */

/** A Typhoon-shaped chat completion carrying transcribed Markdown. */
function typhoonResponse(string $markdown): array
{
    return [
        'model' => 'typhoon-ocr',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $markdown]]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
    ];
}

/** A realistic Thai charging receipt as Typhoon would transcribe it. */
function thaiReceiptMarkdown(): string
{
    return <<<'MD'
        # PEA VOLTA สถานีชาร์จ
        เลขที่ใบเสร็จ INV-2026-00123
        สถานี Demo Station (Bangkok)
        วันที่ 18/08/2569  เวลา 14:35
        หัวชาร์จ CCS2

        | รายการ | จำนวน |
        | หน่วยไฟฟ้า | 42.500 kWh |
        | ราคาต่อหน่วย | 7.50 |
        | ยอดก่อนภาษี | 318.75 |
        | ภาษีมูลค่าเพิ่ม 7% | 22.31 |
        | ยอดรวมทั้งสิ้น | 341.06 |

        ชำระโดย บัตรเครดิต
        MD;
}

beforeEach(function (): void {
    config()->set('ocr.driver', 'typhoon');
    config()->set('ocr.typhoon.api_key', 'test-key');
});

it('is selectable as a driver', function (): void {
    expect(app(OcrProviderManager::class)->driver())->toBeInstanceOf(TyphoonOcrProvider::class);
});

it('calls the OpenAI-compatible endpoint with a bearer token', function (): void {
    Http::fake(['*' => Http::response(typhoonResponse('total 100.00'))]);

    app(OcrProviderManager::class)->driver()->extract('imagebytes', 'image/jpeg');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.opentyphoon.ai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'typhoon-ocr'
            // Deterministic transcription: creativity is the last thing wanted
            // when reading an amount off paper.
            && $request['temperature'] === 0.0;
    });
});

it('sends the image as a base64 data URI, never a fetchable URL', function (): void {
    // Receipts are private; exposing one at an address a third party could
    // fetch would defeat the private disk entirely (docs/03).
    Http::fake(['*' => Http::response(typhoonResponse('total 100.00'))]);

    app(OcrProviderManager::class)->driver()->extract('imagebytes', 'image/jpeg');

    Http::assertSent(function ($request): bool {
        $url = $request['messages'][0]['content'][1]['image_url']['url'];

        return str_starts_with($url, 'data:image/jpeg;base64,')
            && base64_decode(substr($url, strlen('data:image/jpeg;base64,')), true) === 'imagebytes';
    });
});

it('extracts the fields from a Thai receipt', function (): void {
    Http::fake(['*' => Http::response(typhoonResponse(thaiReceiptMarkdown()))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->status)->toBe(OcrResultStatus::SUCCESS)
        ->and($result->field('total')->value)->toBe('341.06')
        ->and($result->field('subtotal')->value)->toBe('318.75')
        ->and($result->field('vat')->value)->toBe('22.31')
        ->and($result->field('energy_kwh')->value)->toBe('42.500')
        ->and($result->field('unit_price')->value)->toBe('7.50')
        ->and($result->field('receipt_number')->value)->toBe('INV-2026-00123');
});

it('converts a Buddhist-era year', function (): void {
    // 2569 BE is 2026 CE. Storing 2569 would put the charge 543 years ahead.
    Http::fake(['*' => Http::response(typhoonResponse(thaiReceiptMarkdown()))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->field('transaction_date')->value)->toBe('2026-08-18')
        ->and($result->field('transaction_time')->value)->toBe('14:35');
});

it('does not mistake a VAT rate for a VAT amount', function (): void {
    // "ภาษีมูลค่าเพิ่ม 7% | 22.31" -- the 7 must not be read as the amount.
    Http::fake(['*' => Http::response(typhoonResponse(thaiReceiptMarkdown()))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->field('vat')->value)->toBe('22.31');
});

it('never reports full confidence on a value read from OCR text', function (): void {
    // A transcribed character can always be wrong, so certainty is not
    // available and claiming it would mislead the reviewer.
    Http::fake(['*' => Http::response(typhoonResponse(thaiReceiptMarkdown()))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    foreach ($result->fields as $name => $field) {
        expect($field->confidence)->toBeLessThan(1.0, "field {$name}");
    }
});

it('omits a field it could not read rather than defaulting it to zero', function (): void {
    // docs/05: never invent a missing financial value. A zero total would be
    // recorded as a real charge of nothing.
    Http::fake(['*' => Http::response(typhoonResponse("สถานี Somewhere\nวันที่ 2026-08-18"))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->fields)->not->toHaveKey('total')
        ->and($result->field('total')->isPresent())->toBeFalse()
        // Readable, but the money is missing, so a human must fill it in.
        ->and($result->status)->toBe(OcrResultStatus::PARTIAL);
});

it('marks an arithmetically recovered subtotal as inferred', function (): void {
    // total - vat is arithmetic on values actually read, not a guess, but the
    // reviewer should still know it was not printed.
    Http::fake(['*' => Http::response(typhoonResponse("ยอดรวมทั้งสิ้น 341.06\nภาษีมูลค่าเพิ่ม 22.31"))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->field('subtotal')->value)->toBe('318.75')
        ->and($result->field('subtotal')->confidence)
        ->toBeLessThan($result->field('total')->confidence);
});

it('preserves the raw transcription (docs/05)', function (): void {
    Http::fake(['*' => Http::response(typhoonResponse(thaiReceiptMarkdown()))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->rawText)->toContain('ยอดรวมทั้งสิ้น')
        ->and($result->rawPayload['content'])->toContain('PEA VOLTA')
        // Usage is what the call is billed on.
        ->and($result->rawPayload['usage'])->toBeArray();
});

it('returns a failed result instead of throwing when the API errors', function (): void {
    // The receipt must still reach a human rather than the job retrying
    // forever (OcrProviderInterface contract).
    Http::fake(['*' => Http::response(['error' => 'rate limited'], 429)]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->status)->toBe(OcrResultStatus::FAILED)
        ->and($result->rawPayload['error'])->toBe('http_429');
});

it('fails cleanly when no API key is configured', function (): void {
    config()->set('ocr.typhoon.api_key', '');
    Http::fake();

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->status)->toBe(OcrResultStatus::FAILED)
        ->and($result->rawPayload['error'])->toBe('missing_api_key');

    // No call should have been attempted.
    Http::assertNothingSent();
});

it('fails cleanly on an empty transcription', function (): void {
    Http::fake(['*' => Http::response(typhoonResponse('   '))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->status)->toBe(OcrResultStatus::FAILED)
        ->and($result->rawPayload['error'])->toBe('empty_response');
});

it('never sends the API key anywhere but the Authorization header', function (): void {
    Http::fake(['*' => Http::response(typhoonResponse('total 1.00'))]);

    app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    Http::assertSent(fn ($request): bool => ! str_contains(json_encode($request->data()) ?: '', 'test-key'));
});

it('parses an English receipt too', function (): void {
    // Networks operating in Thailand issue both.
    $english = <<<'MD'
        EA Anywhere
        Receipt No: EA-99887
        Station: Central Rama 9
        Date: 2026-08-18 09:12
        Energy: 30.000 kWh
        Unit price: 7.00
        Subtotal: 210.00
        VAT: 14.70
        Grand Total: 224.70
        Payment method: QR PromptPay
        MD;

    Http::fake(['*' => Http::response(typhoonResponse($english))]);

    $result = app(OcrProviderManager::class)->driver()->extract('bytes', 'image/jpeg');

    expect($result->field('total')->value)->toBe('224.70')
        ->and($result->field('energy_kwh')->value)->toBe('30.000')
        ->and($result->field('receipt_number')->value)->toBe('EA-99887');
});

it('parses text directly through the parser service', function (): void {
    // The parser is deterministic and usable without the network.
    $fields = app(ReceiptParserService::class)->parse('รวมทั้งสิ้น 1,234.56');

    // Thousands separators are stripped so the value is numeric.
    expect($fields['total']->value)->toBe('1234.56');
});

it('still satisfies the provider contract', function (): void {
    expect(app(OcrProviderManager::class)->driver())->toBeInstanceOf(OcrProviderInterface::class)
        ->and(app(OcrProviderManager::class)->driver()->name())->toBe('typhoon');
});
