<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Support\Ai\AiResponse;

/**
 * No-op adapter and the default driver.
 *
 * Reports itself unavailable rather than returning empty text, so callers
 * present the deterministic answer on its own instead of an assistant reply
 * that says nothing. Keeps a fresh checkout and the test suite free of
 * network calls.
 */
class NoneAiProvider implements AiProviderInterface
{
    public function chat(array $messages, array $options = []): AiResponse
    {
        return AiResponse::failed($this->name(), $this->model(), 'no_provider_configured');
    }

    public function name(): string
    {
        return 'none';
    }

    public function model(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
