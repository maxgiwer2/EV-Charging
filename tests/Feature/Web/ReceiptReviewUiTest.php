<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;
use App\Models\ChargingSession;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use App\Models\User;
use App\Models\Vehicle;

/*
 * docs/09 M2 -> review UI. The web layer goes through the same ReceiptService
 * as the API, so the "only one path to VERIFIED" guarantee holds here too.
 */

it('redirects a guest to sign in', function (): void {
    $this->get('/receipts')->assertRedirect(route('web.login'));
});

it('signs a user in and lands on the review list', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->post(route('web.login.attempt'), [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertRedirect(route('receipts.review.index'));

    $this->assertAuthenticatedAs($user);
});

it('gives one generic message for bad credentials', function (): void {
    // Must not reveal whether the address is registered.
    $user = User::factory()->create(['password' => 'secret-password']);

    $this->from(route('web.login'))
        ->post(route('web.login.attempt'), [
            'email' => $user->email,
            'password' => 'wrong',
        ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('lists only the caller\'s receipts (AT-007)', function (): void {
    $user = User::factory()->create();
    Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'original_filename' => 'mine.jpg',
    ]);
    Receipt::factory()->create(['original_filename' => 'theirs.jpg']);

    $this->actingAs($user)
        ->get('/receipts')
        ->assertOk()
        ->assertSee('mine.jpg')
        ->assertDontSee('theirs.jpg');
});

it('refuses to show another user\'s receipt', function (): void {
    $user = User::factory()->create();
    $theirs = Receipt::factory()->create();

    $this->actingAs($user)
        ->get(route('receipts.review.show', $theirs))
        ->assertNotFound();
});

it('highlights low-confidence fields for the reviewer (docs/05)', function (): void {
    $user = User::factory()->create();
    Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);
    ReceiptOcrResult::factory()->lowConfidence()->create(['receipt_id' => $receipt->id]);

    $response = $this->actingAs($user)->get(route('receipts.review.show', $receipt));

    $response->assertOk()
        // The amber ring marks a field that needs checking.
        ->assertSee('ring-amber-300', escape: false)
        // The percentage is shown too, so the cue is not colour-only.
        ->assertSee('check', escape: false);
});

it('warns about a probable duplicate without blocking review (AT-005)', function (): void {
    $user = User::factory()->create();
    Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
        'duplicate_matches' => [[
            'receipt_id' => 999,
            'uploaded_at' => now()->toIso8601String(),
            'status' => 'OCR_REVIEW',
            'reasons' => ['IDENTICAL_FILE'],
            'score' => 1.0,
        ]],
    ]);

    $this->actingAs($user)
        ->get(route('receipts.review.show', $receipt))
        ->assertOk()
        ->assertSee('looks like a receipt already on file')
        // Still reviewable: the form is present.
        ->assertSee('Confirm receipt');
});

it('confirms a receipt through the web form and records the session', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->post(route('receipts.review.verify', $receipt), [
            'vehicle_id' => $vehicle->id,
            'charging_type' => 'PUBLIC',
            'transaction_date' => now()->subHour()->format('Y-m-d\TH:i'),
            'energy_kwh' => 30,
            'subtotal' => 200,
            'vat' => 14,
            'total' => 214,
        ])
        ->assertRedirect(route('receipts.review.index'))
        ->assertSessionHas('status');

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::VERIFIED)
        ->and(ChargingSession::first()->total_amount)->toBe('214.00');
});

it('shows a validation error when the total does not add up', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->from(route('receipts.review.show', $receipt))
        ->post(route('receipts.review.verify', $receipt), [
            'vehicle_id' => $vehicle->id,
            'charging_type' => 'PUBLIC',
            'transaction_date' => now()->subHour()->format('Y-m-d\TH:i'),
            'energy_kwh' => 30,
            'subtotal' => 200,
            'vat' => 14,
            'total' => 999,
        ])
        ->assertSessionHasErrors('total');

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::OCR_REVIEW)
        ->and(ChargingSession::count())->toBe(0);
});

it('forbids a viewer from confirming', function (): void {
    $viewer = User::factory()->viewer()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $viewer->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $viewer->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($viewer)
        ->post(route('receipts.review.verify', $receipt), [
            'vehicle_id' => $vehicle->id,
            'charging_type' => 'PUBLIC',
            'transaction_date' => now()->format('Y-m-d\TH:i'),
            'energy_kwh' => 30,
            'subtotal' => 200,
            'total' => 200,
        ])
        ->assertForbidden();

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::OCR_REVIEW);
});

it('hides the form once a receipt is terminal', function (): void {
    $user = User::factory()->create();
    Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::VERIFIED,
        'verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('receipts.review.show', $receipt))
        ->assertOk()
        ->assertSee('can no longer be changed')
        ->assertDontSee('Confirm receipt');
});

it('rejects a receipt from the web form', function (): void {
    $user = User::factory()->create();
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->post(route('receipts.review.reject', $receipt), ['reason' => 'Blurry'])
        ->assertRedirect(route('receipts.review.index'));

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::REJECTED)
        ->and(ChargingSession::count())->toBe(0);
});

it('refuses to stream another user\'s receipt image', function (): void {
    // The review page embeds this URL; it must be policy-checked.
    $user = User::factory()->create();
    $theirs = Receipt::factory()->create();

    $this->actingAs($user)
        ->get(route('receipts.review.file', $theirs))
        ->assertNotFound();
});

it('signs the user out', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('web.logout'))
        ->assertRedirect(route('web.login'));

    $this->assertGuest();
});
