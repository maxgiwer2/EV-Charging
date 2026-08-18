<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Ai\AiMessage;
use App\Support\Ai\AiResponse;

/**
 * Provider-independent AI contract
 * (architecture/system-architecture.md -> AI Provider Adapter).
 *
 * "AI is advisory; deterministic business rules remain authoritative." That
 * sentence is the whole design, and it is not a stylistic preference. Asked to
 * divide 1385.84 by 3, the models available here answered 4116.52, returned
 * nothing, and 461.95 respectively -- one of three was right. A figure produced
 * that way must never reach a financial record or a reported total.
 *
 * So an implementation is used for two things only:
 *
 *  - turning a question into a *structured intent* the application can act on;
 *  - phrasing an answer around numbers the application has already computed.
 *
 * It is never asked to calculate, and its output is never persisted as a
 * financial value.
 */
interface AiProviderInterface
{
    /**
     * Send a conversation and return the reply.
     *
     * Implementations must not throw for a provider-side failure -- return
     * AiResponse::failed() so the caller can degrade to a plain, factual
     * answer rather than showing the user an error.
     *
     * @param  list<AiMessage>  $messages
     * @param  array<string, mixed>  $options  provider hints: temperature, max_tokens, json
     */
    public function chat(array $messages, array $options = []): AiResponse;

    /**
     * Identifier recorded alongside anything the provider influenced
     * (docs/05 -> log AI provider/model/version).
     */
    public function name(): string;

    /**
     * The model in use, for the same audit reason.
     */
    public function model(): string;

    /**
     * Whether the provider is configured and usable. Lets callers offer the
     * assistant only when it will actually work.
     */
    public function isAvailable(): bool;
}
