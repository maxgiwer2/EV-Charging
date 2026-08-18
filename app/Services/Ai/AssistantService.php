<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\User;
use App\Services\AnalyticsFilter;
use App\Services\AnalyticsService;
use App\Support\Ai\AiMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The AI assistant (docs/02 FR-017).
 *
 * FR-017: "Answer from system data only; include source records in response."
 * Architecture: "AI is advisory; deterministic business rules remain
 * authoritative."
 *
 * The pipeline is built so those two rules hold structurally rather than by
 * instruction:
 *
 *   1. The model turns the question into a **structured intent** -- a period,
 *      a dimension, a metric. Nothing but whitelisted values survives.
 *   2. AnalyticsService computes the figures. This is the same code the
 *      dashboard and exports use, so an answer always reconciles with them.
 *   3. The model phrases a sentence **around figures it is given**.
 *
 * The model is never asked to calculate, and step 3's output is checked: if it
 * introduces a number that is not among the computed ones, the narration is
 * discarded and the factual summary is returned alone. That check exists
 * because it is not hypothetical -- asked to divide 1385.84 by 3, the models
 * available here produced 4116.52, nothing, and 461.95.
 */
class AssistantService
{
    /**
     * Turns a question into intent. Deliberately narrow: the model chooses
     * from fixed options, so a hallucinated dimension cannot become a query.
     */
    private const INTENT_PROMPT = <<<'TXT'
        You classify questions about a personal EV charging expense log.
        Reply with JSON only, no prose, using exactly these keys:

        {"period":"this_month|last_month|this_year|last_30_days|all",
         "dimension":"none|charging_type|charging_mode|station|network|vehicle",
         "intent":"summary|breakdown|trend|unknown"}

        Rules:
        - Pick the closest option. Never invent a value outside the lists.
        - "unknown" if the question is not about charging costs, energy or usage.
        - Do not answer the question. Do not include numbers.
        TXT;

    /**
     * Phrasing prompt. The figures are supplied; the model may only put them
     * into a sentence.
     */
    private const NARRATION_PROMPT = <<<'TXT'
        You explain EV charging figures to their owner.

        You are given the ONLY facts you may use. Obey strictly:
        - Never state a number that is not in the facts. Do not add, average,
          convert or otherwise calculate anything.
        - If a figure is missing or null, say it is not available. Never
          substitute zero.
        - Amounts are Thai baht (THB). Never write a currency symbol or name
          the facts do not state, and never convert between currencies.
        - Two or three sentences, plain language, same language as the question.
        - No greetings, no markdown, no bullet points.
        TXT;

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AnalyticsService $analytics,
    ) {}

    /**
     * Answer a question about the user's own data.
     *
     * @return array<string, mixed>
     */
    public function ask(string $question, User $user): array
    {
        $provider = $this->providers->driver();

        $intent = $this->classify($question, $user);
        $filter = $this->filterFor($intent['period'], $user);

        // Every figure below is computed by the same service the dashboard
        // uses, so an answer can never disagree with the dashboard (AT-009).
        $summary = $this->analytics->summary($filter);
        $breakdown = $intent['dimension'] === 'none'
            ? []
            : $this->analytics->breakdown($filter, $intent['dimension']);

        $facts = $this->factsFor($summary, $breakdown, $filter);

        $answer = $this->narrate($question, $facts, $provider);

        return [
            // Null when the model was unavailable or produced an unusable
            // answer. The caller shows the figures on their own; a wrong
            // sentence is worse than no sentence.
            'answer' => $answer,
            'facts' => $facts,
            'sources' => $this->sources($filter, $summary, $breakdown),
            'provider' => $provider->name(),
            'model' => $provider->isAvailable() ? $provider->model() : null,
            'intent' => $intent,
        ];
    }

    /**
     * Ask the model for a structured intent, falling back to a safe default.
     *
     * Every field is validated against a whitelist, so a hallucinated value
     * degrades to the default rather than reaching a query.
     *
     * @return array{period: string, dimension: string, intent: string}
     */
    private function classify(string $question, User $user): array
    {
        $default = ['period' => 'this_month', 'dimension' => 'none', 'intent' => 'summary'];

        $provider = $this->providers->driver();

        if (! $provider->isAvailable()) {
            return $default;
        }

        $response = $provider->chat([
            AiMessage::system(self::INTENT_PROMPT),
            AiMessage::user($question),
        ], ['json' => true, 'max_tokens' => 120]);

        $decoded = $response->json();

        if ($decoded === null) {
            return $default;
        }

        return [
            'period' => $this->oneOf($decoded['period'] ?? null,
                ['this_month', 'last_month', 'this_year', 'last_30_days', 'all'], 'this_month'),
            'dimension' => $this->oneOf($decoded['dimension'] ?? null,
                ['none', 'charging_type', 'charging_mode', 'station', 'network', 'vehicle'], 'none'),
            'intent' => $this->oneOf($decoded['intent'] ?? null,
                ['summary', 'breakdown', 'trend', 'unknown'], 'summary'),
        ];
    }

    /**
     * Phrase the answer, then verify it invented no figures.
     *
     * This is the guard that makes "advisory" real: a sentence containing a
     * number the application did not compute is discarded outright.
     *
     * @param  array<string, mixed>  $facts
     */
    private function narrate(string $question, array $facts, AiProviderInterface $provider): ?string
    {
        if (! $provider->isAvailable()) {
            return null;
        }

        $response = $provider->chat([
            AiMessage::system(self::NARRATION_PROMPT),
            AiMessage::user(
                "Facts (the only numbers you may use):\n".
                json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).
                "\n\nQuestion: ".$question
            ),
        ], ['max_tokens' => 300]);

        if (! $response->succeeded) {
            return null;
        }

        $answer = trim($response->content);

        if ($this->containsOnlyKnownNumbers($answer, $facts)) {
            return $answer;
        }

        // Worth knowing about: a rising rejection rate means the model is
        // inventing figures and the prompt or the model needs revisiting.
        // The question is not logged -- it is the user's private financial
        // enquiry (docs/10 rule 13).
        Log::info('Assistant narration rejected: contained an uncomputed figure', [
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'answer' => mb_substr($answer, 0, 200),
        ]);

        return null;
    }

    /**
     * Whether every number in $text appears among the computed facts.
     *
     * Small integers (0-31) are ignored: they show up as counts, dates and
     * ordinals in ordinary prose and flagging them would reject almost every
     * valid sentence. Money and energy figures, which are what actually
     * matter, always carry decimals or exceed that range.
     *
     * @param  array<string, mixed>  $facts
     */
    private function containsOnlyKnownNumbers(string $text, array $facts): bool
    {
        $known = [];

        array_walk_recursive($facts, function ($value) use (&$known): void {
            if (is_numeric($value)) {
                // Compared without trailing zeros so "214.00" matches "214".
                $known[] = rtrim(rtrim((string) $value, '0'), '.');
            }
        });

        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $text, $matches);

        foreach ($matches[0] as $found) {
            $normalised = rtrim(rtrim(str_replace(',', '', $found), '0'), '.');

            if ($normalised === '') {
                continue;
            }

            if (is_numeric($normalised) && (float) $normalised <= 31 && ! str_contains($found, '.')) {
                continue;
            }

            if (! in_array($normalised, $known, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The figures the assistant may talk about.
     *
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $breakdown
     * @return array<string, mixed>
     */
    private function factsFor(array $summary, array $breakdown, AnalyticsFilter $filter): array
    {
        $tz = (string) config('app.display_timezone');

        return [
            // Stated explicitly: left to infer, models reported baht figures
            // as dollars, which on a financial answer is simply wrong.
            'currency' => 'THB',
            'period' => [
                'from' => $filter->from?->copy()->timezone($tz)->toDateString(),
                'to' => $filter->to?->copy()->timezone($tz)->subSecond()->toDateString(),
            ],
            'sessions' => $summary['session_count'],
            'total_spend' => $summary['total_cost'],
            'total_energy_kwh' => $summary['total_kwh'],
            'total_distance_km' => $summary['total_distance_km'],
            // Null where the denominator was zero or missing (docs/06). The
            // prompt tells the model to say so rather than substitute zero.
            'cost_per_kwh' => $summary['cost_per_kwh'],
            'cost_per_km' => $summary['cost_per_km'],
            'kwh_per_100km' => $summary['kwh_per_100km'],
            'home_vs_public' => $summary['home_public_ratio'],
            'breakdown' => array_slice($breakdown, 0, 5),
        ];
    }

    /**
     * The records the figures came from (FR-017 -> include source records).
     *
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $breakdown
     * @return array<string, mixed>
     */
    private function sources(AnalyticsFilter $filter, array $summary, array $breakdown): array
    {
        return [
            'filter' => $filter->describe(),
            // Only CONFIRMED sessions are counted, so the answer reconciles
            // with the dashboard (AT-009).
            'session_count' => $summary['session_count'],
            'breakdown_rows' => count($breakdown),
            'computed_by' => 'AnalyticsService',
        ];
    }

    private function filterFor(string $period, User $user): AnalyticsFilter
    {
        $tz = (string) config('app.display_timezone');
        $now = Carbon::now($tz);
        $userId = $user->isAdmin() ? null : $user->id;

        [$from, $to] = match ($period) {
            'last_month' => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->startOfMonth()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->startOfYear()->addYear()],
            'last_30_days' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->addDay()->startOfDay()],
            'all' => [null, null],
            default => [$now->copy()->startOfMonth(), $now->copy()->startOfMonth()->addMonth()],
        };

        return new AnalyticsFilter(
            userId: $userId,
            from: $from?->utc(),
            to: $to?->utc(),
        );
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
