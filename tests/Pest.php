<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case Bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the full application and hit a real MySQL test database
| (see phpunit.xml) so schema constraints and DECIMAL arithmetic behave as
| they do in production. Unit tests stay framework-free where possible so
| cost and tariff rules can be exercised in isolation.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
