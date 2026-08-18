<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\get;

/*
 * M0 smoke tests: the application boots, serves HTTP, and can reach the
 * database it will store financial records in.
 */

it('serves the health check endpoint', function (): void {
    get('/up')->assertOk();
});

it('runs against MySQL so DECIMAL and ENUM semantics match production', function (): void {
    // docs/10 rule 4/5: money correctness cannot be verified on a driver that
    // stores DECIMAL as a float, so the test suite must not fall back to SQLite.
    expect(config('database.default'))->toBe('mysql');
    expect(DB::connection()->getDriverName())->toBe('mysql');
});

it('has a reachable migrated database', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue();
});
