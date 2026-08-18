<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Support\Ai\AiMessage;
use App\Support\Ai\AiResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ollama adapter, using its OpenAI-compatible /v1/chat/completions endpoint.
 *
 * Self-hosted, which matters here: the assistant is answering questions about
 * a user's financial records, and with Ollama on the local network none of
 * that data leaves the deployment (docs/03 -> secure by default).
 *
 * The model is only ever asked to classify a question or phrase an answer.
 * It is never asked to compute -- see AiProviderInterface for why that is a
 * hard rule rather than a preference.
 */
class OllamaAiProvider implements AiProviderInterface
{
    public function chat(array $messages, array $options = []): AiResponse
    {
        $payload = [
            'model' => $this->model(),
            'messages' => array_map(fn (AiMessage $m): array => $m->toArray(), $messages),
            // Deterministic by default: the same question should give the same
            // answer, and creativity is not wanted when reporting figures.
            'temperature' => $options['temperature'] ?? 0.0,
            'max_tokens' => $options['max_tokens'] ?? (int) config('ai.max_tokens'),
        ];

        if (($options['json'] ?? false) === true) {
            // Ollama honours the OpenAI response_format hint; the reply is
            // still parsed defensively because compliance is not guaranteed.
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::timeout((int) config('ai.timeout_seconds'))
                ->acceptJson()
                ->post($this->endpoint(), $payload);
        } catch (Throwable $e) {
            // A local model server being down must degrade the assistant, not
            // break the request.
            Log::warning('Ollama request failed', ['exception' => $e->getMessage()]);

            return AiResponse::failed($this->name(), $this->model(), 'transport_error');
        }

        if ($response->failed()) {
            Log::warning('Ollama returned an error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return AiResponse::failed($this->name(), $this->model(), 'http_'.$response->status());
        }

        $content = (string) $response->json('choices.0.message.content', '');

        if (trim($content) === '') {
            // Observed in practice: some models return an empty completion.
            return AiResponse::failed($this->name(), $this->model(), 'empty_response');
        }

        return new AiResponse($content, $this->name(), $this->model());
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return (string) config('ai.ollama.model');
    }

    public function isAvailable(): bool
    {
        return (string) config('ai.ollama.base_url') !== '';
    }

    private function endpoint(): string
    {
        return rtrim((string) config('ai.ollama.base_url'), '/').'/chat/completions';
    }
}
