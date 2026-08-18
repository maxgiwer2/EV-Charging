<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Provider
    |--------------------------------------------------------------------------
    |
    | Selects the OcrProviderInterface implementation to bind. Domain code must
    | depend on the interface only and never on a vendor SDK
    | (architecture/system-architecture.md -> OCR Provider Adapter).
    |
    | The `none` driver returns an empty, zero-confidence result. It is the
    | default so a fresh checkout and the test suite never make network calls.
    |
    | Named `none` rather than `null` on purpose: Laravel's env() casts the
    | literal string "null" to PHP null, which would silently blank the driver
    | instead of falling back to the default.
    |
    */

    'driver' => env('OCR_DRIVER', 'none'),

    'timeout_seconds' => (int) env('OCR_TIMEOUT_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Review Threshold
    |--------------------------------------------------------------------------
    |
    | Fields extracted below this confidence are flagged for the reviewer
    | (docs/05 -> low confidence fields must be highlighted).
    |
    | This threshold NEVER decides verification. A receipt reaches VERIFIED
    | only through explicit human confirmation, whatever the confidence
    | (docs/02 FR-005, AT-004). High confidence shortens review; it does not
    | skip it.
    |
    */

    'review_threshold' => (float) env('OCR_CONFIDENCE_REVIEW_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | docs/03 -> retry with backoff, idempotent OCR jobs. Seconds between
    | attempts; the job itself is keyed on receipt id so a replayed callback
    | cannot produce duplicate financial records.
    |
    */

    'max_attempts' => 3,

    'backoff_seconds' => [5, 15, 60],

];
