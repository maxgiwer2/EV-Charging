<?php

declare(strict_types=1);

use App\Models\ChargingSession;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AnomalyDetectionService;
use App\Services\ForecastService;
use App\Support\Anomaly;
use App\Support\Statistics;

/*
 * docs/02 FR-018 (anomaly detection, forecasting) and FR-014 (alerts).
 */

/**
 * $count ordinary sessions: 40 kWh for 214.00, i.e. 5.35/kWh.
 *
 * @return list<ChargingSession>
 */
function ordinarySessions(User $user, int $count, ?Vehicle $vehicle = null): array
{
    $vehicle ??= Vehicle::factory()->create(['user_id' => $user->id]);
    $sessions = [];

    foreach (range(1, $count) as $i) {
        $sessions[] = ChargingSession::factory()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->subDays($count - $i + 1),
            'energy_kwh' => '40.000',
            'distance_km' => '200.0',
            'subtotal' => '200.00',
            'vat_amount' => '14.00',
            'discount_amount' => '0.00',
            'total_amount' => '214.00',
        ]);
    }

    return $sessions;
}

// ------------------------------------------------------------------ statistics

it('uses a median that an outlier cannot move', function (): void {
    // The whole reason the detector is median-based.
    $withoutOutlier = Statistics::median(['10', '10', '10', '10', '10']);
    $withOutlier = Statistics::median(['10', '10', '10', '10', '9999']);

    expect($withoutOutlier)->toBe($withOutlier);
});

it('averages the two central values for an even count', function (): void {
    expect(Statistics::median(['10', '20']))->toBe(bcdiv('30', '2', 8));
});

it('cannot score against a zero spread', function (): void {
    // Every observation identical: "how unusual is this" has no answer, and
    // returning 0 would read as "perfectly normal".
    expect(Statistics::modifiedZScore('9999', '10', '0'))->toBeNull();
});

// -------------------------------------------------------------------- anomalies

it('flags a session that cost far more per kWh than usual', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    // Same energy, four times the price.
    $expensive = ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => '800.00',
        'vat_amount' => '56.00',
        'total_amount' => '856.00',
    ]);

    $found = app(AnomalyDetectionService::class)->detect($user);

    expect(collect($found)->pluck('session.id'))->toContain($expensive->id)
        ->and($found[0]->reasons)->toContain(Anomaly::REASON_UNIT_COST);
});

it('stays silent without enough history to judge', function (): void {
    // Below the minimum every early session looks extreme against the two
    // before it, which would be pure alarm.
    $user = User::factory()->create();
    ordinarySessions($user, 3);

    expect(app(AnomalyDetectionService::class)->detect($user))->toBe([]);
});

it('does not flag ordinary variation', function (): void {
    $user = User::factory()->create();
    ordinarySessions($user, 12);

    expect(app(AnomalyDetectionService::class)->detect($user))->toBe([]);
});

it('is not blinded by the outlier it is looking for', function (): void {
    // A mean/stddev detector fails here: the extreme value inflates the
    // standard deviation enough to hide itself.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => '5000.00',
        'vat_amount' => '350.00',
        'total_amount' => '5350.00',
    ]);

    $found = app(AnomalyDetectionService::class)->detect($user);

    expect($found)->not->toBe([])
        ->and($found[0]->severity)->toBe('high');
});

it('does not flag a cheaper than usual charge', function (): void {
    // Good news is not worth interrupting someone about.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => '10.00',
        'vat_amount' => '0.70',
        'total_amount' => '10.70',
    ]);

    expect(app(AnomalyDetectionService::class)->detect($user))->toBe([]);
});

it('never judges one user against another (AT-007)', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    ordinarySessions($other, 12);

    // The caller has no history of their own, so nothing can be judged.
    expect(app(AnomalyDetectionService::class)->detect($user))->toBe([]);
});

it('ignores drafts and cancellations when forming the baseline', function (): void {
    // A draft is not fact and a cancellation never happened (AT-009).
    $user = User::factory()->create();
    ordinarySessions($user, 4);
    ChargingSession::factory()->count(8)->draft()->create([
        'user_id' => $user->id,
        'total_amount' => '9999.00',
    ]);

    expect(app(AnomalyDetectionService::class)->detect($user))->toBe([]);
});

it('skips a session with no energy rather than treating it as free', function (): void {
    // A zero unit cost would drag the baseline down and make genuinely
    // expensive sessions look normal (docs/06).
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => null,
        'total_amount' => '214.00',
    ]);

    $found = app(AnomalyDetectionService::class)->detect($user);

    $unitCostReasons = collect($found)
        ->flatMap(fn (Anomaly $a): array => $a->reasons)
        ->filter(fn (string $r): bool => $r === Anomaly::REASON_UNIT_COST);

    // The session is simply not judged on a measure it did not record.
    expect($unitCostReasons)->toBeEmpty();
});

it('notifies once per session (FR-014)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => '2000.00',
        'total_amount' => '2140.00',
    ]);

    $service = app(AnomalyDetectionService::class);
    $service->detectAndNotify($user);
    // Re-running must not spam the user with what they have already seen.
    $service->detectAndNotify($user);

    expect(Notification::where('user_id', $user->id)
        ->where('type', Notification::TYPE_ANOMALOUS_EXPENSE)->count())->toBe(1);
});

it('exposes anomalies over the API', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    ordinarySessions($user, 10, $vehicle);

    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => '2000.00',
        'total_amount' => '2140.00',
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/insights/anomalies');

    $response->assertOk();

    expect($response->json('data.0.reasons'))->toBeArray()
        // The method is stated so an empty list is not mistaken for
        // reassurance.
        ->and($response->json('meta.method'))->toContain('median');
});

// -------------------------------------------------------------------- forecast

it('refuses to project from too few days', function (): void {
    // A month projected from two days is a confident-looking number with
    // nothing behind it, and people act on those.
    $this->travelTo(now()->startOfMonth()->addDay());

    $user = User::factory()->create();
    ordinarySessions($user, 3);

    $forecast = app(ForecastService::class)->projectCurrentMonth($user);

    expect($forecast->available)->toBeFalse()
        ->and($forecast->unavailableReason)->toBe('too_early_in_period');
});

it('refuses to project from too few sessions', function (): void {
    $this->travelTo(now()->startOfMonth()->addDays(15));

    $user = User::factory()->create();
    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'started_at' => now()->subDay(),
        'total_amount' => '214.00',
    ]);

    $forecast = app(ForecastService::class)->projectCurrentMonth($user);

    expect($forecast->available)->toBeFalse()
        ->and($forecast->unavailableReason)->toBe('not_enough_sessions');
});

it('projects from the run rate', function (): void {
    // Half way through a 30-day month having spent 642.00 projects to 1284.00.
    $this->travelTo(now()->startOfMonth()->addDays(14)->setTime(12, 0));

    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 3) as $i) {
        ChargingSession::factory()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->startOfMonth()->addDays($i),
            'energy_kwh' => '40.000',
            'total_amount' => '214.00',
        ]);
    }

    $forecast = app(ForecastService::class)->projectCurrentMonth($user);

    expect($forecast->available)->toBeTrue()
        ->and($forecast->spentToDate)->toBe('642.00')
        ->and($forecast->elapsedDays)->toBe(15)
        // 642 / 15 days = 42.80 per day
        ->and($forecast->dailyRate)->toBe('42.80');
});

it('states its caveats rather than burying them', function (): void {
    // A user comparing a projection with their budget deserves to know it
    // rests on a handful of sessions.
    $this->travelTo(now()->startOfMonth()->addDays(6));

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

    $forecast = app(ForecastService::class)->projectCurrentMonth($user);

    expect($forecast->caveats)->toContain('few_sessions')
        ->and($forecast->caveats)->toContain('no_previous_period');
});

it('skips months with no charging when computing a typical month', function (): void {
    // A month away should not drag "typical" down as though the user drove
    // normally and spent nothing.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    foreach ([1, 2] as $monthsAgo) {
        ChargingSession::factory()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->startOfMonth()->subMonths($monthsAgo)->addDays(3),
            'total_amount' => '1000.00',
        ]);
    }

    expect(app(ForecastService::class)->typicalMonthlySpend($user))->toBe('1000.00');
});

it('exposes the forecast over the API as advisory', function (): void {
    $this->travelTo(now()->startOfMonth()->addDays(14));

    $user = User::factory()->create();
    ordinarySessions($user, 4);

    $response = $this->actingAs($user)->getJson('/api/v1/insights/forecast');

    $response->assertOk();

    // A client must never render a projection as a commitment.
    expect($response->json('meta.advisory'))->toBeTrue()
        ->and($response->json('meta.method'))->toBe('run_rate');
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/insights/anomalies')->assertStatus(401);
    $this->getJson('/api/v1/insights/forecast')->assertStatus(401);
});
