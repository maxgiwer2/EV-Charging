<?php

declare(strict_types=1);

use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use App\Jobs\ProcessReceiptOcr;
use App\Models\ChargingSession;
use App\Models\Receipt;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * docs/04 Quick Entry and Scan/Upload, plus vehicle management (FR-002).
 */

// ---------------------------------------------------------------- quick entry

it('records and confirms a charge in one step (docs/04 Quick Entry)', function (): void {
    // Unlike a receipt there is no second source to reconcile against, so
    // leaving it in DRAFT would just hide the charge from the dashboard.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('sessions.quick-entry.store'), [
            'vehicle_id' => $vehicle->id,
            'charging_type' => 'HOME',
            'started_at' => now()->format('Y-m-d\TH:i'),
            'energy_kwh' => 30,
            'total' => 225,
        ])
        ->assertRedirect(route('dashboard'));

    $session = ChargingSession::first();

    expect($session->status)->toBe(SessionStatus::CONFIRMED)
        ->and($session->total_amount)->toBe('225.00')
        ->and($session->energy_source)->toBe(EnergySource::MANUAL);
});

it('derives the amount from energy times rate', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post(route('sessions.quick-entry.store'), [
        'vehicle_id' => $vehicle->id,
        'charging_type' => 'HOME',
        'started_at' => now()->format('Y-m-d\TH:i'),
        'energy_kwh' => 42.5,
        'unit_price' => 7.4567,
    ])->assertRedirect();

    // Rounded once, at the end.
    expect(ChargingSession::first()->total_amount)->toBe('316.91');
});

it('refuses a quick entry against another user\'s vehicle (AT-007)', function (): void {
    $user = User::factory()->create();
    $theirs = Vehicle::factory()->create();

    $this->actingAs($user)
        ->from(route('sessions.quick-entry'))
        ->post(route('sessions.quick-entry.store'), [
            'vehicle_id' => $theirs->id,
            'charging_type' => 'HOME',
            'started_at' => now()->format('Y-m-d\TH:i'),
            'energy_kwh' => 30,
        ])
        ->assertSessionHasErrors('vehicle_id');

    expect(ChargingSession::count())->toBe(0);
});

it('forbids a viewer from quick entry', function (): void {
    $viewer = User::factory()->viewer()->create();
    Vehicle::factory()->create(['user_id' => $viewer->id]);

    $this->actingAs($viewer)->get(route('sessions.quick-entry'))->assertForbidden();
});

it('points a user with no vehicles at the vehicle form', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('sessions.quick-entry'))
        ->assertOk()
        ->assertSee('Add a vehicle first');
});

// -------------------------------------------------------------- receipt upload

it('uploads a receipt and queues OCR', function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('receipts.upload.store'), [
            'file' => UploadedFile::fake()->image('receipt.jpg', 600, 800),
        ])
        ->assertRedirect();

    expect(Receipt::count())->toBe(1);
    Queue::assertPushed(ProcessReceiptOcr::class);
});

it('applies the same magic-byte check as the API', function (): void {
    // A browser upload is not more trustworthy than an API one.
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $this->actingAs(User::factory()->create())
        ->from(route('receipts.upload'))
        ->post(route('receipts.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('payload.jpg', "<?php echo 'pwned';"),
        ])
        ->assertSessionHasErrors('file');

    expect(Receipt::count())->toBe(0);
});

it('says so when a duplicate is flagged rather than accepting it silently', function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $user = User::factory()->create();
    $source = UploadedFile::fake()->image('r.jpg', 400, 400);
    $contents = (string) file_get_contents((string) $source->getRealPath());

    foreach (range(1, 2) as $ignored) {
        $response = $this->actingAs($user)->post(route('receipts.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('r.jpg', $contents),
        ]);
    }

    expect(session('status'))->toContain('already on file');
});

it('warns when no OCR provider is configured', function (): void {
    config()->set('ocr.driver', 'none');

    $this->actingAs(User::factory()->create())
        ->get(route('receipts.upload'))
        ->assertOk()
        ->assertSee('No OCR provider is configured');
});

it('forbids a viewer from uploading', function (): void {
    $this->actingAs(User::factory()->viewer()->create())
        ->get(route('receipts.upload'))
        ->assertForbidden();
});

// -------------------------------------------------------------------- vehicles

it('lists only the caller\'s vehicles (AT-007)', function (): void {
    $user = User::factory()->create();
    Vehicle::factory()->create(['user_id' => $user->id, 'make' => 'BYD', 'model' => 'Atto 3']);
    Vehicle::factory()->create(['make' => 'Tesla', 'model' => 'Model Y']);

    $this->actingAs($user)
        ->get(route('vehicles.manage.index'))
        ->assertOk()
        ->assertSee('BYD Atto 3')
        ->assertDontSee('Tesla Model Y');
});

it('adds a vehicle owned by the caller', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('vehicles.manage.store'), [
            'make' => 'MG',
            'model' => 'ZS EV',
            'battery_kwh' => 50.3,
            'is_active' => 1,
        ])
        ->assertRedirect(route('vehicles.manage.index'));

    expect(Vehicle::first()->user_id)->toBe($user->id);
});

it('ignores a client-supplied user_id when adding a vehicle', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();

    $this->actingAs($user)->post(route('vehicles.manage.store'), [
        'make' => 'MG',
        'model' => 'ZS EV',
        'user_id' => $victim->id,
    ])->assertRedirect();

    expect(Vehicle::first()->user_id)->toBe($user->id);
});

it('rejects a zero battery capacity', function (): void {
    // It divides into SOC-based energy estimates (FR-009).
    $this->actingAs(User::factory()->create())
        ->from(route('vehicles.manage.create'))
        ->post(route('vehicles.manage.store'), [
            'make' => 'MG',
            'model' => 'ZS EV',
            'battery_kwh' => 0,
        ])
        ->assertSessionHasErrors('battery_kwh');
});

it('refuses to edit another user\'s vehicle', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('vehicles.manage.edit', Vehicle::factory()->create()))
        ->assertNotFound();
});

it('soft deletes a vehicle so history survives', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('vehicles.manage.destroy', $vehicle))
        ->assertRedirect(route('vehicles.manage.index'));

    expect(Vehicle::whereKey($vehicle->id)->exists())->toBeFalse()
        ->and(Vehicle::withTrashed()->whereKey($vehicle->id)->exists())->toBeTrue();
});

it('forbids a viewer from adding a vehicle', function (): void {
    $this->actingAs(User::factory()->viewer()->create())
        ->post(route('vehicles.manage.store'), ['make' => 'MG', 'model' => 'ZS EV'])
        ->assertForbidden();

    expect(Vehicle::count())->toBe(0);
});
