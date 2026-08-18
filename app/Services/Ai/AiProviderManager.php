<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the configured AI adapter (config/ai.php -> driver).
 */
class AiProviderManager
{
    /**
     * @var array<string, class-string<AiProviderInterface>>
     */
    private array $drivers = [
        'none' => NoneAiProvider::class,
        'ollama' => OllamaAiProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): AiProviderInterface
    {
        $name ??= (string) config('ai.driver', 'none');

        if (! isset($this->drivers[$name])) {
            // Loudly, rather than silently degrading to `none`, which would
            // look like "the assistant has nothing to say".
            throw new InvalidArgumentException(
                "Unknown AI driver [{$name}]. Registered: ".implode(', ', array_keys($this->drivers)).'.'
            );
        }

        return $this->container->make($this->drivers[$name]);
    }

    /**
     * @param  class-string<AiProviderInterface>  $provider
     */
    public function register(string $name, string $provider): void
    {
        $this->drivers[$name] = $provider;
    }
}
