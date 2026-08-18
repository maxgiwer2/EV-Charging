<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;
use App\Jobs\ProcessReceiptOcr;
use App\Models\AuditLog;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * docs/02 FR-004, AT-003: upload is validated, hashed and stored privately.
 */

beforeEach(function (): void {
    Storage::fake(config('receipts.disk'));
    Queue::fake();
});

/** A real JPEG, so the magic-byte check sees genuine bytes. */
function jpegFile(string $name = 'receipt.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 600, 800);
}

it('stores an uploaded receipt on the private disk', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/receipts', ['file' => jpegFile()]);

    $response->assertCreated()
        ->assertJsonPath('data.status', ReceiptStatus::OCR_PENDING->value);

    $receipt = Receipt::first();

    Storage::disk(config('receipts.disk'))->assertExists($receipt->file_path);
});

it('never exposes the storage path (docs/07)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/receipts', ['file' => jpegFile()]);

    expect($response->json('data'))->not->toHaveKey('file_path');
    // A download route is offered instead of any path or direct URL.
    expect($response->json('data.download_url'))->toContain('/receipts/');
});

it('stores the file under a generated name, not the client filename', function (): void {
    // The client filename is attacker-controlled and could carry traversal
    // or a second extension.
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/receipts', [
        'file' => jpegFile('../../evil.php.jpg'),
    ])->assertCreated();

    $receipt = Receipt::first();

    expect($receipt->file_path)->not->toContain('..')
        ->and($receipt->file_path)->not->toContain('evil')
        ->and($receipt->file_path)->toEndWith('.jpg');
});

it('records the sha256 of the stored bytes (AT-003)', function (): void {
    $user = User::factory()->create();
    $file = jpegFile();
    $expected = hash_file('sha256', $file->getRealPath());

    $this->actingAs($user)->postJson('/api/v1/receipts', ['file' => $file])->assertCreated();

    expect(Receipt::first()->sha256)->toBe($expected);
});

it('queues OCR rather than running it in the request', function (): void {
    // docs/03 -> async OCR jobs.
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/receipts', ['file' => jpegFile()])->assertCreated();

    Queue::assertPushed(ProcessReceiptOcr::class);
});

it('rejects a file whose contents do not match its extension', function (): void {
    // A PHP script renamed to .jpg: the extension and declared MIME both lie,
    // so only the magic-byte check catches it (docs/03).
    $user = User::factory()->create();

    $disguised = UploadedFile::fake()->createWithContent(
        'payload.jpg',
        "<?php echo 'pwned';",
    );

    $this->actingAs($user)
        ->postJson('/api/v1/receipts', ['file' => $disguised])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(Receipt::count())->toBe(0);
});

it('rejects an unsupported file type', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/receipts', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
        ])
        ->assertStatus(422);

    expect(Receipt::count())->toBe(0);
});

it('rejects a file over the configured size cap', function (): void {
    $user = User::factory()->create();
    $oversized = UploadedFile::fake()->image('big.jpg')->size(
        ((int) config('receipts.max_size_kb')) + 1024
    );

    $this->actingAs($user)
        ->postJson('/api/v1/receipts', ['file' => $oversized])
        ->assertStatus(422);
});

it('forbids a viewer from uploading', function (): void {
    $this->actingAs(User::factory()->viewer()->create())
        ->postJson('/api/v1/receipts', ['file' => jpegFile()])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect(Receipt::count())->toBe(0);
});

it('audits the upload (AT-010)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/receipts', ['file' => jpegFile()])->assertCreated();

    $log = AuditLog::where('entity_type', Receipt::class)
        ->where('action', AuditLog::ACTION_CREATE)
        ->first();

    expect($log)->not->toBeNull()
        // The private storage path must not leak into a trail admins can read
        // (docs/10 rule 13).
        ->and($log->after_data['file_path'])->toBe('[redacted]');
});
