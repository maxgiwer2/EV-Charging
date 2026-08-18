<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogService;

/*
 * AT-010: create/update/delete/verify actions must generate audit records.
 */

it('records a create with the resulting attributes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/vehicles', [
        'make' => 'BYD',
        'model' => 'Atto 3',
    ])->assertCreated();

    $log = AuditLog::where('action', AuditLog::ACTION_CREATE)
        ->where('entity_type', Vehicle::class)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->before_data)->toBeNull()
        ->and($log->after_data['make'])->toBe('BYD');
});

it('records only the changed attributes on update', function (): void {
    // A full-row snapshot on both sides would bury the change in noise.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id, 'make' => 'BYD']);

    $this->actingAs($user)
        ->putJson("/api/v1/vehicles/{$vehicle->id}", ['make' => 'Tesla'])
        ->assertOk();

    $log = AuditLog::where('action', AuditLog::ACTION_UPDATE)->first();

    expect($log)->not->toBeNull()
        ->and($log->before_data)->toHaveKey('make')
        ->and($log->before_data['make'])->toBe('BYD')
        ->and($log->after_data['make'])->toBe('Tesla')
        // Unchanged columns must not appear on either side.
        ->and($log->after_data)->not->toHaveKey('model');
});

it('writes no audit row when an update changes nothing', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id, 'make' => 'BYD']);

    $this->actingAs($user)
        ->putJson("/api/v1/vehicles/{$vehicle->id}", ['make' => 'BYD'])
        ->assertOk();

    expect(AuditLog::where('action', AuditLog::ACTION_UPDATE)->count())->toBe(0);
});

it('records a delete with the prior state', function (): void {
    // A soft-deleted financial record must stay explainable.
    $user = User::factory()->create();
    $session = ChargingSession::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/charging-sessions/{$session->id}")
        ->assertNoContent();

    $log = AuditLog::where('action', AuditLog::ACTION_DELETE)
        ->where('entity_type', ChargingSession::class)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->before_data)->not->toBeNull()
        ->and($log->after_data)->toBeNull();
});

it('captures the request IP and user agent', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', 'PestTest/1.0')
        ->postJson('/api/v1/vehicles', ['make' => 'MG', 'model' => 'ZS EV'])
        ->assertCreated();

    $log = AuditLog::where('action', AuditLog::ACTION_CREATE)->first();

    expect($log->user_agent)->toBe('PestTest/1.0')
        ->and($log->ip_address)->not->toBeNull();
});

it('redacts sensitive attributes (docs/10 rule 13)', function (): void {
    $user = User::factory()->create();
    $service = app(AuditLogService::class);

    $log = $service->log('TEST', $user, null, [
        'password' => 'super-secret',
        'remember_token' => 'abc123',
        'api_key' => 'k-123',
        'file_path' => 'receipts/private.jpg',
        'make' => 'BYD',
    ]);

    expect($log->after_data['password'])->toBe('[redacted]')
        ->and($log->after_data['remember_token'])->toBe('[redacted]')
        ->and($log->after_data['api_key'])->toBe('[redacted]')
        // Private storage paths must not leak into a trail admins can read.
        ->and($log->after_data['file_path'])->toBe('[redacted]')
        // Non-sensitive values are kept, otherwise the trail is useless.
        ->and($log->after_data['make'])->toBe('BYD');
});

it('truncates an over-long user agent instead of failing the write', function (): void {
    $user = User::factory()->create();

    $log = $this->withHeader('User-Agent', str_repeat('A', 900))
        ->actingAs($user)
        ->postJson('/api/v1/vehicles', ['make' => 'Ora', 'model' => 'Good Cat'])
        ->assertCreated();

    $entry = AuditLog::where('action', AuditLog::ACTION_CREATE)->first();

    expect(mb_strlen((string) $entry->user_agent))->toBeLessThanOrEqual(500);
});
