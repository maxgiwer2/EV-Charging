<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\UserRole;

it('makes viewers read-only', function (): void {
    expect(UserRole::VIEWER->canWrite())->toBeFalse()
        ->and(UserRole::USER->canWrite())->toBeTrue()
        ->and(UserRole::ADMIN->canWrite())->toBeTrue();
});

it('restricts reference data management to admins', function (): void {
    expect(UserRole::ADMIN->canManageReferenceData())->toBeTrue()
        ->and(UserRole::USER->canManageReferenceData())->toBeFalse()
        ->and(UserRole::VIEWER->canManageReferenceData())->toBeFalse();
});

it('restricts cross-user access to admins (AT-007)', function (): void {
    expect(UserRole::ADMIN->canAccessAllUsers())->toBeTrue()
        ->and(UserRole::USER->canAccessAllUsers())->toBeFalse()
        ->and(UserRole::VIEWER->canAccessAllUsers())->toBeFalse();
});

it('counts only confirmed sessions toward totals (AT-009)', function (): void {
    // Drafts and cancellations must never inflate a report.
    expect(SessionStatus::CONFIRMED->countsTowardTotals())->toBeTrue()
        ->and(SessionStatus::DRAFT->countsTowardTotals())->toBeFalse()
        ->and(SessionStatus::CANCELLED->countsTowardTotals())->toBeFalse();
});
