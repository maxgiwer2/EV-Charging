<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;

/*
 * Budget management screens (docs/02 FR-013) and the dashboard cards that
 * surface budgets, the projection and anything unusual.
 */

it('lists only the caller\'s budgets (AT-007)', function (): void {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id, 'amount' => '1234.00']);
    Budget::factory()->create(['amount' => '9876.00']);

    $this->actingAs($user)
        ->get(route('budgets.manage.index'))
        ->assertOk()
        ->assertSee('1234.00')
        ->assertDontSee('9876.00');
});

it('creates a budget owned by the caller', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('budgets.manage.store'), [
        'amount' => 3000,
        'period' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ])->assertRedirect(route('budgets.manage.index'));

    expect(Budget::first()->user_id)->toBe($user->id);
});

it('accepts thresholds typed as a comma separated list', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('budgets.manage.store'), [
        'amount' => 3000,
        'period' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'thresholds_csv' => '60, 90 ,60, 120',
    ])->assertRedirect();

    // Deduplicated and sorted; a repeated level would alert twice for one
    // crossing.
    expect(Budget::first()->thresholds())->toBe([60, 90, 120]);
});

it('falls back to the defaults when the thresholds field is unusable', function (): void {
    // Storing an empty set would disable alerts without saying so.
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('budgets.manage.store'), [
        'amount' => 3000,
        'period' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'thresholds_csv' => 'abc, -5',
    ])->assertRedirect();

    expect(Budget::first()->thresholds())->toBe([50, 80, 100]);
});

it('refuses to edit another user\'s budget', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('budgets.manage.edit', Budget::factory()->create()))
        ->assertNotFound();
});

it('forbids a viewer from adding a budget', function (): void {
    $this->actingAs(User::factory()->viewer()->create())
        ->post(route('budgets.manage.store'), [
            'amount' => 1000,
            'period' => 'MONTHLY',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])
        ->assertForbidden();

    expect(Budget::count())->toBe(0);
});

it('shows budget progress on the dashboard', function (): void {
    $user = User::factory()->create();
    Budget::factory()->create([
        'user_id' => $user->id,
        'amount' => '1000.00',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ]);
    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDay(),
        'total_amount' => '600.00',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Budget')
        ->assertSee('600.00')
        ->assertSee('60.00%');
});

it('says why a projection is unavailable instead of showing a number', function (): void {
    // A figure resting on nothing is worse than an honest gap.
    $this->travelTo(now()->startOfMonth()->addDay());

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Not enough data yet');
});

it('shows the projection with its caveats once available', function (): void {
    $this->travelTo(now()->startOfMonth()->addDays(14));

    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 3) as $i) {
        ChargingSession::factory()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->startOfMonth()->addDays($i),
            'total_amount' => '214.00',
        ]);
    }

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Projected this month')
        // Caveats are shown, not buried.
        ->assertSee('few sessions');
});
