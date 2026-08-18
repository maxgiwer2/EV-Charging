<?php

declare(strict_types=1);

use App\Enums\ChargingType;
use App\Enums\TimeBand;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Models\ChargingTariff;
use App\Models\TariffVersion;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ChargingSessionService;
use App\Services\TariffService;

/*
 * docs/02 FR-007, docs/04 Admin Tariff, AT-006.
 */

// ------------------------------------------------------------ authorization

it('lets any authenticated user read tariffs', function (): void {
    // A user is entitled to see the rate they were charged.
    ChargingTariff::factory()->count(2)->create();

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/tariffs')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('forbids a normal user from publishing a tariff', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/tariffs', ['name' => 'Rogue', 'charging_type' => 'PUBLIC'])
        ->assertStatus(403);

    expect(ChargingTariff::count())->toBe(0);
});

it('lets an admin publish a tariff and a version', function (): void {
    $admin = User::factory()->admin()->create();

    $tariff = $this->actingAs($admin)->postJson('/api/v1/tariffs', [
        'name' => 'DC public rate',
        'charging_type' => 'PUBLIC',
    ])->assertCreated()->json('data.id');

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff}/versions", [
            'energy_rate' => 7.5,
            'vat_rate' => 7,
            'effective_from' => now()->subMonth()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.energy_rate', '7.5000')
        ->assertJsonPath('data.is_locked', false);
});

// --------------------------------------------------------- overlap validation

it('rejects a version that overlaps an existing period (docs/04)', function (): void {
    // MySQL cannot express a non-overlap constraint over a range, so the
    // service enforces it. Two versions covering one instant would make
    // pricing depend on row order.
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'effective_from' => '2026-01-01 00:00:00',
        'effective_to' => '2026-06-30 00:00:00',
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
            'energy_rate' => 8,
            'effective_from' => '2026-03-01T00:00:00Z',
            'effective_to' => '2026-09-01T00:00:00Z',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TARIFF_OVERLAP');
});

it('allows peak and off-peak versions to share dates', function (): void {
    // They cover the same days but different hours, so they do not clash.
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    TariffVersion::factory()->peak()->create([
        'charging_tariff_id' => $tariff->id,
        'effective_from' => '2026-01-01 00:00:00',
        'effective_to' => '2026-12-31 00:00:00',
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
            'energy_rate' => 4,
            'time_band' => TimeBand::OFF_PEAK->value,
            'effective_from' => '2026-01-01T00:00:00Z',
            'effective_to' => '2026-12-31T00:00:00Z',
        ])
        ->assertCreated();
});

it('closes the open-ended version when a new one starts', function (): void {
    // The timeline must have no instant covered twice and no gap.
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    $old = TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'effective_from' => '2026-01-01 00:00:00',
        'effective_to' => null,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
            'energy_rate' => 9,
            'effective_from' => '2026-07-01T00:00:00Z',
        ])
        ->assertCreated();

    expect($old->refresh()->effective_to->toDateString())->toBe('2026-07-01');
});

it('rejects a version that ends before it starts', function (): void {
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
            'energy_rate' => 7,
            'effective_from' => '2026-06-01T00:00:00Z',
            'effective_to' => '2026-01-01T00:00:00Z',
        ])
        ->assertStatus(422);
});

it('rejects an inverted power band', function (): void {
    // It would match nothing, silently leaving that range unpriced.
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    $this->actingAs($admin)
        ->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
            'energy_rate' => 7,
            'power_min_kw' => 100,
            'power_max_kw' => 50,
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.power_max_kw.0', 'The upper power bound must be above the lower bound.');
});

// ------------------------------------------------------------- immutability

it('refuses to edit a version that has priced a session (AT-006)', function (): void {
    // The version is evidence, not configuration: changing its rates would
    // silently rewrite a historical total.
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();
    $version = TariffVersion::factory()->create(['charging_tariff_id' => $tariff->id]);

    ChargingSession::factory()->create(['tariff_version_id' => $version->id]);

    $this->actingAs($admin)
        ->putJson("/api/v1/tariffs/{$tariff->id}/versions/{$version->id}", [
            'energy_rate' => 99,
            'effective_from' => $version->effective_from->toIso8601String(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($version->refresh()->energy_rate)->not->toBe('99.0000');
});

it('still refuses when the referencing session was soft deleted', function (): void {
    // A deleted financial record is still a record; its total must stay
    // explainable (docs/10 rule 15).
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();
    $version = TariffVersion::factory()->create(['charging_tariff_id' => $tariff->id]);

    ChargingSession::factory()->create(['tariff_version_id' => $version->id])->delete();

    $this->actingAs($admin)
        ->putJson("/api/v1/tariffs/{$tariff->id}/versions/{$version->id}", [
            'energy_rate' => 99,
            'effective_from' => $version->effective_from->toIso8601String(),
        ])
        ->assertStatus(409);
});

it('allows editing a version no session has used', function (): void {
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();
    $version = TariffVersion::factory()->create(['charging_tariff_id' => $tariff->id]);

    $this->actingAs($admin)
        ->putJson("/api/v1/tariffs/{$tariff->id}/versions/{$version->id}", [
            'energy_rate' => 6.25,
            'effective_from' => $version->effective_from->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.energy_rate', '6.2500');
});

it('hides a version belonging to a different tariff', function (): void {
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();
    $otherVersion = TariffVersion::factory()->create();

    $this->actingAs($admin)
        ->putJson("/api/v1/tariffs/{$tariff->id}/versions/{$otherVersion->id}", [
            'energy_rate' => 5,
            'effective_from' => now()->toIso8601String(),
        ])
        ->assertStatus(404);
});

// ----------------------------------------------------------------- pricing

it('prices a session from the tariff in force and snapshots the version', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $station = ChargingStation::factory()->create();

    $tariff = ChargingTariff::factory()->create([
        'charging_type' => ChargingType::PUBLIC,
        'station_id' => $station->id,
    ]);
    $version = TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '7.5000',
        'service_fee' => '0.00',
        'parking_fee' => '0.00',
        'vat_rate' => '7.000',
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    $session = ChargingSession::factory()->draft()->create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'station_id' => $station->id,
        'charging_type' => ChargingType::PUBLIC,
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'subtotal' => 0, 'vat_amount' => 0, 'total_amount' => 0,
    ]);

    app(ChargingSessionService::class)->applyAmounts($session, ['energy_kwh' => '40.000']);

    $session->refresh();

    // 40 * 7.5 = 300, VAT 7% = 21, total 321
    expect($session->subtotal)->toBe('300.00')
        ->and($session->vat_amount)->toBe('21.00')
        ->and($session->total_amount)->toBe('321.00')
        // The snapshot that makes this reproducible (AT-006).
        ->and($session->tariff_version_id)->toBe($version->id);
});

it('lets a supplied amount override the tariff', function (): void {
    // What the driver was actually billed is the fact; a tariff is only an
    // expectation of it.
    $user = User::factory()->create();
    $station = ChargingStation::factory()->create();
    $tariff = ChargingTariff::factory()->create([
        'charging_type' => ChargingType::PUBLIC,
        'station_id' => $station->id,
    ]);
    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '7.5000',
        'effective_from' => now()->subYear(),
    ]);

    $session = ChargingSession::factory()->draft()->create([
        'user_id' => $user->id,
        'station_id' => $station->id,
        'charging_type' => ChargingType::PUBLIC,
        'started_at' => now(),
        'energy_kwh' => '40.000',
    ]);

    app(ChargingSessionService::class)->applyAmounts($session, [
        'energy_kwh' => '40.000',
        'total' => '250.00',
        'subtotal' => '250.00',
    ]);

    expect($session->refresh()->total_amount)->toBe('250.00');
});

it('leaves a session unpriced when no tariff applies', function (): void {
    // Better an obvious zero the user can correct than someone else's rate
    // applied silently.
    $session = ChargingSession::factory()->draft()->create([
        'started_at' => now(),
        'energy_kwh' => '40.000',
        'charging_type' => ChargingType::PUBLIC,
    ]);

    app(ChargingSessionService::class)->applyAmounts($session, ['energy_kwh' => '40.000']);

    expect($session->refresh()->tariff_version_id)->toBeNull()
        ->and($session->total_amount)->toBe('0.00');
});

it('does not apply VAT when the tariff does not state a rate', function (): void {
    // null vat_rate means "not specified", which is not the same as 0% and
    // must not add tax that was never charged (docs/10 rule 9).
    $tariff = ChargingTariff::factory()->create();
    $version = TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '7.0000',
        'service_fee' => '0.00',
        'parking_fee' => '0.00',
        'vat_rate' => null,
    ]);

    $priced = app(TariffService::class)->priceSession($version, '10.000');

    expect($priced['subtotal'])->toBe('70.00')
        ->and($priced['vat'])->toBeNull()
        ->and($priced['total'])->toBe('70.00');
});

it('picks a historical version for a historical session (AT-006)', function (): void {
    // The whole point of versioning: an old charge keeps resolving to the
    // rate that applied then, not to today's.
    $station = ChargingStation::factory()->create();
    $tariff = ChargingTariff::factory()->create([
        'charging_type' => ChargingType::PUBLIC,
        'station_id' => $station->id,
    ]);

    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '5.0000',
        'effective_from' => now()->subYears(2),
        'effective_to' => now()->subYear(),
    ]);
    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '9.0000',
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);

    $oldSession = ChargingSession::factory()->draft()->create([
        'station_id' => $station->id,
        'charging_type' => ChargingType::PUBLIC,
        'started_at' => now()->subMonths(18),
        'energy_kwh' => '10.000',
        'power_kw' => null,
    ]);

    $resolved = app(TariffService::class)->resolveForSession($oldSession);

    expect($resolved?->energy_rate)->toBe('5.0000');
});

it('honours a power band', function (): void {
    $station = ChargingStation::factory()->create();
    $tariff = ChargingTariff::factory()->create([
        'charging_type' => ChargingType::PUBLIC,
        'station_id' => $station->id,
    ]);

    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '6.0000',
        'power_min_kw' => 0, 'power_max_kw' => 50,
        'effective_from' => now()->subYear(),
    ]);
    TariffVersion::factory()->create([
        'charging_tariff_id' => $tariff->id,
        'energy_rate' => '9.0000',
        'power_min_kw' => 50, 'power_max_kw' => null,
        'effective_from' => now()->subYear(),
    ]);

    $fast = ChargingSession::factory()->draft()->create([
        'station_id' => $station->id,
        'charging_type' => ChargingType::PUBLIC,
        'started_at' => now(),
        'power_kw' => '120.00',
        'energy_kwh' => '10.000',
    ]);

    expect(app(TariffService::class)->resolveForSession($fast)?->energy_rate)->toBe('9.0000');
});

it('audits publishing a version (AT-010)', function (): void {
    $admin = User::factory()->admin()->create();
    $tariff = ChargingTariff::factory()->create();

    $this->actingAs($admin)->postJson("/api/v1/tariffs/{$tariff->id}/versions", [
        'energy_rate' => 7,
        'effective_from' => now()->toIso8601String(),
    ])->assertCreated();

    expect(AuditLog::where('entity_type', TariffVersion::class)
        ->where('action', AuditLog::ACTION_CREATE)->exists())->toBeTrue();
});
