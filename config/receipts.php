<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Receipt Storage
    |--------------------------------------------------------------------------
    |
    | The disk receipt files are written to. Must be a private disk with no
    | public URL -- see config/filesystems.php and docs/03.
    |
    */

    'disk' => env('RECEIPT_DISK', 'receipts'),

    /*
    |--------------------------------------------------------------------------
    | Upload Constraints
    |--------------------------------------------------------------------------
    |
    | docs/02 FR-004 permits JPG/JPEG/PNG/WEBP/PDF. Both the MIME type and the
    | file's magic bytes are validated at upload time -- a client-supplied
    | Content-Type is never trusted on its own (docs/10 rule 3).
    |
    | `extensions` and `mime_types` must stay in sync; the signature map below
    | is what actually gates the upload.
    |
    */

    'max_size_kb' => (int) env('RECEIPT_MAX_SIZE_KB', 10240),

    'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],

    'mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    /*
     * Leading magic bytes, hex encoded, keyed by MIME type. A file must match
     * one signature for its declared type. WEBP and JPEG need an offset match
     * (WEBP: "RIFF" then "WEBP" at byte 8), handled by the validator rule.
     */
    'signatures' => [
        'image/jpeg' => ['ffd8ff'],
        'image/png' => ['89504e470d0a1a0a'],
        'image/webp' => ['52494646'],
        'application/pdf' => ['25504446'],
    ],

];
