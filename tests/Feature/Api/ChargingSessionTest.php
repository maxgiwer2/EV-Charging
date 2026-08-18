<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\ChargingConnector;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\User;
use App\Models\Vehicle;

/*
 * docs/02 FR-003, AT-002, AT-007.
 */

it('creates a session as DRAFT rather than confirmed', function (): void {
    // Totals are produced by the cost engine (M3); a new entry is not
    // financial fact until confirmed, so it must not count toward totals.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->subHour()->toIso8601String(),
            'charging_type' => 'PUBLIC',
            'energy_kwh' => 42.5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', SessionStatus::DRAFT->value);
});

it('derives duration from the timestamps when not supplied', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $start = now()->subHours(2);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => $start->toIso8601String(),
            'ended_at' => $start->copy()->addMinutes(37)->toIso8601String(),
            'charging_type' => 'PUBLIC',
        ])
        ->assertCreated()
        ->assertJsonPath('data.duration_minutes', 37);
});

it('leaves duration null when the session has no end time', function (): void {
    // Better an honest null than a zero that reads as an instant charge.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->toIso8601String(),
            'charging_type' => 'HOME',
        ])
        ->assertCreated()
        ->assertJsonPath('data.duration_minutes', null);
});

it('refuses to attach a session to another user\'s vehicle', function (): void {
    // Without this the attacker could pollute a victim's reports even though
    // they could never read them back (AT-007).
    $user = User::factory()->create();
    $victimVehicle = Vehicle::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $victimVehicle->id,
            'started_at' => now()->toIso8601String(),
            'charging_type' => 'PUBLIC',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(ChargingSession::count())->toBe(0);
});

it('rejects a state of charge that decreases', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->toIso8601String(),
            'charging_type' => 'PUBLIC',
            'soc_before' => 80,
            'soc_after' => 40,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.soc_after.0', 'State of charge after charging must not be lower than before.');
});

it('rejects an end time before the start time', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->toIso8601String(),
            'ended_at' => now()->subHour()->toIso8601String(),
            'charging_type' => 'PUBLIC',
        ])
        ->assertStatus(422);
});

it('rejects a connector that belongs to a different station', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $stationA = ChargingStation::factory()->create();
    $connectorAtB = ChargingConnector::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'station_id' => $stationA->id,
            'connector_id' => $connectorAtB->id,
            'started_at' => now()->toIso8601String(),
            'charging_type' => 'PUBLIC',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.connector_id.0', 'The connector does not belong to the selected station.');
});

it('ignores client-supplied money fields', function (): void {
    // Totals come from the cost engine, never from request input
    // (docs/10 rules 3 and 10).
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/v1/charging-sessions', [
            'vehicle_id' => $vehicle->id,
            'started_at' => now()->toIso8601String(),
            'charging_type' => 'PUBLIC',
            'total_amount' => 99999.99,
            'subtotal' => 99999.99,
        ])
        ->assertCreated()
        ->assertJsonPath('data.total_amount', '0.00');
});

it('soft deletes a session so financial history survives', function (): void {
    $user = User::factory()->create();
    $session = ChargingSession::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/charging-sessions/{$session->id}")
        ->assertNoContent();

    expect(ChargingSession::whereKey($session->id)->exists())->toBeFalse()
        ->and(ChargingSession::withTrashed()->whereKey($session->id)->exists())->toBeTrue();
});

it('does not list another user\'s sessions', function (): void {
    $user = User::factory()->create();
    ChargingSession::factory()->count(2)->create(['user_id' => $user->id]);
    ChargingSession::factory()->count(4)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/charging-sessions');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});
