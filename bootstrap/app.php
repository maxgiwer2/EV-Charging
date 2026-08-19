<?php

declare(strict_types=1);

use App\Enums\ErrorCode;
use App\Http\ApiResponse;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // docs/03 -> rate limiting. Applied across the API surface; the
        // `auth` limiter in AppServiceProvider tightens login specifically.
        $middleware->api(prepend: [
            ThrottleRequests::using('api'),
        ]);

        // Security headers on every response, web and API alike
        // (docs/03 -> secure by default).
        $middleware->append(SecurityHeaders::class);

        // Prepended so the id exists before anything else can log
        // (docs/03 -> structured logging).
        $middleware->prepend(AssignRequestId::class);

        // Trust the reverse proxy for scheme and client IP. Without this
        // Laravel behind nginx sees plain HTTP and generates http:// URLs, and
        // every audit row records the proxy's address instead of the user's.
        // Restricted to a configured proxy list in production, because trusting
        // any X-Forwarded-For lets a client spoof the IP written to the audit
        // trail (docs/02 FR-015).
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') === '*' ? '*' : array_filter(explode(',', (string) env('TRUSTED_PROXIES', ''))),
        );

        // The sign-in route is named `web.login`, not Laravel's default
        // `login`, so the guest redirect has to be pointed at it explicitly.
        // API requests are unaffected: they get a 401 envelope instead.
        $middleware->redirectGuestsTo(fn () => route('web.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * One JSON envelope with stable codes for every API failure
         * (docs/07, docs/10 rule 14).
         *
         * Laravel's prepareException() rewrites framework exceptions into HTTP
         * exceptions before render callbacks run -- an AuthorizationException
         * becomes a 403 HttpException, and a policy's denyAsNotFound() becomes
         * a plain 404 HttpException rather than NotFoundHttpException. Matching
         * on the status code therefore covers every conversion, whereas
         * matching on the exception class silently misses some.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    ErrorCode::VALIDATION_FAILED,
                    'The given data was invalid.',
                    $e->errors(),
                );
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error(ErrorCode::UNAUTHENTICATED, 'Authentication is required.');
            }

            // A record that does not exist and one owned by somebody else must
            // look identical, otherwise 404-vs-403 reveals which ids exist
            // (AT-007).
            if ($e instanceof ModelNotFoundException) {
                return ApiResponse::error(ErrorCode::NOT_FOUND, 'Resource not found.');
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                $code = match ($status) {
                    401 => ErrorCode::UNAUTHENTICATED,
                    403 => ErrorCode::FORBIDDEN,
                    404 => ErrorCode::NOT_FOUND,
                    409 => ErrorCode::CONFLICT,
                    422 => ErrorCode::VALIDATION_FAILED,
                    429 => ErrorCode::RATE_LIMITED,
                    default => ErrorCode::SERVER_ERROR,
                };

                $message = match ($status) {
                    401 => 'Authentication is required.',
                    403 => 'This action is unauthorized.',
                    404 => 'Resource not found.',
                    429 => 'Too many requests.',
                    // A framework message may name internal detail, so it is
                    // only passed through for statuses without a fixed text.
                    default => $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.',
                };

                return ApiResponse::error($code, $message, null, $status);
            }

            // Unexpected failures: the message can contain internal detail
            // (query fragments, file paths), so it is surfaced only in debug
            // mode. The full trace always goes to the logs.
            return ApiResponse::error(
                ErrorCode::SERVER_ERROR,
                config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
            );
        });
    })->create();
