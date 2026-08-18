<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\OcrProviderInterface;
use App\Enums\OcrResultStatus;
use App\Support\Ocr\OcrResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Typhoon OCR adapter (SCB 10X, opentyphoon.ai).
 *
 * Chosen for Thai receipts: it is a Thai-first document VLM, where a general
 * OCR engine tends to mangle Thai glyphs and the mixed Thai/English labels
 * that charging receipts use.
 *
 * The API is OpenAI-compatible (POST /chat/completions with a Bearer token),
 * and the model returns layout-aware **Markdown**, not structured fields. So
 * this adapter does two things and keeps them separate: it obtains the text,
 * then hands it to ReceiptParserService, which recovers the docs/05 fields
 * deterministically. Asking the model for JSON directly would be shorter but
 * would let it produce a plausible total for an unreadable line, which docs/05
 * forbids.
 *
 * Nothing here throws for a provider-side failure: OcrProviderInterface
 * requires a failed result instead, so the receipt still reaches human review.
 */
class TyphoonOcrProvider implements OcrProviderInterface
{
    /**
     * Instruction sent with the image.
     *
     * Deliberately asks for transcription only. Any instruction to "extract
     * the total" would invite the model to infer a number that is not legible,
     * and the extraction step downstream is the part that must stay
     * deterministic.
     */
    private const PROMPT = <<<'TXT'
        Transcribe this receipt exactly as printed, preserving the layout as Markdown.
        Keep every label next to its value on the same line, including Thai text.
        Do not translate, summarise, correct, or add any value that is not visible.
        If part of the receipt is unreadable, leave it out rather than guessing.
        TXT;

    public function __construct(private readonly ReceiptParserService $parser) {}

    public function extract(string $contents, string $mimeType): OcrResult
    {
        $apiKey = (string) config('ocr.typhoon.api_key');

        if ($apiKey === '') {
            // A missing key is a configuration fault, not a bad receipt. It is
            // reported as a failed run so the receipt still reaches a human,
            // and logged so the cause is visible.
            Log::warning('Typhoon OCR is selected but no API key is configured.');

            return OcrResult::failed($this->name(), null, ['error' => 'missing_api_key']);
        }

        try {
            $image = $this->toImage($contents, $mimeType);
        } catch (RuntimeException $e) {
            return OcrResult::failed($this->name(), null, ['error' => $e->getMessage()]);
        }

        $model = (string) config('ocr.typhoon.model');

        $response = Http::withToken($apiKey)
            ->timeout((int) config('ocr.timeout_seconds'))
            ->acceptJson()
            ->post(rtrim((string) config('ocr.typhoon.base_url'), '/').'/chat/completions', [
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        // OpenAI vision format: the image travels as a base64
                        // data URI rather than a URL, because receipts are
                        // private and must never be exposed at a fetchable
                        // address (docs/03).
                        ['type' => 'image_url', 'image_url' => [
                            'url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']),
                        ]],
                    ],
                ]],
                // Deterministic transcription: creativity is exactly what is
                // not wanted when reading an amount off paper.
                'temperature' => 0.0,
                'max_tokens' => (int) config('ocr.typhoon.max_tokens'),
            ]);

        if ($response->failed()) {
            // The status and a short body excerpt are logged; the receipt
            // contents never are (docs/10 rule 13).
            Log::warning('Typhoon OCR request failed', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return OcrResult::failed($this->name(), $model, [
                'error' => 'http_'.$response->status(),
            ]);
        }

        $text = (string) $response->json('choices.0.message.content', '');

        if (trim($text) === '') {
            return OcrResult::failed($this->name(), $model, ['error' => 'empty_response']);
        }

        $fields = $this->parser->parse($text);

        return new OcrResult(
            provider: $this->name(),
            model: $model,
            // PARTIAL when the money that matters was not recovered: the
            // receipt is readable but the reviewer will have to fill the gaps.
            status: isset($fields['total']) ? OcrResultStatus::SUCCESS : OcrResultStatus::PARTIAL,
            fields: $fields,
            // The provider response is preserved verbatim (docs/05). Usage
            // figures are kept because they are what the call is billed on.
            rawPayload: [
                'model' => $response->json('model'),
                'usage' => $response->json('usage'),
                'content' => $text,
            ],
            rawText: $text,
        );
    }

    public function name(): string
    {
        return 'typhoon';
    }

    /**
     * Produce image bytes the model can read.
     *
     * Typhoon accepts PNG and JPEG. A PDF receipt is rasterised first with
     * pdftoppm (poppler-utils, installed in the app image) -- without this,
     * PDF receipts would silently always fail.
     *
     * @return array{bytes: string, mime: string}
     */
    private function toImage(string $contents, string $mimeType): array
    {
        if ($mimeType !== 'application/pdf') {
            // WEBP is accepted by upload but not by the model; convert it.
            if ($mimeType === 'image/webp') {
                return ['bytes' => $this->webpToPng($contents), 'mime' => 'image/png'];
            }

            return ['bytes' => $contents, 'mime' => $mimeType];
        }

        return ['bytes' => $this->pdfToPng($contents), 'mime' => 'image/png'];
    }

    private function pdfToPng(string $pdf): string
    {
        $source = tempnam(sys_get_temp_dir(), 'receipt-').'.pdf';
        file_put_contents($source, $pdf);

        // Only the first page: a charging receipt is one page, and rendering
        // an arbitrarily long PDF would be an easy way to exhaust the worker.
        $prefix = $source.'-page';
        $command = sprintf(
            'pdftoppm -png -r 200 -f 1 -l 1 %s %s 2>&1',
            escapeshellarg($source),
            escapeshellarg($prefix),
        );

        exec($command, $output, $exitCode);

        $rendered = $prefix.'-1.png';

        try {
            if ($exitCode !== 0 || ! is_file($rendered)) {
                throw new RuntimeException('pdf_rasterisation_failed');
            }

            return (string) file_get_contents($rendered);
        } finally {
            @unlink($source);
            @unlink($rendered);
        }
    }

    private function webpToPng(string $webp): string
    {
        $image = @imagecreatefromstring($webp);

        if ($image === false) {
            throw new RuntimeException('webp_decode_failed');
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
