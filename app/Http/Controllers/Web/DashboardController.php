<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Services\AnalyticsFilter;
use App\Services\AnalyticsService;
use App\Services\AnomalyDetectionService;
use App\Services\BudgetService;
use App\Services\ForecastService;
use App\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The dashboard screen (docs/02 FR-010, docs/06).
 *
 * Reads through AnalyticsService, so the page and the API report the same
 * numbers and both reconcile with confirmed sessions (AT-009).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ReportService $reports,
        private readonly BudgetService $budgets,
        private readonly ForecastService $forecast,
        private readonly AnomalyDetectionService $anomalies,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ChargingSession::class);

        $filter = AnalyticsFilter::fromRequest($request, $request->user());

        return view('dashboard.index', [
            'filter' => $filter,
            'summary' => $this->analytics->summary($filter),
            'comparison' => $this->analytics->comparison($filter, $filter->previousPeriod()),
            'trends' => $this->analytics->trends($filter, 'day'),
            'byType' => $this->analytics->breakdown($filter, 'charging_type'),
            'byStation' => array_slice($this->analytics->breakdown($filter, 'station'), 0, 5),
            // Recent activity, capped: the dashboard is a summary, the report
            // screen is for browsing everything.
            'recent' => $this->reports->chargingRows($filter)->take(10)->all(),
            'columns' => ReportService::CHARGING_COLUMNS,

            // Budgets and the projection alert on the way past a threshold, so
            // evaluating on view is what makes them useful (FR-013/FR-014).
            'budgets' => $this->budgets->evaluateAndNotify($request->user()),
            'forecast' => $this->forecast->projectCurrentMonth($request->user())->toArray(),
            'typicalSpend' => $this->forecast->typicalMonthlySpend($request->user()),
            // Capped: the dashboard points at the most notable, the insights
            // list has the rest.
            'anomalies' => array_slice($this->anomalies->detectAndNotify($request->user()), 0, 3),
        ]);
    }
}
