<?php

declare(strict_types=1);

use App\Enums\ChargingType;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AnalyticsFilter;
use App\Services\AnalyticsService;

/*
 * AT-009: dashboard totals must reconcile with confirmed charging sessions.
 */

/** A confirmed session with exact amounts, inside the current month. */
function confirmedSession(User $user, array $attributes = []): ChargingSession
{
    return ChargingSession::factory()->create(array_merge([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDays(2),
        'energy_kwh' => '40.000',
        'distance_km' => '200.0',
        'subtotal' => '200.00',
        'vat_amount' => '14.00',
        'discount_amount' => '0.00',
        'total_amount' => '214.00',
    ], $attributes));
}

it('reconciles totals with the confirmed sessions (AT-009)', function (): void {
    $user = User::factory()->create();
    confirmedSession($user);
    confirmedSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    $response->assertOk();

    expect($response->json('data.summary.session_count'))->toBe(2)
        ->and($response->json('data.summary.total_cost'))->toBe('428.00')
        ->and($response->json('data.summary.total_kwh'))->toBe('80.000');
});

it('excludes drafts and cancellations from totals (AT-009)', function (): void {
    // This is the whole point of the CONFIRMED scope: an unfinished or
    // retracted entry must never inflate a report.
    $user = User::factory()->create();
    confirmedSession($user);
    confirmedSession($user, ['status' => 'DRAFT']);
    confirmedSession($user, ['status' => 'CANCELLED']);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.session_count'))->toBe(1)
        ->and($response->json('data.summary.total_cost'))->toBe('214.00');
});

it('excludes soft-deleted sessions', function (): void {
    $user = User::factory()->create();
    confirmedSession($user);
    confirmedSession($user)->delete();

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.session_count'))->toBe(1);
});

it('never mixes in another user\'s spending (AT-007)', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    confirmedSession($user);
    confirmedSession($other);
    confirmedSession($other);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.session_count'))->toBe(1)
        ->and($response->json('data.summary.total_cost'))->toBe('214.00');
});

it('computes the derived metrics from the period totals (docs/06)', function (): void {
    $user = User::factory()->create();
    confirmedSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    // 214 / 40 kWh, 214 / 200 km
    expect($response->json('data.summary.cost_per_kwh'))->toBe('5.3500')
        ->and($response->json('data.summary.cost_per_km'))->toBe('1.0700')
        ->and($response->json('data.summary.kwh_per_100km'))->toBe('20.0000');
});

it('returns null metrics rather than zero when distance was never recorded', function (): void {
    // A zero would read as free driving and corrupt the average.
    $user = User::factory()->create();
    confirmedSession($user, ['distance_km' => null]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.cost_per_km'))->toBeNull()
        ->and($response->json('data.summary.kwh_per_100km'))->toBeNull()
        // The metric that does not need distance still works.
        ->and($response->json('data.summary.cost_per_kwh'))->toBe('5.3500');
});

it('reports zero spend and null metrics for an empty period', function (): void {
    // No sessions means nothing was spent (a known zero), but cost per kWh is
    // unknowable (null) rather than zero.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.session_count'))->toBe(0)
        ->and($response->json('data.summary.total_cost'))->toBe('0.00')
        ->and($response->json('data.summary.cost_per_kwh'))->toBeNull();
});

it('splits home from public spend (docs/06)', function (): void {
    $user = User::factory()->create();
    confirmedSession($user, ['charging_type' => ChargingType::HOME]);
    confirmedSession($user, ['charging_type' => ChargingType::PUBLIC]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.summary.home_public_ratio.home'))->toBe('214.00')
        ->and($response->json('data.summary.home_public_ratio.public'))->toBe('214.00');
});

it('respects an explicit date window with an exclusive upper bound', function (): void {
    // A session exactly on the boundary must be counted once, not in both
    // adjacent periods.
    $user = User::factory()->create();
    $boundary = now()->startOfMonth();

    confirmedSession($user, ['started_at' => $boundary]);
    confirmedSession($user, ['started_at' => $boundary->copy()->subSecond()]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary?'.http_build_query([
        'from' => $boundary->toIso8601String(),
        'to' => $boundary->copy()->addMonth()->toIso8601String(),
    ]));

    expect($response->json('data.summary.session_count'))->toBe(1);
});

it('compares against the preceding period', function (): void {
    $user = User::factory()->create();
    confirmedSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/summary');

    expect($response->json('data.comparison.current.total_cost'))->toBe('214.00')
        ->and($response->json('data.comparison.previous.total_cost'))->toBe('0.00')
        // A percentage against a zero base is undefined, not +100%.
        ->and($response->json('data.comparison.change.total_cost_pct'))->toBeNull();
});

it('returns a monthly trend series', function (): void {
    $user = User::factory()->create();
    confirmedSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/trends?'.http_build_query([
        'from' => now()->startOfMonth()->toIso8601String(),
        'to' => now()->startOfMonth()->addMonth()->toIso8601String(),
        'granularity' => 'month',
    ]));

    $response->assertOk();

    expect($response->json('data.0.bucket'))->toBe(now()->format('Y-m'))
        ->and($response->json('data.0.total_cost'))->toBe('214.00');
});

it('rejects an unknown granularity', function (): void {
    // The value selects a raw SQL date expression, so it must be whitelisted.
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/dashboard/trends?granularity=; DROP TABLE users --')
        ->assertStatus(422);
});

it('breaks spend down by charging type', function (): void {
    $user = User::factory()->create();
    confirmedSession($user, ['charging_type' => ChargingType::HOME]);
    confirmedSession($user, ['charging_type' => ChargingType::PUBLIC]);
    confirmedSession($user, ['charging_type' => ChargingType::PUBLIC]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/breakdowns?dimension=charging_type');

    $response->assertOk();

    $byLabel = collect($response->json('data'))->keyBy('label');

    expect($byLabel['PUBLIC']['session_count'])->toBe(2)
        ->and($byLabel['PUBLIC']['total_cost'])->toBe('428.00')
        ->and($byLabel['HOME']['total_cost'])->toBe('214.00');
});

it('keeps sessions without a station in the station breakdown', function (): void {
    // Dropping them would make the parts stop summing to the whole.
    $user = User::factory()->create();
    $station = ChargingStation::factory()->create();
    confirmedSession($user, ['station_id' => $station->id]);
    confirmedSession($user, ['station_id' => null, 'charging_type' => ChargingType::HOME]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/breakdowns?dimension=station');

    $labels = collect($response->json('data'))->pluck('label');

    expect($labels)->toContain('Unspecified')
        ->and(collect($response->json('data'))->sum('session_count'))->toBe(2);
});

it('breaks spend down by network through the station join', function (): void {
    $user = User::factory()->create();
    $station = ChargingStation::factory()->create();
    confirmedSession($user, ['station_id' => $station->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/dashboard/breakdowns?dimension=network');

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('label'))
        ->toContain($station->network->name);
});

it('lets an admin scope the dashboard to one user', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();
    confirmedSession($target);
    confirmedSession(User::factory()->create());

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/dashboard/summary?user_id='.$target->id);

    expect($response->json('data.summary.session_count'))->toBe(1);
});

it('ignores a user_id sent by a non-admin (AT-007)', function (): void {
    // Otherwise anyone could read another user's spending totals.
    $user = User::factory()->create();
    $victim = User::factory()->create();
    confirmedSession($victim);
    confirmedSession($victim);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/dashboard/summary?user_id='.$victim->id);

    expect($response->json('data.summary.session_count'))->toBe(0);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/dashboard/summary')->assertStatus(401);
});

it('does not lose precision across many sessions', function (): void {
    // Ten sessions of 0.10 must total exactly 1.00.
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        confirmedSession($user, [
            'subtotal' => '0.10',
            'vat_amount' => '0.00',
            'total_amount' => '0.10',
        ]);
    }

    $totals = app(AnalyticsService::class)->totals(
        new AnalyticsFilter(userId: $user->id)
    );

    expect($totals['total_cost'])->toBe('1.00');
});
