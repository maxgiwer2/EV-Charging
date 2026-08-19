<?php

declare(strict_types=1);

use App\Models\Budget;
use App\Models\ChargingSession;
use App\Models\Receipt;
use App\Models\User;
use App\Models\Vehicle;

/*
 * Mobile layout guarantees (docs/03 -> responsive/mobile-first).
 *
 * These assert the structural decisions rather than pixel measurements, which
 * a headless test cannot see. The measurements that drove them were taken in a
 * real 375px viewport and are recorded in docs/15-ui.md.
 */

it('offers a thumb-reachable primary action on every authenticated page', function (): void {
    // On the previous layout "+ Add" was a 17px link at the top of an
    // ~1800px page. It is now a 56px control fixed to the bottom bar.
    $user = User::factory()->create();

    foreach (['/dashboard', '/receipts', '/vehicles', '/budgets'] as $path) {
        $this->actingAs($user)
            ->get($path)
            ->assertOk()
            ->assertSee('aria-label="Primary"', escape: false)
            ->assertSee('Add a charge');
    }
});

it('hides the bottom bar from desktop and the top nav from mobile', function (): void {
    // One nav definition drives both, so they cannot drift apart, but each is
    // only shown at the width it suits.
    $response = $this->actingAs(User::factory()->create())->get('/dashboard');

    $response->assertSee('md:hidden', escape: false)   // bottom bar
        ->assertSee('hidden items-center gap-1 text-sm md:flex', escape: false); // desktop nav
});

it('renders the session list as cards on mobile and a table on desktop', function (): void {
    // The table is 15 columns and 877px wide; in a 359px container 518px of
    // every row was off screen, hiding the figures people opened the app for.
    $user = User::factory()->create();
    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDay(),
        'total_amount' => '214.00',
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk()
        // Card list, mobile only.
        ->assertSee('divide-y divide-slate-100 md:hidden', escape: false)
        // Table, desktop only.
        ->assertSee('hidden overflow-x-auto md:block', escape: false);
});

it('renders receipts and vehicles as cards on mobile too', function (): void {
    $user = User::factory()->create();
    Receipt::factory()->create(['uploaded_by' => $user->id]);
    Vehicle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/receipts')->assertOk()->assertSee('md:hidden', escape: false);
    $this->actingAs($user)->get('/vehicles')->assertOk()->assertSee('md:hidden', escape: false);
});

it('collapses the account controls behind a menu', function (): void {
    // Six inline links plus a name wrapped the header onto two rows at 375px.
    $response = $this->actingAs(User::factory()->create())->get('/dashboard');

    $response->assertSee('Account menu')
        ->assertSee('aria-haspopup="menu"', escape: false)
        // Sign out moved inside it rather than being dropped.
        ->assertSee('Sign out');
});

it('opts into the safe area on notched phones', function (): void {
    // Without viewport-fit=cover the bottom bar sits under the home indicator.
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertSee('viewport-fit=cover', escape: false)
        ->assertSee('env(safe-area-inset-bottom)', escape: false);
});

it('gives money fields a numeric keypad', function (): void {
    // A full keyboard for an amount is a small, constant tax on quick entry.
    Vehicle::factory()->create(['user_id' => $user = User::factory()->create()]);

    $this->actingAs($user)
        ->get('/quick-add')
        ->assertOk()
        ->assertSee('inputmode="decimal"', escape: false);
});

it('lets the camera be used for a receipt', function (): void {
    // docs/04 calls this flow "Scan": on a phone that should open the camera,
    // not a file browser.
    $this->actingAs(User::factory()->create())
        ->get('/receipts/upload')
        ->assertOk()
        ->assertSee('capture="environment"', escape: false);
});

it('keeps budget actions as buttons rather than inline text', function (): void {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get('/budgets')
        ->assertOk()
        // Bordered controls with a minimum height, not 20px text links.
        ->assertSee('min-h-11', escape: false);
});

it('still shows the same figures it did before the redesign', function (): void {
    // Layout changed; the numbers must not.
    $user = User::factory()->create();
    ChargingSession::factory()->create([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDay(),
        'energy_kwh' => '40.000',
        'distance_km' => '200.0',
        'subtotal' => '200.00',
        'vat_amount' => '14.00',
        'discount_amount' => '0.00',
        'total_amount' => '214.00',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('214.00')
        ->assertSee('5.3500')   // cost per kWh
        ->assertSee('1.0700');  // cost per km
});
