<?php

declare(strict_types=1);

use App\Enums\OcrResultStatus;
use App\Enums\ReceiptStatus;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ReceiptService;
use App\Support\Ocr\ExtractedField;
use App\Support\Ocr\OcrResult;

/*
 * docs/05 review lifecycle, AT-004: a human must confirm before extracted
 * values become financial fact.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function confirmedPayload(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicle->id,
        'charging_type' => 'PUBLIC',
        'transaction_date' => now()->subHour()->toIso8601String(),
        'energy_kwh' => 42.5,
        'unit_price' => 7.5,
        'subtotal' => 318.75,
        'vat' => 22.31,
        'total' => 341.06,
    ], $overrides);
}

it('sends OCR output to review, never straight to verified (AT-004)', function (): void {
    $receipt = Receipt::factory()->create(['status' => ReceiptStatus::OCR_PROCESSING]);

    app(ReceiptService::class)->recordOcrResult($receipt, new OcrResult(
        provider: 'test',
        model: 'v1',
        status: OcrResultStatus::SUCCESS,
        // Deliberately near-perfect confidence: even this must not skip a human.
        fields: ['total' => new ExtractedField('341.06', 0.99)],
    ));

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::OCR_REVIEW);
});

it('sends a failed OCR run to review as well', function (): void {
    // A human can still key the values in from the stored image; discarding
    // the receipt would strand it.
    $receipt = Receipt::factory()->create(['status' => ReceiptStatus::OCR_PROCESSING]);

    app(ReceiptService::class)->recordOcrResult(
        $receipt,
        OcrResult::failed('none'),
    );

    expect($receipt->refresh()->status)->toBe(ReceiptStatus::OCR_REVIEW);
});

it('keeps every OCR attempt instead of overwriting the previous one', function (): void {
    // docs/05 -> preserve raw OCR.
    $receipt = Receipt::factory()->create(['status' => ReceiptStatus::OCR_PROCESSING]);
    $service = app(ReceiptService::class);

    $service->recordOcrResult($receipt, OcrResult::failed('none'));

    $receipt->refresh();
    $receipt->status = ReceiptStatus::OCR_PROCESSING;
    $receipt->save();

    $service->recordOcrResult($receipt, new OcrResult(
        provider: 'test',
        model: 'v2',
        status: OcrResultStatus::SUCCESS,
        fields: ['total' => new ExtractedField('341.06', 0.9)],
    ));

    expect(ReceiptOcrResult::where('receipt_id', $receipt->id)->count())->toBe(2);
});

it('verifies a receipt and creates the charging session (docs/04)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertOk()
        ->assertJsonPath('data.status', ReceiptStatus::VERIFIED->value);

    $session = ChargingSession::first();

    expect($session)->not->toBeNull()
        // A verified receipt is confirmed fact, so it counts toward totals.
        ->and($session->status->value)->toBe('CONFIRMED')
        ->and($session->total_amount)->toBe('341.06')
        // Receipt is the highest-precedence energy source (FR-009).
        ->and($session->energy_source->value)->toBe('RECEIPT');
});

it('records who verified and when (AT-004)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertOk();

    $receipt->refresh();

    expect($receipt->verified_by)->toBe($user->id)
        ->and($receipt->verified_at)->not->toBeNull();
});

it('stores the confirmed values without touching the OCR result (docs/05)', function (): void {
    // The reviewer corrects a figure OCR misread. The provider's original
    // reading must survive, otherwise a disputed number cannot be audited.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);
    $ocr = ReceiptOcrResult::factory()->create([
        'receipt_id' => $receipt->id,
        'extracted_data' => ['total' => ['value' => '999.99', 'confidence' => 0.55]],
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertOk();

    expect($ocr->refresh()->extracted_data['total']['value'])->toBe('999.99')
        ->and($receipt->refresh()->verified_data['total'])->toBe(341.06);
});

it('refuses to verify a receipt twice', function (): void {
    // VERIFIED is terminal: a correction is a new audited change, not a rewind
    // (docs/10 rule 6).
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::VERIFIED,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
});

it('rejects a total that does not match the breakdown', function (): void {
    // Money is being committed here; a mistyped figure must not become fact.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle, [
            'total' => 500.00,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    expect(ChargingSession::count())->toBe(0);
});

it('tolerates one satang of rounding across the breakdown', function (): void {
    // Real receipts round each line independently; an exact match would
    // reject legitimate paperwork.
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle, [
            'total' => 341.07,
        ]))
        ->assertOk();
});

it('writes the frozen cost breakdown (AT-006)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertOk();

    $lines = ChargingSession::first()->costLines;

    expect($lines->pluck('line_type')->all())->toContain('ENERGY', 'VAT')
        ->and($lines->firstWhere('line_type', 'ENERGY')->amount)->toBe('318.75');
});

it('refuses to verify using another user\'s vehicle', function (): void {
    $user = User::factory()->create();
    $othersVehicle = Vehicle::factory()->create();
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($othersVehicle))
        ->assertStatus(422);
});

it('audits the verification with a VERIFY action (AT-010)', function (): void {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create(['user_id' => $user->id]);
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/verify", confirmedPayload($vehicle))
        ->assertOk();

    expect(AuditLog::where('action', AuditLog::ACTION_VERIFY)
        ->where('entity_type', Receipt::class)->exists())->toBeTrue();
});

it('rejects a receipt with a reason', function (): void {
    $user = User::factory()->create();
    $receipt = Receipt::factory()->create([
        'uploaded_by' => $user->id,
        'status' => ReceiptStatus::OCR_REVIEW,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/receipts/{$receipt->id}/reject", ['reason' => 'Illegible'])
        ->assertOk()
        ->assertJsonPath('data.status', ReceiptStatus::REJECTED->value);

    expect($receipt->refresh()->verified_data['rejection_reason'])->toBe('Illegible')
        // Rejecting must not fabricate a financial record.
        ->and(ChargingSession::count())->toBe(0);
});
