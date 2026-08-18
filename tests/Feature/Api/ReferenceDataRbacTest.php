<?php

declare(strict_types=1);

use App\Models\ChargingNetwork;
use App\Models\ChargingStation;
use App\Models\User;

/*
 * Shared reference data (docs/02 FR-006): every authenticated user reads,
 * only admins write. Ownership cannot gate these -- nobody owns a network.
 */

it('lets any authenticated user list networks', function (): void {
    ChargingNetwork::factory()->count(3)->create();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/networks')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('lets a viewer read a station', function (): void {
    $station = ChargingStation::factory()->create();

    $this->actingAs(User::factory()->viewer()->create())
        ->getJson("/api/v1/stations/{$station->id}")
        ->assertOk();
});

it('forbids a normal user from creating a network', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/networks', ['name' => 'Rogue', 'code' => 'ROGUE'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect(ChargingNetwork::count())->toBe(0);
});

it('allows an admin to create a network', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/api/v1/networks', ['name' => 'PEA VOLTA', 'code' => 'pea_volta'])
        ->assertCreated()
        // Codes are normalised so uniqueness is not case-dependent.
        ->assertJsonPath('data.code', 'PEA_VOLTA');
});

it('rejects a duplicate network code', function (): void {
    ChargingNetwork::factory()->create(['code' => 'DUP']);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/api/v1/networks', ['name' => 'Other', 'code' => 'DUP'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('forbids a normal user from deleting a station', function (): void {
    $station = ChargingStation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/v1/stations/{$station->id}")
        ->assertStatus(403);

    expect(ChargingStation::whereKey($station->id)->exists())->toBeTrue();
});

it('soft deletes a station rather than removing the row', function (): void {
    $station = ChargingStation::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->deleteJson("/api/v1/stations/{$station->id}")
        ->assertNoContent();

    expect(ChargingStation::whereKey($station->id)->exists())->toBeFalse()
        ->and(ChargingStation::withTrashed()->whereKey($station->id)->exists())->toBeTrue();
});

it('rejects an out-of-range latitude', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/api/v1/stations', ['name' => 'Bad', 'latitude' => 120, 'longitude' => 100])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});
