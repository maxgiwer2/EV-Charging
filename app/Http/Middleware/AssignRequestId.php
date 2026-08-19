<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlates every log line from one request (docs/03 -> structured logging).
 *
 * Without this, tracing a single user's failed receipt upload through a log
 * that mixes web requests and queue workers means guessing from timestamps.
 * With it, one id ties the whole request together and is returned to the
 * client, so a user reporting a problem can quote it.
 *
 * An inbound X-Request-Id is honoured so a trace started at the load balancer
 * survives, but it is validated first: the value reaches log files, and
 * unvalidated input in a log line invites forged entries.
 */
class AssignRequestId
{
    private const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        // Attached to the log context, so every entry for this request carries
        // it without each call site having to remember.
        Log::shareContext(['request_id' => $id]);

        $request->headers->set(self::HEADER, $id);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * Use the inbound id when it is plausibly one, otherwise mint a new one.
     */
    private function resolve(Request $request): string
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');

        // Conservative allowlist: anything with newlines or control characters
        // could forge log lines.
        if ($incoming !== '' && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
