<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChargingSession;

/**
 * One charging session flagged as unusual, with why (docs/02 FR-018, FR-014).
 *
 * Advisory, like duplicate detection: the reasons travel with the finding so a
 * person can judge it. An expensive charge is often perfectly legitimate -- a
 * motorway rapid charger on a long trip -- and the system has no way to know
 * that, so it reports rather than concludes.
 */
final readonly class Anomaly
{
    /** Cost per kWh far above this user's own usual rate. */
    public const REASON_UNIT_COST = 'UNIT_COST';

    /** Total amount far above this user's usual per-session spend. */
    public const REASON_TOTAL_AMOUNT = 'TOTAL_AMOUNT';

    /** Energy delivered far above the usual session. */
    public const REASON_ENERGY = 'ENERGY';

    /**
     * @param  list<string>  $reasons
     * @param  array<string, string|null>  $context  observed value, baseline, score
     */
    public function __construct(
        public ChargingSession $session,
        public array $reasons,
        public string $severity,
        public array $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->session->id,
            'started_at' => $this->session->started_at->toIso8601String(),
            'total_amount' => $this->session->total_amount,
            'reasons' => $this->reasons,
            'severity' => $this->severity,
            'context' => $this->context,
        ];
    }
}
