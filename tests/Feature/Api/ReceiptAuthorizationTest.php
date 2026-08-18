<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * AT-007 for receipts. The download route matters most: the receipts disk has
 * no public URL, so this policy is the only thing between an authenticated
 * user and someone else's receipt image.
 */

it('refuses to download another user\'s receipt', function (): void {
    $user = User::factory()->create();
    $victimReceipt = Receipt::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/receipts/{$victimReceipt->id}/download")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('streams the file to its owner', function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/receipts', [
        'file' => UploadedFile::fake()->image('r.jpg', 300, 300),
    ])->assertCreated();

    $receipt = Receipt::first();

    $response = $this->actingAs($user)->get("/api/v1/receipts/{$receipt->id}/download");

    $response->assertOk();

    // Private financial documents must not sit in shared caches. Symfony
    // normalises directive order, so assert on the directives themselves.
    $cacheControl = $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store');
});

it('hides another user\'s receipt from show', function (): void {
    $user = User::factory()->create();
    $victimReceipt = Receipt::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/v1/receipts/{$victimReceipt->id}")
        ->assertStatus(404);
});

it('lists only the caller\'s receipts', function (): void {
    $user = User::factory()->create();
    Receipt::factory()->count(2)->create(['uploaded_by' => $user->id]);
    Receipt::factory()->count(3)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/receipts');

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(2);
});

it('refuses to verify another user\'s receipt', function (): void {
    $user = User::factory()->create();
    $victimReceipt = Receipt::factory()->create(['status' => ReceiptStatus::OCR_REVIEW]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$victimReceipt->id}/verify", [])
        ->assertStatus(404);

    expect($victimReceipt->fresh()->status)->toBe(ReceiptStatus::OCR_REVIEW);
});

it('forbids a viewer from verifying their own receipt', function (): void {
    $viewer = User::factory()->viewer()->create();
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $viewer->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($viewer)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", [])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('lets a viewer read their own receipt', function (): void {
    $viewer = User::factory()->viewer()->create();
    $receipt = Receipt::factory()->create(['uploaded_by' => $viewer->id]);

    $this->actingAs($viewer)
        ->getJson("/api/v1/receipts/{$receipt->id}")
        ->assertOk();
});

it('lets an admin read any receipt', function (): void {
    $admin = User::factory()->admin()->create();
    $receipt = Receipt::factory()->create();

    $this->actingAs($admin)
        ->getJson("/api/v1/receipts/{$receipt->id}")
        ->assertOk();
});

it('requires authentication to download', function (): void {
    $receipt = Receipt::factory()->create();

    $this->getJson("/api/v1/receipts/{$receipt->id}/download")
        ->assertStatus(401);
});
