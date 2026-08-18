<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Receipt;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes and reads receipt files on the private disk
 * (docs/02 FR-004, docs/03, AT-003).
 *
 * Every path decision lives here so no caller can accidentally place a
 * receipt somewhere web-reachable.
 */
class ReceiptStorageService
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('receipts.disk'));
    }

    /**
     * Store an uploaded receipt and return its path plus content hash.
     *
     * The stored filename is a random ULID, never the client's filename: the
     * original name is attacker-controlled and could contain path traversal
     * or a second extension. It is kept in the database column instead, purely
     * for display.
     *
     * Files are foldered by owner and year/month, which keeps directory sizes
     * manageable and makes per-user retention or export straightforward later.
     *
     * @return array{path: string, sha256: string, size: int, mime: string}
     */
    public function store(UploadedFile $file, int $userId): array
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException('Uploaded receipt could not be read.');
        }

        // Hash the file as uploaded, before it is moved. This is the value
        // duplicate detection keys on (docs/05), so it must describe exactly
        // the bytes that get stored.
        $hash = hash_file('sha256', $realPath);

        if ($hash === false) {
            throw new RuntimeException('Uploaded receipt could not be hashed.');
        }

        $directory = sprintf('%d/%s', $userId, now()->format('Y/m'));
        $filename = Str::ulid()->toString().'.'.$this->extensionFor($file);

        $path = $this->disk()->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new RuntimeException('Receipt could not be written to private storage.');
        }

        return [
            'path' => $path,
            'sha256' => $hash,
            'size' => $file->getSize() ?: 0,
            'mime' => (string) $file->getMimeType(),
        ];
    }

    /**
     * Read a receipt's bytes, for the OCR job or an authorized download.
     *
     * Callers must have run the ReceiptPolicy first -- this method performs no
     * authorization of its own (AT-007).
     */
    public function contents(Receipt $receipt): string
    {
        return (string) $this->disk()->get($receipt->file_path);
    }

    public function exists(Receipt $receipt): bool
    {
        return $this->disk()->exists($receipt->file_path);
    }

    /**
     * Derive the stored extension from the detected MIME type rather than the
     * client's filename, so the name on disk always matches the real content.
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            // Unreachable while ValidReceiptFile gates the upload; kept so a
            // future caller cannot produce an extensionless file.
            default => 'bin',
        };
    }
}
