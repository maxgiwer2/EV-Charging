<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChargingSession;
use App\Models\Notification;
use App\Models\User;
use App\Support\Anomaly;
use App\Support\Money;
use App\Support\Statistics;
use Illuminate\Support\Collection;

/**
 * Flags charging sessions that look unusual for this user (docs/02 FR-018),
 * and raises the notification FR-014 asks for.
 *
 * Statistical, not AI. The reasoning is the same as for the assistant: this
 * decides what to tell someone about their money, so it has to be
 * reproducible and explainable. A model that flags a different set of sessions
 * each run is not a detector.
 *
 * Three properties keep it from being noise:
 *
 *  - **Robust baselines.** Median and MAD, not mean and standard deviation. A
 *    single expensive charge inflates the standard deviation enough to hide
 *    itself, so a mean-based detector goes quiet exactly when it matters.
 *  - **Per user.** Someone who only charges at home has a different normal
 *    from someone who lives on motorway rapid chargers. A global baseline
 *    would flag one group constantly and the other never.
 *  - **Refuses without evidence.** Below MINIMUM_HISTORY sessions there is no
 *    baseline, and every early session would look extreme against the two
 *    before it.
 */
class AnomalyDetectionService
{
    /**
     * Sessions needed before anything is judged. Below this the median is
     * meaningless and the detector would simply generate alarm.
     */
    private const MINIMUM_HISTORY = 8;

    /**
     * Modified z-score threshold. 3.5 is the conventional cut-off for this
     * statistic; lower would flag ordinary variation between a home charge and
     * a rapid charger.
     */
    private const THRESHOLD = '3.5';

    /** Above this, the finding is reported as high severity. */
    private const SEVERE_THRESHOLD = '6.0';

    /**
     * How far back the baseline looks. Long enough to cover seasonal habit,
     * short enough that a tariff change two years ago does not define "usual".
     */
    private const BASELINE_MONTHS = 12;

    /**
     * Sessions that stand out from the user's own history, most severe first.
     *
     * @param  list<ChargingSession>|Collection<int, ChargingSession>|null  $candidates
     *                                                                                   sessions to judge; defaults to the most recent ones
     * @return list<Anomaly>
     */
    public function detect(User $user, ?iterable $candidates = null): array
    {
        $history = $this->baseline($user);

        if ($history->count() < self::MINIMUM_HISTORY) {
            return [];
        }

        $baselines = [
            Anomaly::REASON_UNIT_COST => $this->baselineFor($history, fn (ChargingSession $s): ?string => $this->unitCost($s)),
            Anomaly::REASON_TOTAL_AMOUNT => $this->baselineFor($history, fn (ChargingSession $s): string => $s->total_amount),
            Anomaly::REASON_ENERGY => $this->baselineFor($history, fn (ChargingSession $s): ?string => $s->energy_kwh),
        ];

        $candidates ??= $history;
        $found = [];

        foreach ($candidates as $session) {
            $anomaly = $this->judge($session, $baselines);

            if ($anomaly !== null) {
                $found[] = $anomaly;
            }
        }

        usort($found, fn (Anomaly $a, Anomaly $b): int => bccomp(
            $b->context['score'] ?? '0',
            $a->context['score'] ?? '0',
            4,
        ));

        return $found;
    }

    /**
     * Detect and notify (FR-014 -> notify on anomalous expense).
     *
     * One notification per session, so re-running the check does not spam a
     * user with what they have already seen.
     *
     * @param  iterable<int, ChargingSession>|null  $candidates
     * @return list<Anomaly>
     */
    public function detectAndNotify(User $user, ?iterable $candidates = null): array
    {
        $anomalies = $this->detect($user, $candidates);

        foreach ($anomalies as $anomaly) {
            $alreadyNotified = Notification::query()
                ->where('user_id', $user->id)
                ->where('type', Notification::TYPE_ANOMALOUS_EXPENSE)
                ->whereJsonContains('context->session_id', $anomaly->session->id)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            Notification::create([
                'user_id' => $user->id,
                'type' => Notification::TYPE_ANOMALOUS_EXPENSE,
                'title' => 'Unusual charging cost',
                'body' => 'A charging session cost noticeably more than your usual. Worth a look.',
                'context' => $anomaly->toArray(),
            ]);
        }

        return $anomalies;
    }

    /**
     * Judge one session against the prepared baselines.
     *
     * Only the *high* side is reported: a cheaper-than-usual charge is good
     * news, not something to interrupt someone about.
     *
     * @param  array<string, array{median: string, mad: string}|null>  $baselines
     */
    private function judge(ChargingSession $session, array $baselines): ?Anomaly
    {
        $reasons = [];
        $context = [];
        $worst = '0';

        $observations = [
            Anomaly::REASON_UNIT_COST => $this->unitCost($session),
            Anomaly::REASON_TOTAL_AMOUNT => $session->total_amount,
            Anomaly::REASON_ENERGY => $session->energy_kwh,
        ];

        foreach ($observations as $reason => $value) {
            $baseline = $baselines[$reason] ?? null;

            // No value recorded, or no usable baseline: not judged. Silence
            // here means "cannot tell", which is the honest answer.
            if ($value === null || $baseline === null) {
                continue;
            }

            $score = Statistics::modifiedZScore($value, $baseline['median'], $baseline['mad'])
                // A zero MAD means a perfectly consistent history -- someone
                // who charges the same amount at the same place every time.
                // The z-score is undefined there, but that user is exactly the
                // one for whom a sudden fourfold charge is obvious, so fall
                // back to a relative comparison rather than going silent.
                ?? $this->relativeScore($value, $baseline['median']);

            if ($score === null || bccomp($score, self::THRESHOLD, 4) !== 1) {
                continue;
            }

            $reasons[] = $reason;
            $context[mb_strtolower($reason).'_observed'] = $value;
            $context[mb_strtolower($reason).'_usual'] = $baseline['median'];

            if (bccomp($score, $worst, 4) === 1) {
                $worst = $score;
            }
        }

        if ($reasons === []) {
            return null;
        }

        $context['score'] = $worst;

        return new Anomaly(
            $session,
            $reasons,
            bccomp($worst, self::SEVERE_THRESHOLD, 4) === 1 ? 'high' : 'medium',
            $context,
        );
    }

    /**
     * Score used when the spread is zero.
     *
     * Expressed on the same scale as the z-score threshold so one cut-off
     * governs both paths: a value 50% above the median scores 3.5 (the
     * threshold), and one at four times the median scores well past it.
     *
     * Returns null when the median is zero -- a history of free charging says
     * nothing about whether a paid charge is unusual.
     */
    private function relativeScore(string $value, string $median): ?string
    {
        if (bccomp($median, '0', 8) !== 1) {
            return null;
        }

        $excess = bcsub($value, $median, 8);

        if (bccomp($excess, '0', 8) !== 1) {
            return '0';
        }

        // (excess / median) / 0.5 * THRESHOLD
        $ratio = bcdiv($excess, $median, 8);

        return bcmul(bcdiv($ratio, '0.5', 8), self::THRESHOLD, 8);
    }

    /**
     * The confirmed sessions that define "usual" for this user.
     *
     * Only CONFIRMED: a draft is not yet fact and a cancellation never
     * happened, so neither should shape the baseline (AT-009).
     *
     * @return Collection<int, ChargingSession>
     */
    private function baseline(User $user): Collection
    {
        return ChargingSession::query()
            ->confirmed()
            ->ownedBy($user->id)
            ->where('started_at', '>=', now()->subMonths(self::BASELINE_MONTHS))
            ->orderByDesc('started_at')
            ->limit(500)
            ->get();
    }

    /**
     * Median and MAD for one measure, or null when too few sessions record it.
     *
     * @param  Collection<int, ChargingSession>  $sessions
     * @param  callable(ChargingSession): ?string  $extract
     * @return array{median: string, mad: string}|null
     */
    private function baselineFor(Collection $sessions, callable $extract): ?array
    {
        $values = [];

        foreach ($sessions as $session) {
            $value = $extract($session);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        if (count($values) < self::MINIMUM_HISTORY) {
            return null;
        }

        $median = Statistics::median($values);
        $mad = Statistics::medianAbsoluteDeviation($values, $median);

        if ($median === null || $mad === null) {
            return null;
        }

        return ['median' => $median, 'mad' => $mad];
    }

    /**
     * Cost per kWh, or null when it cannot be computed.
     *
     * The same rule as everywhere else (docs/06): no denominator, no metric.
     * A zero here would look like a free charge and drag the baseline down,
     * making genuinely expensive sessions look normal.
     */
    private function unitCost(ChargingSession $session): ?string
    {
        // total_amount is NOT NULL, so only the energy can be missing.
        if ($session->energy_kwh === null) {
            return null;
        }

        return Money::of($session->total_amount)->divide($session->energy_kwh)?->toScale(4);
    }
}
