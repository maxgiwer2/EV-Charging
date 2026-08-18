<?php

declare(strict_types=1);

use App\Enums\ChargingType;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;

/*
 * The dashboard screen (docs/02 FR-010). It reads through AnalyticsService, so
 * the page and the API report the same numbers (AT-009).
 */

function dashboardSession(User $user, array $attributes = []): ChargingSession
{
    return ChargingSession::factory()->create(array_merge([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDays(1),
        'energy_kwh' => '40.000',
        'distance_km' => '200.0',
        'subtotal' => '200.00',
        'vat_amount' => '14.00',
        'discount_amount' => '0.00',
        'total_amount' => '214.00',
    ], $attributes));
}

it('redirects a guest to sign in', function (): void {
    $this->get('/dashboard')->assertRedirect(route('web.login'));
});

it('shows the period totals', function (): void {
    $user = User::factory()->create();
    dashboardSession($user);
    dashboardSession($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('428.00')
        ->assertSee('Total spend');
});

it('shows an em dash rather than zero for an uncomputable metric', function (): void {
    // docs/06: a metric with no denominator is unknown, not zero. Rendering
    // "0.00" would misreport it as measured.
    $user = User::factory()->create();
    dashboardSession($user, ['distance_km' => null, 'odometer_before_km' => null, 'odometer_after_km' => null]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk()
        ->assertSee('Not enough data to calculate')
        // The metric that does not need distance is still shown.
        ->assertSee('5.3500');
});

it('excludes drafts and cancellations from the page (AT-009)', function (): void {
    $user = User::factory()->create();
    dashboardSession($user);
    dashboardSession($user, ['status' => 'DRAFT']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        // One confirmed session only.
        ->assertSee('214.00');
});

it('never shows another user\'s data (AT-007)', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    dashboardSession($victim);
    dashboardSession($victim);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('428.00');
});

it('handles an empty period without breaking', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('No charging recorded in this period');
});

it('offers exports carrying the current filter (AT-008)', function (): void {
    $user = User::factory()->create();
    dashboardSession($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('format=csv', escape: false)
        ->assertSee('format=xlsx', escape: false)
        ->assertSee('format=pdf', escape: false);
});

it('breaks spend down by charging type', function (): void {
    $user = User::factory()->create();
    dashboardSession($user, ['charging_type' => ChargingType::HOME]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('By charging type')
        ->assertSee('HOME');
});

it('honours an explicit date range', function (): void {
    $user = User::factory()->create();
    // Last month, outside the default window.
    dashboardSession($user, ['started_at' => now()->subMonth()->startOfMonth()->addDay()]);

    $inRange = $this->actingAs($user)->get('/dashboard?'.http_build_query([
        'from' => now()->subMonth()->startOfMonth()->toIso8601String(),
        'to' => now()->startOfMonth()->toIso8601String(),
    ]));

    $inRange->assertOk()->assertSee('214.00');

    // Default (current month) excludes it.
    $this->actingAs($user)->get('/dashboard')->assertDontSee('214.00');
});
