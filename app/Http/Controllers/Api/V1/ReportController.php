<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ChargingSession;
use App\Services\AnalyticsFilter;
use App\Services\AnalyticsService;
use App\Services\AuditLogService;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/07 -> Reports, docs/02 FR-011 and FR-012, AT-008.
 *
 * Reports and exports share AnalyticsFilter with the dashboard, so an export
 * contains exactly the records the same filter selects.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly AnalyticsService $analytics,
        private readonly ExportService $exports,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Row-level charging report.
     */
    public function charging(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingSession::class);

        $filter = AnalyticsFilter::fromRequest($request, $request->user());

        // Capped: the JSON report is for viewing, exports are for bulk.
        $rows = $this->reports->chargingRows($filter)->take(1000)->all();

        return ApiResponse::item($rows, meta: [
            'filter' => $filter->describe(),
            'summary' => $this->analytics->summary($filter),
        ]);
    }

    /**
     * Spend grouped by vehicle, station or network (docs/07 -> Reports).
     */
    public function breakdown(Request $request, string $dimension): JsonResponse
    {
        $this->authorize('viewAny', ChargingSession::class);

        $filter = AnalyticsFilter::fromRequest($request, $request->user());

        return ApiResponse::item(
            $this->analytics->breakdown($filter, $dimension),
            meta: ['filter' => $filter->describe(), 'dimension' => $dimension],
        );
    }

    /**
     * Export the filtered records (FR-012, AT-008).
     *
     * The export is audited: it moves financial data out of the system, so who
     * took what and when is worth recording (FR-015).
     */
    public function export(Request $request): Response
    {
        $this->authorize('viewAny', ChargingSession::class);

        $validated = $request->validate([
            'format' => ['required', 'in:csv,xlsx,pdf'],
        ]);

        $filter = AnalyticsFilter::fromRequest($request, $request->user());
        /** @var 'csv'|'xlsx'|'pdf' $format */
        $format = $validated['format'];
        $filename = 'charging-report-'.now()->format('Ymd-His').'.'.$format;

        $this->audit->log(
            AuditLog::ACTION_EXPORT,
            $request->user(),
            null,
            ['format' => $format, 'filter' => $filter->describe()],
        );

        return match ($format) {
            'csv' => $this->exports->csv($filter, $filename),
            'xlsx' => $this->exports->xlsx($filter, $filename),
            'pdf' => $this->exports->pdf($filter, $this->analytics->summary($filter), $filename),
        };
    }
}
