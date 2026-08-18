<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use App\Exceptions\InvalidSessionTransition;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns the charging session lifecycle (docs/02 FR-003, docs/04 Manual Entry).
 *
 * Before this existed, CONFIRMED was written in exactly one place -- receipt
 * verification -- so a manually entered session could never count toward any
 * total. Home charging, quick entry and every receipt-less charge were
 * invisible to the dashboard despite being recorded (see
 * docs/12-improvement-backlog.md, P0 #1).
 *
 * Confirmation is a deliberate act, separate from creating or editing a
 * session, because it is the point where an entry becomes financial fact that
 * AT-009 reconciles against.
 */
class ChargingSessionService
{
    public function __construct(
        private readonly CostCalculationService $costs,
        private readonly AuditLogService $audit,
        private readonly TariffService $tariffs,
    ) {}

    /**
     * Recalculate the money and energy on a session from supplied amounts.
     *
     * Energy goes through FR-009 precedence, so a weaker source cannot
     * overwrite a stronger one.
     *
     * @param  array<string, string|null>  $amounts
     */
    public function applyAmounts(
        ChargingSession $session,
        array $amounts,
        ?EnergySource $energySource = null,
    ): ChargingSession {
        $incomingKwh = $amounts['energy_kwh'] ?? null;

        // Fall back to a SOC-derived figure when no measured energy was given.
        if ($incomingKwh === null && $energySource === null) {
            $estimated = $this->costs->estimateEnergyFromSoc($session);

            if ($estimated !== null) {
                $incomingKwh = $estimated;
                $energySource = EnergySource::SOC_ESTIMATE;
            }
        }

        $resolved = $this->costs->resolveEnergy($session, $incomingKwh, $energySource);

        if ($resolved !== null) {
            $session->energy_kwh = $resolved['kwh'];
            $session->energy_source = $resolved['source'];
        }

        // When nothing about the money was supplied, price the session from the
        // tariff in force at the time (docs/02 FR-007).
        //
        // The resolved version id is stored on the session, which is what
        // makes the total reproducible years later: the version is immutable
        // once referenced, so the rates that applied stay resolvable (AT-006).
        //
        // A supplied amount always wins -- what a driver was actually billed
        // is the fact, and a tariff is only ever an expectation of it.
        if ($this->hasNoSuppliedMoney($amounts) && $session->energy_kwh !== null) {
            $version = $this->tariffs->resolveForSession($session);

            if ($version !== null) {
                $session->tariff_version_id = $version->id;
                $amounts = [...$amounts, ...$this->tariffs->priceSession($version, $session->energy_kwh)];
            }
        }

        // When the user supplied a rate rather than an amount, derive the
        // subtotal from it.
        $amounts['subtotal'] ??= $this->costs->subtotalFromRate(
            $session->energy_kwh,
            $amounts['unit_price'] ?? null,
        );

        $totals = $this->costs->composeTotals($amounts);

        $session->subtotal = $totals['subtotal'];
        $session->discount_amount = $totals['discount_amount'];
        $session->vat_amount = $totals['vat_amount'];
        $session->total_amount = $totals['total_amount'];

        $session->save();

        $this->costs->writeCostLines($session, [...$amounts, 'subtotal' => $totals['subtotal']]);

        return $session;
    }

    /**
     * Whether the caller stated any money at all.
     *
     * @param  array<string, string|null>  $amounts
     */
    private function hasNoSuppliedMoney(array $amounts): bool
    {
        foreach (['total', 'subtotal', 'unit_price'] as $field) {
            if (($amounts[$field] ?? null) !== null && $amounts[$field] !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Confirm a session: it now counts toward every total (AT-009).
     *
     * This is what docs/04 Manual Entry means by "save -> dashboard update".
     *
     * @throws InvalidSessionTransition
     */
    public function confirm(ChargingSession $session, User $actor): ChargingSession
    {
        if ($session->status === SessionStatus::CONFIRMED) {
            // Idempotent: confirming twice is a no-op, not an error, so a
            // double-submitted form does not fail.
            return $session;
        }

        if ($session->status !== SessionStatus::DRAFT) {
            throw new InvalidSessionTransition($session->status, SessionStatus::CONFIRMED);
        }

        return DB::transaction(function () use ($session, $actor): ChargingSession {
            $before = $session->getOriginal();

            $session->status = SessionStatus::CONFIRMED;
            $session->save();

            $this->audit->log(
                AuditLog::ACTION_UPDATE,
                $session,
                $before,
                $session->getChanges(),
                $actor->id,
            );

            return $session->refresh();
        });
    }

    /**
     * Cancel a session so it stops counting.
     *
     * Reachable from CONFIRMED as well as DRAFT: a charge recorded in error
     * must be retractable, and doing so through a status change keeps the row
     * and its audit trail intact rather than deleting evidence
     * (docs/10 rule 15).
     *
     * @throws InvalidSessionTransition
     */
    public function cancel(ChargingSession $session, User $actor, ?string $reason = null): ChargingSession
    {
        if ($session->status === SessionStatus::CANCELLED) {
            return $session;
        }

        return DB::transaction(function () use ($session, $actor, $reason): ChargingSession {
            $before = $session->getOriginal();

            $session->status = SessionStatus::CANCELLED;

            if ($reason !== null) {
                $session->notes = trim(($session->notes ?? '')."\nCancelled: ".$reason);
            }

            $session->save();

            $this->audit->log(
                AuditLog::ACTION_UPDATE,
                $session,
                $before,
                $session->getChanges(),
                $actor->id,
            );

            return $session->refresh();
        });
    }

    /**
     * Return a cancelled session to draft so it can be corrected and
     * re-confirmed.
     *
     * @throws InvalidSessionTransition
     */
    public function reopen(ChargingSession $session, User $actor): ChargingSession
    {
        if ($session->status !== SessionStatus::CANCELLED) {
            throw new InvalidSessionTransition($session->status, SessionStatus::DRAFT);
        }

        return DB::transaction(function () use ($session, $actor): ChargingSession {
            $before = $session->getOriginal();

            $session->status = SessionStatus::DRAFT;
            $session->save();

            $this->audit->log(
                AuditLog::ACTION_UPDATE,
                $session,
                $before,
                $session->getChanges(),
                $actor->id,
            );

            return $session->refresh();
        });
    }
}
