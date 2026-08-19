<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks (docs/03 -> reliability, architecture -> Scheduler)
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (php artisan schedule:work).
|
*/

// Nightly backup, before the working day. withoutOverlapping so a long dump
// cannot have a second one started on top of it.
Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// Budget thresholds and anomalies are also evaluated when a dashboard is
// viewed, but a user who does not open the app should still be told they are
// approaching their budget (FR-013, FR-014).
Schedule::command('insights:evaluate')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sanctum tokens accumulate; expired ones are dead weight and an unnecessary
// attack surface.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->onOneServer();
