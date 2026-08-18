<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\ChargingSession;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\BudgetService;
use Illuminate\Support\Carbon;

/*
 * docs/02 FR-013 (monthly budget, thresholds 50/80/100, configurable)
 * and FR-014 (budget threshold alerts).
 */

/** A budget covering the current month. */
function currentBudget(User $user, string $amount, ?array $thresholds = null): Budget
{
    return Budget::factory()->create([
        'user_id' => $user->id,
        'amount' => $amount,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'alert_thresholds' => $thresholds,
    ]);
}

/** Confirmed spend of $total inside the current month. */
function spend(User $user, string $total): ChargingSession
{
    return ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDays(2),
        'energy_kwh' => '40.000',
        'subtotal' => $total,
        'vat_amount' => '0.00',
        'discount_amount' => '0.00',
        'total_amount' => $total,
    ]);
}

it('measures spend against the budget', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '250.00');

    $evaluation = app(BudgetService::class)->evaluateAll($user)[0];

    expect($evaluation['spent'])->toBe('250.00')
        ->and($evaluation['remaining'])->toBe('750.00')
        ->and($evaluation['percentage_used'])->toBe('25.00')
        ->and($evaluation['is_over_budget'])->toBeFalse();
});

it('agrees with the dashboard by ignoring drafts (AT-009)', function (): void {
    // A budget that disagreed with the dashboard would be worse than none.
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '250.00');
    ChargingSession::factory()->draft()->create([
        'user_id' => $user->id,
        'started_at' => now()->startOfMonth()->addDays(2),
        'total_amount' => '9999.00',
    ]);

    expect(app(BudgetService::class)->evaluateAll($user)[0]['spent'])->toBe('250.00');
});

it('reports how far over budget, not a clamped zero', function (): void {
    // "How far over" is the useful reading.
    $user = User::factory()->create();
    currentBudget($user, '100.00');
    spend($user, '250.00');

    $evaluation = app(BudgetService::class)->evaluateAll($user)[0];

    expect($evaluation['remaining'])->toBe('-150.00')
        ->and($evaluation['percentage_used'])->toBe('250.00')
        ->and($evaluation['is_over_budget'])->toBeTrue();
});

it('uses the documented default thresholds', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');

    expect(app(BudgetService::class)->evaluateAll($user)[0]['thresholds'])->toBe([50, 80, 100]);
});

it('honours thresholds configured on the budget (FR-013)', function (): void {
    // docs/02 calls them configurable; docs/10 rule 9 forbids hard-coding.
    $user = User::factory()->create();
    currentBudget($user, '1000.00', [25, 90]);
    spend($user, '300.00');

    $evaluation = app(BudgetService::class)->evaluateAll($user)[0];

    expect($evaluation['thresholds'])->toBe([25, 90])
        ->and($evaluation['thresholds_reached'])->toBe([25]);
});

it('reports every threshold passed, not just the highest', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '850.00');

    expect(app(BudgetService::class)->evaluateAll($user)[0]['thresholds_reached'])->toBe([50, 80]);
});

it('notifies once per threshold, ever (FR-014)', function (): void {
    // Alerts people learn to ignore are worse than none: a user sitting just
    // above 80% must not be told again on every evaluation.
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '850.00');

    $service = app(BudgetService::class);
    $service->evaluateAndNotify($user);
    $service->evaluateAndNotify($user);
    $service->evaluateAndNotify($user);

    expect(Notification::where('user_id', $user->id)
        ->where('type', Notification::TYPE_BUDGET_THRESHOLD)->count())->toBe(2);
});

it('raises a new alert when a further threshold is crossed', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '550.00');

    $service = app(BudgetService::class);
    $service->evaluateAndNotify($user);

    expect(Notification::where('type', Notification::TYPE_BUDGET_THRESHOLD)->count())->toBe(1);

    spend($user, '400.00');
    $service->evaluateAndNotify($user);

    // 95% now, so the 80 alert fires and 50 does not repeat.
    expect(Notification::where('type', Notification::TYPE_BUDGET_THRESHOLD)->count())->toBe(2);
});

it('titles the 100% alert as exceeded', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '100.00');
    spend($user, '150.00');

    app(BudgetService::class)->evaluateAndNotify($user);

    expect(Notification::where('type', Notification::TYPE_BUDGET_THRESHOLD)
        ->latest('id')->first()->title)->toContain('exceeded');
});

it('counts a charge on the final local evening of the period', function (): void {
    // The upper bound is exclusive but must cover the whole last day *in the
    // user's timezone*. 22:30 Bangkok on the 31st is 15:30 UTC, which a naive
    // UTC window would have cut off.
    $tz = config('app.display_timezone');
    $user = User::factory()->create();
    currentBudget($user, '1000.00');

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse(now()->endOfMonth()->toDateString().' 22:30', $tz)->utc(),
        'total_amount' => '300.00',
    ]);

    expect(app(BudgetService::class)->evaluateAll($user)[0]['spent'])->toBe('300.00');
});

it('excludes a charge that falls into the next local day', function (): void {
    // 00:30 on the 1st is a September charge, even though it is still the 31st
    // in UTC. Counting it would inflate August's budget.
    $tz = config('app.display_timezone');
    $user = User::factory()->create();
    currentBudget($user, '1000.00');

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse(now()->endOfMonth()->addDay()->toDateString().' 00:30', $tz)->utc(),
        'total_amount' => '300.00',
    ]);

    expect(app(BudgetService::class)->evaluateAll($user)[0]['spent'])->toBe('0.00');
});

it('ignores spend outside the budget period', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subMonth()->startOfMonth()->addDay(),
        'total_amount' => '500.00',
    ]);

    expect(app(BudgetService::class)->evaluateAll($user)[0]['spent'])->toBe('0.00');
});

it('never counts another user\'s spending (AT-007)', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($other, '900.00');

    expect(app(BudgetService::class)->evaluateAll($user)[0]['spent'])->toBe('0.00');
});

// ------------------------------------------------------------------------- API

it('creates a budget owned by the caller', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/budgets', [
        'amount' => 3000,
        'period' => 'MONTHLY',
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ])->assertCreated();

    expect(Budget::first()->user_id)->toBe($user->id);
});

it('rejects a zero budget', function (): void {
    // It has no meaningful percentage and every charge would be "over".
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/budgets', [
            'amount' => 0,
            'period' => 'MONTHLY',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])
        ->assertStatus(422);
});

it('rejects duplicate thresholds', function (): void {
    // A repeated threshold would fire twice for one crossing.
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/budgets', [
            'amount' => 1000,
            'period' => 'MONTHLY',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'alert_thresholds' => [50, 50, 80],
        ])
        ->assertStatus(422);
});

it('hides another user\'s budget behind a 404', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/budgets/'.Budget::factory()->create()->id)
        ->assertStatus(404);
});

it('forbids a viewer from setting a budget', function (): void {
    $this->actingAs(User::factory()->viewer()->create())
        ->postJson('/api/v1/budgets', [
            'amount' => 1000,
            'period' => 'MONTHLY',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])
        ->assertStatus(403);
});

it('returns budget status over the API', function (): void {
    $user = User::factory()->create();
    currentBudget($user, '1000.00');
    spend($user, '600.00');

    $response = $this->actingAs($user)->getJson('/api/v1/budgets/status');

    $response->assertOk();

    expect($response->json('data.0.percentage_used'))->toBe('60.00')
        ->and($response->json('data.0.thresholds_reached'))->toBe([50]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/budgets/status')->assertStatus(401);
});
