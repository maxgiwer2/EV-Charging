<?php

declare(strict_types=1);

use App\Enums\ChargingType;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Testing\TestResponse;

/*
 * AT-008: given filters, exported data must match the filtered records.
 */

function exportableSession(User $user, array $attributes = []): ChargingSession
{
    return ChargingSession::factory()->create(array_merge([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDays(3),
        'energy_kwh' => '40.000',
        'distance_km' => '200.0',
        'subtotal' => '200.00',
        'vat_amount' => '14.00',
        'discount_amount' => '0.00',
        'total_amount' => '214.00',
    ], $attributes));
}

/** Read a streamed download into a string. */
function streamedContent(TestResponse $response): string
{
    ob_start();
    $response->baseResponse->sendContent();

    return (string) ob_get_clean();
}

it('exports exactly the filtered records (AT-008)', function (): void {
    $user = User::factory()->create();
    exportableSession($user, ['charging_type' => ChargingType::HOME]);
    exportableSession($user, ['charging_type' => ChargingType::PUBLIC]);

    $response = $this->actingAs($user)->get('/api/v1/reports/export?'.http_build_query([
        'format' => 'csv',
        'charging_type' => 'HOME',
    ]));

    $response->assertOk();

    $csv = streamedContent($response);
    $dataLines = array_filter(array_slice(explode("\n", trim($csv)), 1));

    // One header plus exactly one matching record.
    expect($dataLines)->toHaveCount(1)
        ->and($csv)->toContain('HOME')
        ->and($csv)->not->toContain('PUBLIC');
});

it('excludes drafts and cancellations from exports', function (): void {
    $user = User::factory()->create();
    exportableSession($user);
    exportableSession($user, ['status' => 'DRAFT']);
    exportableSession($user, ['status' => 'CANCELLED']);

    $csv = streamedContent(
        $this->actingAs($user)->get('/api/v1/reports/export?format=csv')
    );

    expect(array_filter(array_slice(explode("\n", trim($csv)), 1)))->toHaveCount(1);
});

it('never exports another user\'s records (AT-007)', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();
    exportableSession($victim, ['notes' => 'VICTIM-MARKER']);

    $csv = streamedContent(
        $this->actingAs($user)->get('/api/v1/reports/export?format=csv')
    );

    expect($csv)->not->toContain('VICTIM-MARKER')
        ->and(array_filter(array_slice(explode("\n", trim($csv)), 1)))->toHaveCount(0);
});

it('writes a UTF-8 BOM so Excel renders Thai correctly', function (): void {
    $user = User::factory()->create();
    exportableSession($user);

    $csv = streamedContent(
        $this->actingAs($user)->get('/api/v1/reports/export?format=csv')
    );

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
});

it('leaves an uncomputable metric blank rather than zero', function (): void {
    // A spreadsheet full of zeroes would be averaged by whoever opens it.
    $user = User::factory()->create();
    exportableSession($user, ['distance_km' => null, 'odometer_before_km' => null, 'odometer_after_km' => null]);

    $csv = streamedContent(
        $this->actingAs($user)->get('/api/v1/reports/export?format=csv')
    );

    $row = str_getcsv(array_values(array_filter(array_slice(explode("\n", trim($csv)), 1)))[0]);

    // cost_per_km is the last column and must be empty, not "0".
    expect(end($row))->toBe('');
});

it('exports XLSX with the same columns as CSV', function (): void {
    $user = User::factory()->create();
    exportableSession($user);

    $response = $this->actingAs($user)->get('/api/v1/reports/export?format=xlsx');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $content = streamedContent($response);

    // A valid XLSX is a ZIP archive; check the signature rather than parsing.
    expect(str_starts_with($content, 'PK'))->toBeTrue();
});

it('exports a PDF', function (): void {
    $user = User::factory()->create();
    exportableSession($user);

    $response = $this->actingAs($user)->get('/api/v1/reports/export?format=pdf');

    $response->assertOk();

    // dompdf returns a complete response rather than a stream, because it
    // renders the whole document before sending.
    expect(str_starts_with($response->getContent(), '%PDF'))->toBeTrue();
});

it('rejects an unknown export format', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/reports/export?format=exe')
        ->assertStatus(422);
});

it('audits an export (FR-015)', function (): void {
    // An export moves financial data out of the system.
    $user = User::factory()->create();
    exportableSession($user);

    streamedContent($this->actingAs($user)->get('/api/v1/reports/export?format=csv'));

    $log = AuditLog::where('action', AuditLog::ACTION_EXPORT)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($user->id)
        ->and($log->after_data['format'])->toBe('csv');
});

it('returns row-level report data with a summary', function (): void {
    $user = User::factory()->create();
    exportableSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/reports/charging');

    $response->assertOk();

    expect($response->json('data.0.total_amount'))->toBe('214.00')
        ->and($response->json('data.0.cost_per_kwh'))->toBe('5.3500')
        // The summary must agree with the rows (AT-009).
        ->and($response->json('meta.summary.total_cost'))->toBe('214.00');
});

it('groups a report by vehicle', function (): void {
    $user = User::factory()->create();
    exportableSession($user);

    $response = $this->actingAs($user)->getJson('/api/v1/reports/vehicles');

    $response->assertOk();
    expect($response->json('data.0.total_cost'))->toBe('214.00');
});

it('requires authentication to export', function (): void {
    $this->getJson('/api/v1/reports/export?format=csv')->assertStatus(401);
});
