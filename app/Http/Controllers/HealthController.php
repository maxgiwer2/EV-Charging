<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health and readiness (docs/03 -> observability, docs/09 M6).
 *
 * Laravel's `/up` only proves PHP booted. That is the wrong signal for a load
 * balancer: an instance whose database is unreachable answers `/up` happily and
 * keeps receiving traffic it cannot serve.
 *
 * So `/health` checks the dependencies this application cannot work without and
 * returns 503 when one is down. Failures are reported by name but never with
 * the underlying message, which tends to contain hostnames and credentials.
 */
class HealthController extends Controller
{
    /**
     * Liveness: is the process up. Cheap, no dependencies, safe to poll often.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness: can this instance actually serve a request.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): mixed => DB::select('select 1')),
            'cache' => $this->check(function (): mixed {
                // Round-trips a value rather than just connecting: a cache that
                // accepts connections but cannot store is still broken.
                Cache::put('health:probe', '1', 10);

                return Cache::get('health:probe');
            }),
            'receipt_storage' => $this->check(
                fn (): mixed => Storage::disk((string) config('receipts.disk'))->exists('.')
            ),
            // size() reaches the configured queue backend, whichever driver is
            // in use. Checking the `jobs` table instead would prove nothing on
            // a Redis queue -- and would pass while the queue was unreachable.
            'queue' => $this->check(fn (): mixed => Queue::connection()->size()),
        ];

        $healthy = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            // Lets an operator confirm which release answered, which is the
            // first question during an incident.
            'version' => config('app.version'),
        ], $healthy ? 200 : 503);
    }

    /**
     * Run one probe, reducing any failure to false.
     *
     * The exception message is deliberately discarded: a health endpoint is
     * usually unauthenticated, and a connection error names hosts, ports and
     * sometimes credentials (docs/10 rule 13).
     */
    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
