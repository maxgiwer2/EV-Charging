<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates an uploaded receipt by its actual bytes (docs/03 -> upload
 * MIME/signature validation, docs/02 FR-004).
 *
 * A client-supplied Content-Type and a file extension are both attacker
 * controlled, so neither is trusted. This rule checks the leading magic bytes
 * against config('receipts.signatures') and requires the detected type to be
 * on the allowlist. That is what stops a PHP script or an executable being
 * stored under a .jpg name and later served or processed as a receipt.
 */
class ValidReceiptFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        if (! $value->isValid()) {
            $fail('The :attribute failed to upload.');

            return;
        }

        /** @var list<string> $allowedMimes */
        $allowedMimes = config('receipts.mime_types');

        // getMimeType() inspects the file's contents (finfo), unlike
        // getClientMimeType() which merely echoes the request header.
        $detectedMime = $value->getMimeType();

        if (! in_array($detectedMime, $allowedMimes, true)) {
            $fail('The :attribute must be a JPG, PNG, WEBP or PDF file.');

            return;
        }

        if (! $this->matchesSignature($value->getRealPath(), $detectedMime)) {
            $fail('The :attribute contents do not match its file type.');
        }
    }

    /**
     * Compare the file's leading bytes with the known signatures for its type.
     */
    private function matchesSignature(string|false $path, string $mime): bool
    {
        if ($path === false) {
            return false;
        }

        /** @var array<string, list<string>> $signatures */
        $signatures = config('receipts.signatures', []);
        $expected = $signatures[$mime] ?? [];

        if ($expected === []) {
            // A MIME type on the allowlist with no signature configured would
            // otherwise pass unchecked; refuse instead of trusting it.
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        // 16 bytes covers every signature in use, including WEBP which needs
        // byte 8 onward.
        $header = (string) fread($handle, 16);
        fclose($handle);

        $hex = bin2hex($header);

        foreach ($expected as $signature) {
            if (str_starts_with($hex, mb_strtolower($signature))) {
                // WEBP starts with the generic RIFF container marker, which is
                // also used by AVI and WAV, so the format tag at byte 8 must be
                // checked too.
                if ($mime === 'image/webp') {
                    return substr($header, 8, 4) === 'WEBP';
                }

                return true;
            }
        }

        return false;
    }
}
