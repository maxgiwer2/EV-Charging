<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Production-only hardening (docs/03 -> secure by default, docs/09 M6).
 *
 * These are the settings whose absence is invisible in development and
 * expensive in production: a cookie sent over plain HTTP, a debug page
 * disclosing a stack trace, an APP_KEY left at its scaffold value.
 *
 * The misconfiguration checks deliberately **throw on boot**. A system that
 * starts up holding financial records with debug mode on is worse than one
 * that refuses to start and says why: the first fails quietly for as long as
 * nobody looks.
 */
class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $this->assertSafeConfiguration();

        // Generate https:// URLs regardless of what the proxy forwarded. Belt
        // and braces alongside trustProxies: a login form posting to http://
        // would send the session cookie in clear.
        URL::forceScheme('https');
    }

    /**
     * Refuse to boot on a configuration that would leak or weaken security.
     *
     * @throws RuntimeException
     */
    private function assertSafeConfiguration(): void
    {
        $problems = [];

        if (config('app.debug') === true) {
            // Debug pages disclose stack traces, queries and env values.
            $problems[] = 'APP_DEBUG must be false in production.';
        }

        if (config('session.secure') !== true) {
            // Otherwise the session cookie travels over plain HTTP.
            $problems[] = 'SESSION_SECURE_COOKIE must be true in production.';
        }

        if (config('session.encrypt') !== true) {
            $problems[] = 'SESSION_ENCRYPT must be true in production.';
        }

        $key = (string) config('app.key');

        if ($key === '' || $key === 'base64:') {
            $problems[] = 'APP_KEY is not set.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must use https in production.';
        }

        // A public receipts disk would expose private financial documents by
        // URL, which is the single worst configuration mistake available here
        // (docs/03, AT-007).
        $disk = (string) config('receipts.disk');

        if ($disk === 'public' || config("filesystems.disks.{$disk}.url") !== null) {
            $problems[] = "The receipts disk [{$disk}] must be private and have no public URL.";
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Unsafe production configuration:\n - ".implode("\n - ", $problems)
            );
        }
    }
}
