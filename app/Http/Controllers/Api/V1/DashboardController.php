<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\AnalyticsFilter;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * docs/07 -> Dashboard, docs/02 FR-010, docs/06.
 *
 * Every figure here comes from CONFIRMED sessions only, so the dashboard
 * reconciles with the underlying records (AT-009).
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    /**
     * Headline KPIs, plus the previous period for comparison.
     */
    public function summary(Request $request): JsonResponse
    {
        $filter = $this->filter($request);

        return ApiResponse::item([
            'summary' => $this->analytics->summary($filter),
            'comparison' => $this->analytics->comparison($filter, $filter->previousPeriod()),
        ], meta: ['filter' => $filter->describe()]);
    }

    /**
     * Time series for charts.
     */
    public function trends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Whitelisted rather than free text: the value selects a raw SQL
            // date expression.
            'granularity' => ['sometimes', 'in:day,month,year'],
        ]);

        $filter = $this->filter($request);

        return ApiResponse::item(
            $this->analytics->trends($filter, $validated['granularity'] ?? 'month'),
            meta: ['filter' => $filter->describe()],
        );
    }

    /**
     * Spend split by a dimension (docs/06 -> Dimensions).
     */
    public function breakdowns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dimension' => ['sometimes', 'in:charging_type,charging_mode,station,network,vehicle'],
        ]);

        $filter = $this->filter($request);
        $dimension = $validated['dimension'] ?? 'charging_type';

        return ApiResponse::item(
            $this->analytics->breakdown($filter, $dimension),
            meta: ['filter' => $filter->describe(), 'dimension' => $dimension],
        );
    }

    /**
     * The date window defaults to the current calendar month in the display
     * timezone, which is what a user means by "this month" (docs/10 rule 7).
     * All other filters are honoured whether or not dates were supplied.
     */
    private function filter(Request $request): AnalyticsFilter
    {
        return AnalyticsFilter::fromRequest($request, $request->user());
    }
}
