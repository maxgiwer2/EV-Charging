<?php

declare(strict_types=1);

use App\Enums\ReceiptStatus;

/*
 * docs/05 review lifecycle and the FR-005 / AT-004 rule that OCR must never
 * auto-confirm a financial record.
 */

it('follows the documented happy path', function (): void {
    expect(ReceiptStatus::OCR_PENDING->canTransitionTo(ReceiptStatus::OCR_PROCESSING))->toBeTrue()
        ->and(ReceiptStatus::OCR_PROCESSING->canTransitionTo(ReceiptStatus::OCR_REVIEW))->toBeTrue()
        ->and(ReceiptStatus::OCR_REVIEW->canTransitionTo(ReceiptStatus::VERIFIED))->toBeTrue();
});

it('never allows verification without passing through review', function (): void {
    // This is the core of AT-004: no confidence score may skip a human.
    expect(ReceiptStatus::OCR_PENDING->canTransitionTo(ReceiptStatus::VERIFIED))->toBeFalse()
        ->and(ReceiptStatus::OCR_PROCESSING->canTransitionTo(ReceiptStatus::VERIFIED))->toBeFalse();
});

it('treats verified and rejected as terminal', function (): void {
    // A correction to a verified receipt is a new audited record, never an
    // in-place rewind (docs/10 rule 6).
    expect(ReceiptStatus::VERIFIED->isTerminal())->toBeTrue()
        ->and(ReceiptStatus::REJECTED->isTerminal())->toBeTrue()
        ->and(ReceiptStatus::VERIFIED->canTransitionTo(ReceiptStatus::OCR_REVIEW))->toBeFalse()
        ->and(ReceiptStatus::REJECTED->canTransitionTo(ReceiptStatus::OCR_REVIEW))->toBeFalse();
});

it('lets a retried job re-enter pending without error', function (): void {
    // docs/03 -> idempotent OCR jobs: a replay must be a no-op, not a failure.
    expect(ReceiptStatus::OCR_PENDING->canTransitionTo(ReceiptStatus::OCR_PENDING))->toBeTrue()
        ->and(ReceiptStatus::OCR_PROCESSING->canTransitionTo(ReceiptStatus::OCR_PENDING))->toBeTrue();
});

it('allows rejection from any non-terminal state', function (): void {
    expect(ReceiptStatus::OCR_PENDING->canTransitionTo(ReceiptStatus::REJECTED))->toBeTrue()
        ->and(ReceiptStatus::OCR_PROCESSING->canTransitionTo(ReceiptStatus::REJECTED))->toBeTrue()
        ->and(ReceiptStatus::OCR_REVIEW->canTransitionTo(ReceiptStatus::REJECTED))->toBeTrue();
});

it('reports only OCR_REVIEW as awaiting a human', function (): void {
    expect(ReceiptStatus::OCR_REVIEW->awaitsReview())->toBeTrue()
        ->and(ReceiptStatus::OCR_PENDING->awaitsReview())->toBeFalse()
        ->and(ReceiptStatus::VERIFIED->awaitsReview())->toBeFalse();
});
