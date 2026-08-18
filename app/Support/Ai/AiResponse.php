<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * A reply from an AI provider.
 *
 * Carries the provider and model so anything the AI influenced can be traced
 * back to what produced it (docs/05 -> log AI provider/model/version).
 */
final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public bool $succeeded = true,
        public ?string $error = null,
    ) {}

    public static function failed(string $provider, string $model, string $error): self
    {
        return new self('', $provider, $model, false, $error);
    }

    /**
     * Decode a JSON reply, tolerating the code fences models like to add.
     *
     * Returns null when the reply is not usable JSON. Callers must treat that
     * as "the model did not answer", never as an empty result -- an empty
     * filter would silently widen a query to everything.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if (! $this->succeeded) {
            return null;
        }

        $text = trim($this->content);
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/mu', '', $text);

        // Models often wrap JSON in a sentence; take the outermost object.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
