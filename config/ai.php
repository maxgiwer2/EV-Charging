<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Selects the AiProviderInterface implementation. Domain code depends on
    | the interface only (architecture/system-architecture.md).
    |
    | `none` is the default so a fresh checkout and the test suite make no
    | network calls. Named `none`, not `null`, because env() casts the literal
    | string "null" to PHP null.
    |
    */

    'driver' => env('AI_DRIVER', 'none'),

    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 120),

    'max_tokens' => (int) env('AI_MAX_TOKENS', 512),

    /*
    |--------------------------------------------------------------------------
    | Ollama
    |--------------------------------------------------------------------------
    |
    | Self-hosted, using the OpenAI-compatible /v1/chat/completions endpoint.
    | Self-hosting matters here: the assistant answers questions about a user's
    | financial records, and this way none of that data leaves the deployment.
    |
    | The model is only ever asked to classify a question or phrase an answer
    | around figures the application computed. It is never asked to calculate:
    | on a plain division of 1385.84 by 3, the models tested returned 4116.52,
    | nothing, and 461.95 respectively.
    |
    */

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', ''),
        'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
    ],

];
