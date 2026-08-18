<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Services\AuditLogService;
use App\Services\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * docs/02 FR-013 budgets and FR-014 threshold alerts.
 *
 * Spend is measured by AnalyticsService, so a budget always agrees with the
 * dashboard.
 */
class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $budgets = $request->user()->budgets()
            ->orderByDesc('period_start')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(BudgetResource::collection($budgets), $budgets);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $this->authorize('create', Budget::class);

        $budget = DB::transaction(function () use ($request): Budget {
            $budget = new Budget($request->validated());
            // Ownership comes from the authenticated user, never from input.
            $budget->user_id = $request->user()->id;
            $budget->save();

            $this->audit->logCreate($budget);

            return $budget;
        });

        return ApiResponse::item(new BudgetResource($budget->refresh()), 201);
    }

    public function show(Budget $budget): JsonResponse
    {
        $this->authorize('view', $budget);

        return ApiResponse::item(new BudgetResource($budget), meta: [
            'evaluation' => $this->budgets->evaluate($budget),
        ]);
    }

    public function update(StoreBudgetRequest $request, Budget $budget): JsonResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget): void {
            $before = $budget->getOriginal();
            $budget->fill($request->validated());
            $budget->save();
            $this->audit->logUpdate($budget, $before);
        });

        return ApiResponse::item(new BudgetResource($budget->refresh()));
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $this->authorize('delete', $budget);

        DB::transaction(function () use ($budget): void {
            $this->audit->logDelete($budget);
            $budget->delete();
        });

        return ApiResponse::noContent();
    }

    /**
     * Current standing against every active budget.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $evaluations = $request->boolean('notify')
            ? $this->budgets->evaluateAndNotify($request->user())
            : $this->budgets->evaluateAll($request->user());

        return ApiResponse::item($evaluations);
    }
}
