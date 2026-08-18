<?php

declare(strict_types=1);

/*
 * These tests guard the security invariants from docs/03 and AT-007 at the
 * configuration level. They exist so a future change to config/filesystems.php
 * that would expose private receipt files fails CI instead of shipping.
 */

it('stores receipts on a private disk with no public URL', function (): void {
    $disk = config('receipts.disk');

    expect($disk)->not->toBe('public');

    $config = config("filesystems.disks.{$disk}");

    expect($config)->toBeArray()
        ->and($config['visibility'] ?? null)->toBe('private')
        // No `url` key: nothing may hand out a direct link to a receipt file.
        ->and($config)->not->toHaveKey('url')
        // `serve` would expose files by path with no ownership check; receipts
        // are streamed through a controller that runs the ReceiptPolicy.
        ->and($config['serve'] ?? false)->toBeFalse();
});

it('keeps the receipt storage root outside the public directory', function (): void {
    $root = config('filesystems.disks.'.config('receipts.disk').'.root');

    expect($root)->toBeString()
        ->and(str_starts_with($root, public_path()))->toBeFalse();
});

it('only accepts the receipt formats permitted by FR-004', function (): void {
    // docs/02 FR-004: JPG/JPEG/PNG/WEBP/PDF only.
    expect(config('receipts.mime_types'))->toEqualCanonicalizing([
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ]);

    // Every accepted MIME type must have a magic-byte signature to check
    // against, so a renamed executable cannot pass as a receipt.
    foreach (config('receipts.mime_types') as $mime) {
        $signatures = config("receipts.signatures.{$mime}");

        expect($signatures)->toBeArray();
        expect(count($signatures))->toBeGreaterThan(0);
    }
});

it('defaults OCR to a driver that makes no network calls', function (): void {
    // Must be a non-empty driver name. A blank value would mean no adapter is
    // bound at all, which fails at runtime rather than degrading safely.
    expect(config('ocr.driver'))->toBe('none');
});

it('treats the OCR confidence threshold as review-only, never auto-verification', function (): void {
    // docs/02 FR-005 and AT-004: OCR must never auto-confirm. The threshold
    // only decides which fields get highlighted for the human reviewer, so it
    // must stay below 1.0 and must not be interpretable as "skip review".
    $threshold = config('ocr.review_threshold');

    expect($threshold)->toBeFloat()
        ->toBeGreaterThan(0.0)
        ->toBeLessThan(1.0);
});

it('computes in UTC and renders in Asia/Bangkok', function (): void {
    // docs/10 rule 7.
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('app.display_timezone'))->toBe('Asia/Bangkok');
});
