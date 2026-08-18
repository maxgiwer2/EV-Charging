<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a charging session (database/schema.sql charging_sessions.status).
 *
 * Only CONFIRMED sessions are financial fact. AT-009 requires dashboard totals
 * to reconcile with confirmed sessions, so every analytics query must filter on
 * countsTowardTotals().
 */
enum SessionStatus: string
{
    case DRAFT = 'DRAFT';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';

    /**
     * Whether this session contributes to spend, energy and efficiency totals.
     *
     * A DRAFT is an incomplete entry (for example awaiting receipt review) and
     * a CANCELLED session never happened; neither may inflate a report.
     */
    public function countsTowardTotals(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * Whether the session may still be edited freely. Once confirmed, changes
     * are corrections and must be auditable (docs/10 rule 6).
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }
}
