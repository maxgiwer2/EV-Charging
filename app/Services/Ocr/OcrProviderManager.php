<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Contracts\OcrProviderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the configured OCR adapter (config/ocr.php -> driver).
 *
 * New providers register here rather than being referenced from domain code,
 * which is what keeps the receipt pipeline vendor-agnostic
 * (architecture/system-architecture.md).
 */
class OcrProviderManager
{
    /**
     * @var array<string, class-string<OcrProviderInterface>>
     */
    private array $drivers = [
        'none' => NoneOcrProvider::class,
        'typhoon' => TyphoonOcrProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): OcrProviderInterface
    {
        $name ??= (string) config('ocr.driver', 'none');

        if (! isset($this->drivers[$name])) {
            // Failing loudly beats silently falling back to `none`, which
            // would look like "OCR read nothing" and send every receipt to
            // manual entry with no indication that the config is wrong.
            throw new InvalidArgumentException(
                "Unknown OCR driver [{$name}]. Registered: ".implode(', ', array_keys($this->drivers)).'.'
            );
        }

        return $this->container->make($this->drivers[$name]);
    }

    /**
     * @param  class-string<OcrProviderInterface>  $provider
     */
    public function register(string $name, string $provider): void
    {
        $this->drivers[$name] = $provider;
    }
}
