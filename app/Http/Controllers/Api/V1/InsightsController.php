<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Services\AnomalyDetectionService;
use App\Services\ForecastService;
use App\Support\Anomaly;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anomalies and forecasting (docs/02 FR-018).
 *
 * Both are statistical, not AI: they inform decisions about money, so they
 * have to be reproducible and explainable. The assistant may later narrate
 * these figures, but it does not produce them.
 */
class InsightsController extends Controller
{
    public function __construct(
        private readonly AnomalyDetectionService $anomalies,
        private readonly ForecastService $forecast,
    ) {}

    /**
     * Sessions that stand out from the caller's own history.
     */
    public function anomalies(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingSession::class);

        $found = $request->boolean('notify')
            ? $this->anomalies->detectAndNotify($request->user())
            : $this->anomalies->detect($request->user());

        return ApiResponse::item(
            array_map(fn (Anomaly $a): array => $a->toArray(), $found),
            meta: [
                // An empty list can mean "nothing unusual" or "not enough
                // history to judge". Saying which avoids false reassurance.
                'method' => 'modified_z_score_over_median_absolute_deviation',
                'baseline' => 'per user, confirmed sessions, last 12 months',
            ],
        );
    }

    /**
     * Projected spend for the current month.
     */
    public function forecast(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingSession::class);

        $forecast = $this->forecast->projectCurrentMonth($request->user());

        return ApiResponse::item([
            ...$forecast->toArray(),
            'typical_monthly_spend' => $this->forecast->typicalMonthlySpend($request->user()),
        ], meta: [
            'method' => 'run_rate',
            // Stated so a client never renders a projection as a commitment.
            'advisory' => true,
        ]);
    }
}
