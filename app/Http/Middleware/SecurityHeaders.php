<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response security headers (docs/03 -> secure by default).
 *
 * Set in the application rather than only in nginx, for two reasons: the
 * headers then travel with the app regardless of what sits in front of it in a
 * given environment, and they can be reasoned about alongside the code that
 * decides them. The nginx config keeps its copy as defence in depth.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Clickjacking. DENY rather than SAMEORIGIN: nothing in this
        // application is meant to be framed, including by itself.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Stops a browser second-guessing a Content-Type. Matters most on the
        // receipt download route, where a mis-sniffed file could be executed
        // as something other than the image it claims to be.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Referrers leak URLs. Receipt and session URLs contain record ids, so
        // they must not travel to third-party hosts.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Features this application never uses. Denying them removes the
        // prompts entirely rather than relying on the user to decline.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), payment=(), usb=()',
        );

        // Financial pages must not be cached by a proxy or left in a shared
        // browser cache. Applied to authenticated HTML and JSON only: static
        // assets are fingerprinted by Vite and should stay cacheable.
        if ($this->carriesPrivateData($request, $response)) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        // HSTS is only meaningful over HTTPS, and asserting it on a plain-HTTP
        // development server would teach the browser to refuse localhost.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        // CSP is applied to HTML only. `unsafe-inline` for styles is currently
        // required: Blade templates carry inline `style` attributes for the
        // chart bars and progress bars. Scripts do not need it -- Vite emits
        // external bundles -- so script-src stays strict, which is the half
        // that actually blocks injected code.
        if ($this->isHtml($response)) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                // No third-party endpoints: the assistant and OCR are called
                // server side, so the browser never talks to them.
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
            ]));
        }

        return $response;
    }

    private function carriesPrivateData(Request $request, Response $response): bool
    {
        if ($request->user() === null) {
            return false;
        }

        return $this->isHtml($response)
            || str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
