<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Vehicle;

/*
 * AT-007: a user must never reach another user's records.
 */

it('lists only the vehicles owned by the caller', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Vehicle::factory()->count(2)->create(['user_id' => $user->id]);
    Vehicle::factory()->count(3)->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/vehicles');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('hides another user\'s vehicle behind a 404 rather than a 403', function (): void {
    // A 403 would confirm the id exists; both cases must look identical.
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $victim->id]);

    $this->actingAs($user)
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('refuses to update another user\'s vehicle', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $victim->id, 'make' => 'BYD']);

    $this->actingAs($user)
        ->putJson("/api/v1/vehicles/{$vehicle->id}", ['make' => 'Hacked'])
        ->assertStatus(404);

    expect($vehicle->fresh()->make)->toBe('BYD');
});

it('refuses to delete another user\'s vehicle', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $victim->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertStatus(404);

    expect(Vehicle::whereKey($vehicle->id)->exists())->toBeTrue();
});

it('lets an admin read any user\'s vehicle', function (): void {
    $admin = User::factory()->admin()->create();
    $vehicle = Vehicle::factory()->create();

    $this->actingAs($admin)
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $vehicle->id);
});

it('forbids a viewer from creating a vehicle', function (): void {
    // The viewer role is read-only, whatever the ownership.
    $viewer = User::factory()->viewer()->create();

    $this->actingAs($viewer)
        ->postJson('/api/v1/vehicles', ['make' => 'BYD', 'model' => 'Atto 3'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect(Vehicle::count())->toBe(0);
});

it('forbids a viewer from updating their own vehicle', function (): void {
    $viewer = User::factory()->viewer()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $viewer->id, 'make' => 'BYD']);

    $this->actingAs($viewer)
        ->putJson("/api/v1/vehicles/{$vehicle->id}", ['make' => 'Changed'])
        ->assertStatus(403);

    expect($vehicle->fresh()->make)->toBe('BYD');
});

it('lets a viewer read their own vehicle', function (): void {
    $viewer = User::factory()->viewer()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)
        ->getJson("/api/v1/vehicles/{$vehicle->id}")
        ->assertOk();
});

it('ignores a client-supplied user_id when creating a vehicle', function (): void {
    // Ownership must come from the authenticated user, never from input.
    $user = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/vehicles', [
            'make' => 'BYD',
            'model' => 'Atto 3',
            'user_id' => $victim->id,
        ])
        ->assertCreated();

    expect(Vehicle::first()->user_id)->toBe($user->id);
});
