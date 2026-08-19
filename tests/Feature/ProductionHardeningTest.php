<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\ProductionServiceProvider;

use function Pest\Laravel\get;

/*
 * docs/09 M6 and docs/03: security headers, health probes, request
 * correlation, and refusing to boot on an unsafe production configuration.
 */

// ------------------------------------------------------------ security headers

it('sets the security headers on every response', function (): void {
    $response = get('/login');

    $response->assertOk()
        // Nothing here is meant to be framed, including by itself.
        ->assertHeader('X-Frame-Options', 'DENY')
        // Matters most on the receipt download route, where a mis-sniffed file
        // could be treated as something other than the image it claims to be.
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=()');
});

it('applies a strict script policy while allowing inline styles', function (): void {
    // Blade uses inline style attributes for the chart and progress bars, so
    // style-src needs unsafe-inline. script-src does not, and that is the half
    // that blocks injected code.
    $csp = get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self'")
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'");
});

it('does not assert HSTS over plain http', function (): void {
    // Asserting it on a plain-HTTP dev server would teach the browser to
    // refuse localhost.
    expect(get('/login')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('marks authenticated pages as uncacheable', function (): void {
    // Financial pages must not sit in a proxy or shared browser cache.
    $cacheControl = $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private');
});

it('leaves an unauthenticated page cacheable', function (): void {
    // Only private data needs the no-store treatment.
    expect(get('/login')->headers->get('Cache-Control'))->not->toContain('no-store');
});

// ------------------------------------------------------------------- health

it('answers liveness without touching a dependency', function (): void {
    get('/health/live')->assertOk()->assertJson(['status' => 'ok']);
});

it('reports readiness per dependency', function (): void {
    $response = get('/health/ready');

    $response->assertOk()->assertJsonPath('status', 'ok');

    expect($response->json('checks'))
        ->toHaveKeys(['database', 'cache', 'receipt_storage', 'queue'])
        ->and($response->json('checks.database'))->toBeTrue();
});

it('reports the running version so an incident can identify the release', function (): void {
    config()->set('app.version', 'abc1234');

    expect(get('/health/ready')->json('version'))->toBe('abc1234');
});

it('never discloses why a dependency failed', function (): void {
    // A connection error names hosts, ports and sometimes credentials, and
    // these endpoints are unauthenticated.
    $body = get('/health/ready')->getContent();

    expect($body)->not->toContain(config('database.connections.mysql.password'))
        ->and($body)->not->toContain(config('database.connections.mysql.host'));
});

// --------------------------------------------------------------- request id

it('returns a request id for correlation', function (): void {
    $id = get('/login')->headers->get('X-Request-Id');

    expect($id)->not->toBeNull()->and(mb_strlen((string) $id))->toBeGreaterThan(8);
});

it('honours a well-formed inbound request id', function (): void {
    // Lets a trace started at the load balancer survive.
    $response = $this->withHeader('X-Request-Id', 'lb-abc123def456')->get('/login');

    expect($response->headers->get('X-Request-Id'))->toBe('lb-abc123def456');
});

it('replaces an inbound id that could forge a log line', function (): void {
    // The value reaches log files, so newlines and control characters are
    // rejected outright rather than escaped.
    $response = $this->withHeader('X-Request-Id', "bad\nid INJECTED")->get('/login');

    expect($response->headers->get('X-Request-Id'))->not->toContain('INJECTED');
});

// ------------------------------------------------- production configuration

it('passes the production check with a safe configuration', function (): void {
    config()->set([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://ev.example.com',
        'session.secure' => true,
        'session.encrypt' => true,
        'session.http_only' => true,
        'queue.default' => 'redis',
    ]);

    $this->artisan('app:check-production')->assertSuccessful();
});

it('fails the production check when debug is on', function (): void {
    config()->set('app.debug', true);

    $this->artisan('app:check-production')->assertFailed();
});

it('fails the production check when the receipts disk is public', function (): void {
    // The single worst configuration mistake available here (AT-007).
    config()->set('receipts.disk', 'public');

    $this->artisan('app:check-production')->assertFailed();
});

it('fails the production check when the queue is synchronous', function (): void {
    // OCR would run inside the request and time out.
    config()->set('queue.default', 'sync');

    $this->artisan('app:check-production')->assertFailed();
});

it('refuses to boot in production with debug on', function (): void {
    // Refusing to start and saying why beats coming up quietly with financial
    // records exposed behind a debug page.
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set([
        'app.debug' => true,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://ev.example.com',
        'session.secure' => true,
        'session.encrypt' => true,
    ]);

    expect(fn () => (new ProductionServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'APP_DEBUG must be false');
});

it('refuses to boot in production with an insecure session cookie', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config()->set([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://ev.example.com',
        'session.secure' => false,
        'session.encrypt' => true,
    ]);

    expect(fn () => (new ProductionServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'SESSION_SECURE_COOKIE');
});

it('boots without complaint outside production', function (): void {
    // The guards must not interfere with local development.
    config()->set('app.debug', true);

    expect(fn () => (new ProductionServiceProvider($this->app))->boot())->not->toThrow(RuntimeException::class);
});
