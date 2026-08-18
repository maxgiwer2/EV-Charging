<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\BudgetPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Models\Budget;
use App\Services\AuditLogService;
use App\Services\BudgetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Budget management screens (docs/02 FR-013).
 *
 * Reuses the API form request, so the rules cannot diverge between the two
 * paths.
 */
class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Budget::class);

        return view('budgets.index', [
            'budgets' => $request->user()->budgets()->orderByDesc('period_start')->paginate(20),
            // Keyed by id so the list can show current standing inline.
            'evaluations' => collect($this->budgets->evaluateAll($request->user()))
                ->keyBy('budget_id')
                ->all(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Budget::class);

        return view('budgets.form', [
            'budget' => new Budget,
            'periods' => BudgetPeriod::cases(),
        ]);
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);

        DB::transaction(function () use ($request): void {
            $budget = new Budget($this->payload($request));
            // Ownership comes from the session, never from input.
            $budget->user_id = $request->user()->id;
            $budget->save();

            $this->audit->logCreate($budget);
        });

        return redirect()->route('budgets.manage.index')->with('status', 'Budget saved.');
    }

    public function edit(Budget $budget): View
    {
        $this->authorize('update', $budget);

        return view('budgets.form', [
            'budget' => $budget,
            'periods' => BudgetPeriod::cases(),
        ]);
    }

    public function update(StoreBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget): void {
            $before = $budget->getOriginal();
            $budget->fill($this->payload($request));
            $budget->save();

            $this->audit->logUpdate($budget, $before);
        });

        return redirect()->route('budgets.manage.index')->with('status', 'Budget updated.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        DB::transaction(function () use ($budget): void {
            $this->audit->logDelete($budget);
            $budget->delete();
        });

        return redirect()->route('budgets.manage.index')->with('status', 'Budget removed.');
    }

    /**
     * Thresholds arrive from the form as a comma-separated string; the API
     * takes an array. Normalised here so both reach the model the same way.
     *
     * @return array<string, mixed>
     */
    private function payload(StoreBudgetRequest $request): array
    {
        $validated = $request->validated();

        if ($request->filled('thresholds_csv')) {
            $thresholds = collect(explode(',', (string) $request->input('thresholds_csv')))
                ->map(fn (string $value): int => (int) trim($value))
                ->filter(fn (int $value): bool => $value > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();

            // An empty result means the field held nothing usable; fall back to
            // the defaults rather than storing an empty set that would disable
            // alerts silently.
            $validated['alert_thresholds'] = $thresholds === [] ? null : $thresholds;
        }

        return $validated;
    }
}
