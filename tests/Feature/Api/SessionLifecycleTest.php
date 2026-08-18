<?php

declare(strict_types=1);

use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ChargingSessionService;

/*
 * The P0 fix from docs/12: before this, CONFIRMED was written only by receipt
 * verification, so a manually entered charge could never count toward a total
 * and docs/04 Manual Entry could not reach "dashboard update".
 */

it('lets a manually entered session be confirmed (docs/04 Manual Entry)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $created = $this->actingAs($user)->postJson('/api/v1/charging-sessions', [
        'vehicle_id' => $vehicle->id,
        'started_at' => now()->subHour()->toIso8601String(),
        'charging_type' => 'HOME',
        'energy_kwh' => 30,
        'total' => 150,
    ])->assertCreated();

    $id = $created->json('data.id');

    // Created as DRAFT, so it does not count yet.
    expect(ChargingSession::find($id)->status)->toBe(SessionStatus::DRAFT);

    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', SessionStatus::CONFIRMED->value);

    expect(ChargingSession::find($id)->status)->toBe(SessionStatus::CONFIRMED);
});

it('is idempotent when confirming twice', function (): void {
    // A double-submitted form must not be an error.
    $user = User::factory()->create();
    $session = ChargingSession::factory()->draft()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson("/api/v1/charging-sessions/{$session->id}/confirm")->assertOk();
    $this->actingAs($user)->postJson("/api/v1/charging-sessions/{$session->id}/confirm")->assertOk();

    expect($session->refresh()->status)->toBe(SessionStatus::CONFIRMED);
});

it('cancels a confirmed session so it stops counting', function (): void {
    // A charge recorded in error must be retractable without deleting the row
    // and its audit trail (docs/10 rule 15).
    $user = User::factory()->create();
    $session = ChargingSession::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$session->id}/cancel", ['reason' => 'Duplicate entry'])
        ->assertOk()
        ->assertJsonPath('data.status', SessionStatus::CANCELLED->value);

    $session->refresh();

    expect($session->status)->toBe(SessionStatus::CANCELLED)
        ->and($session->notes)->toContain('Duplicate entry')
        // The row survives.
        ->and(ChargingSession::whereKey($session->id)->exists())->toBeTrue();
});

it('refuses to confirm a cancelled session directly', function (): void {
    $user = User::factory()->create();
    $session = ChargingSession::factory()->cancelled()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$session->id}/confirm")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});

it('reopens a cancelled session for correction', function (): void {
    $user = User::factory()->create();
    $session = ChargingSession::factory()->cancelled()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$session->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.status', SessionStatus::DRAFT->value);

    // ...and can then be confirmed again.
    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$session->id}/confirm")
        ->assertOk();

    expect($session->refresh()->status)->toBe(SessionStatus::CONFIRMED);
});

it('audits every status change (AT-010)', function (): void {
    $user = User::factory()->create();
    $session = ChargingSession::factory()->draft()->create(['user_id' => $user->id]);

    $this->actingAs($user)->postJson("/api/v1/charging-sessions/{$session->id}/confirm")->assertOk();

    $log = AuditLog::where('entity_type', ChargingSession::class)
        ->where('action', AuditLog::ACTION_UPDATE)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->before_data['status'])->toBe(SessionStatus::DRAFT->value)
        ->and($log->after_data['status'])->toBe(SessionStatus::CONFIRMED->value);
});

it('refuses to confirm another user\'s session', function (): void {
    $user = User::factory()->create();
    $theirs = ChargingSession::factory()->draft()->create();

    $this->actingAs($user)
        ->postJson("/api/v1/charging-sessions/{$theirs->id}/confirm")
        ->assertStatus(404);

    expect($theirs->refresh()->status)->toBe(SessionStatus::DRAFT);
});

it('forbids a viewer from confirming', function (): void {
    $viewer = User::factory()->viewer()->create();
    $session = ChargingSession::factory()->draft()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)
        ->postJson("/api/v1/charging-sessions/{$session->id}/confirm")
        ->assertStatus(403);
});

/*
 * FR-009 energy precedence, now actually enforced (docs/12 P0 #3).
 */

it('does not let a SOC estimate overwrite a billed figure (FR-009)', function (): void {
    $session = ChargingSession::factory()->create([
        'energy_kwh' => '42.500',
        'energy_source' => EnergySource::RECEIPT,
    ]);

    app(ChargingSessionService::class)->applyAmounts(
        $session,
        ['energy_kwh' => '99.000'],
        EnergySource::SOC_ESTIMATE,
    );

    expect($session->refresh()->energy_kwh)->toBe('42.500')
        ->and($session->energy_source)->toBe(EnergySource::RECEIPT);
});

it('lets a receipt figure replace a manual entry (FR-009)', function (): void {
    $session = ChargingSession::factory()->create([
        'energy_kwh' => '30.000',
        'energy_source' => EnergySource::MANUAL,
    ]);

    app(ChargingSessionService::class)->applyAmounts(
        $session,
        ['energy_kwh' => '42.500'],
        EnergySource::RECEIPT,
    );

    expect($session->refresh()->energy_kwh)->toBe('42.500')
        ->and($session->energy_source)->toBe(EnergySource::RECEIPT);
});

it('lets a corrected manual reading replace an earlier one', function (): void {
    // Equal precedence: a correction is a legitimate edit.
    $session = ChargingSession::factory()->create([
        'energy_kwh' => '30.000',
        'energy_source' => EnergySource::MANUAL,
    ]);

    app(ChargingSessionService::class)->applyAmounts(
        $session,
        ['energy_kwh' => '31.500'],
        EnergySource::MANUAL,
    );

    expect($session->refresh()->energy_kwh)->toBe('31.500');
});

it('derives energy from the SOC delta when nothing better exists', function (): void {
    $vehicle = Vehicle::factory()->create(['battery_kwh' => '60.000']);
    $session = ChargingSession::factory()->create([
        'vehicle_id' => $vehicle->id,
        'energy_kwh' => null,
        'energy_source' => null,
        'soc_before' => '20.00',
        'soc_after' => '80.00',
    ]);
    $session->setRelation('vehicle', $vehicle);

    app(ChargingSessionService::class)->applyAmounts($session, []);

    // 60 kWh * 60% = 36 kWh
    expect($session->refresh()->energy_kwh)->toBe('36.000')
        ->and($session->energy_source)->toBe(EnergySource::SOC_ESTIMATE);
});

it('does not invent energy when the battery capacity is unknown', function (): void {
    // An invented figure would flow straight into cost/kWh.
    $vehicle = Vehicle::factory()->create(['battery_kwh' => null]);
    $session = ChargingSession::factory()->create([
        'vehicle_id' => $vehicle->id,
        'energy_kwh' => null,
        'energy_source' => null,
        'soc_before' => '20.00',
        'soc_after' => '80.00',
    ]);
    $session->setRelation('vehicle', $vehicle);

    app(ChargingSessionService::class)->applyAmounts($session, []);

    expect($session->refresh()->energy_kwh)->toBeNull();
});
