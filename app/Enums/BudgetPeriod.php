<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Budget window (docs/02 FR-013).
 */
enum BudgetPeriod: string
{
    case MONTHLY = 'MONTHLY';
    case YEARLY = 'YEARLY';
    case CUSTOM = 'CUSTOM';
}
