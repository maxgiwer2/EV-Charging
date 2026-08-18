<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;
use App\Models\Notification;
use App\Models\Receipt;
use App\Models\User;
use App\Services\DuplicateDetectionService;
use App\Support\DuplicateMatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * AT-005: uploading the same receipt twice must be flagged as a probable
 * duplicate -- flagged, not blocked, because re-uploading to correct a
 * mis-keyed session is legitimate.
 */

it('flags a byte-identical re-upload (AT-005)', function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $user = User::factory()->create();
    // Held in a variable: the fake file is deleted when the UploadedFile
    // object is garbage-collected, so reading it inline can race.
    $source = UploadedFile::fake()->image('r.jpg', 400, 400);
    $contents = (string) file_get_contents((string) $source->getRealPath());

    $upload = fn (): TestResponse => $this->actingAs($user)->postJson(
        '/api/v1/receipts',
        ['file' => UploadedFile::fake()->createWithContent('r.jpg', $contents)]
    );

    $upload()->assertCreated();
    $second = $upload();

    // Not blocked: the upload still succeeds.
    $second->assertCreated();

    $matches = $second->json('data.duplicate_matches');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['reasons'])->toContain(DuplicateMatch::REASON_IDENTICAL_FILE)
        // JSON decodes 1.0 as an int, so compare numerically.
        ->and((float) $matches[0]['score'])->toBe(1.0);
});

it('notifies the owner about a probable duplicate (FR-014)', function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();

    $user = User::factory()->create();
    // Held in a variable: the fake file is deleted when the UploadedFile
    // object is garbage-collected, so reading it inline can race.
    $source = UploadedFile::fake()->image('r.jpg', 400, 400);
    $contents = (string) file_get_contents((string) $source->getRealPath());

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($user)->postJson('/api/v1/receipts', [
            'file' => UploadedFile::fake()->createWithContent('r.jpg', $contents),
        ])->assertCreated();
    }

    expect(Notification::where('user_id', $user->id)
        ->where('type', Notification::TYPE_DUPLICATE_RECEIPT)
        ->exists())->toBeTrue();
});

it('does not flag two different receipts', function (): void {
    $user = User::factory()->create();
    Receipt::factory()->create(['uploaded_by' => $user->id]);
    $other = Receipt::factory()->create(['uploaded_by' => $user->id]);

    expect(app(DuplicateDetectionService::class)->detect($other))->toBe([]);
});

it('never compares receipts across users (AT-007)', function (): void {
    // Surfacing a cross-user match would reveal that another user's receipt
    // exists, as well as being meaningless.
    $mine = Receipt::factory()->create();
    $theirs = Receipt::factory()->create(['sha256' => $mine->sha256]);

    expect(app(DuplicateDetectionService::class)->detect($theirs))->toBe([]);
});

it('flags a shared receipt number', function (): void {
    $user = User::factory()->create();
    Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'receipt_number' => 'INV-12345',
    ]);
    $candidate = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'receipt_number' => 'INV-12345',
    ]);

    $matches = app(DuplicateDetectionService::class)->detect($candidate);

    expect($matches)->toHaveCount(1)
        ->and($matches[0]->reasons)->toContain(DuplicateMatch::REASON_RECEIPT_NUMBER);
});

it('ignores a null receipt number when matching', function (): void {
    // Otherwise every receipt awaiting OCR would match every other one.
    $user = User::factory()->create();
    Receipt::factory()->count(2)->create([
        'uploaded_by' => $user->id,
        'receipt_number' => null,
    ]);
    $candidate = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'receipt_number' => null,
    ]);

    expect(app(DuplicateDetectionService::class)->detect($candidate))->toBe([]);
});

it('does not re-flag a receipt a human already rejected', function (): void {
    // Re-surfacing it would reproduce noise the reviewer has dealt with.
    $user = User::factory()->create();
    $rejected = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::REJECTED,
    ]);
    $candidate = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'sha256' => $rejected->sha256,
    ]);

    expect(app(DuplicateDetectionService::class)->detect($candidate))->toBe([]);
});

it('ranks a stronger signal first', function (): void {
    $user = User::factory()->create();
    $identical = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'receipt_number' => 'AAA',
    ]);
    Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'receipt_number' => 'BBB',
    ]);

    $candidate = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'sha256' => $identical->sha256,
        'receipt_number' => 'BBB',
    ]);

    $matches = app(DuplicateDetectionService::class)->detect($candidate);

    // Identical bytes (1.0) must outrank a shared receipt number (0.9).
    expect($matches[0]->score)->toBeGreaterThanOrEqual($matches[1]->score)
        ->and($matches[0]->reasons)->toContain(DuplicateMatch::REASON_IDENTICAL_FILE);
});
