<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\EnergySource;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChargingSession\StoreChargingSessionRequest;
use App\Models\ChargingSession;
use App\Models\ChargingStation;
use App\Services\AuditLogService;
use App\Services\ChargingSessionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quick entry (docs/04 -> Quick Entry): vehicle, station, kWh, amount, date.
 *
 * The path that mattered most after M3: before confirm/cancel existed, a
 * manually recorded charge could never count toward a total. This screen is
 * the one a driver uses at the charger, so it is deliberately short and
 * confirms in a single step -- there is nothing to review when the person
 * entering the data is the person who was standing there.
 */
class QuickEntryController extends Controller
{
    public function __construct(
        private readonly ChargingSessionService $sessions,
        private readonly AuditLogService $audit,
    ) {}

    public function create(Request $request): View
    {
        $this->authorize('create', ChargingSession::class);

        return view('sessions.quick-entry', [
            'vehicles' => $request->user()->vehicles()->active()->orderBy('make')->get(),
            'stations' => ChargingStation::query()->active()->orderBy('name')->get(),
            // Pre-filled with now, in the user's timezone, because the entry is
            // almost always made at the charger (docs/10 rule 7).
            'now' => now()->timezone((string) config('app.display_timezone'))->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(StoreChargingSessionRequest $request): RedirectResponse
    {
        $this->authorize('create', ChargingSession::class);

        $session = DB::transaction(function () use ($request): ChargingSession {
            $session = new ChargingSession($request->safe()->except(self::MONEY_FIELDS));
            $session->user_id = $request->user()->id;
            // Explicit rather than relying on the column default: a freshly
            // saved model does not load defaults into memory.
            $session->status = SessionStatus::DRAFT;
            $session->save();

            // Money is derived by the cost engine, never mass-assigned.
            $this->sessions->applyAmounts(
                $session,
                $this->amounts($request),
                $request->enum('energy_source', EnergySource::class) ?? EnergySource::MANUAL,
            );

            $this->audit->logCreate($session);

            // Confirmed immediately: unlike a receipt, there is no second
            // source to reconcile against, so leaving it in DRAFT would just
            // hide the charge from the dashboard (AT-009).
            return $this->sessions->confirm($session, $request->user());
        });

        return redirect()
            ->route('dashboard')
            ->with('status', 'Charging session recorded ('.$session->total_amount.').');
    }

    /**
     * Validated but not fillable, so an amount reaches the database only
     * through CostCalculationService.
     *
     * @var list<string>
     */
    private const MONEY_FIELDS = [
        'unit_price', 'subtotal', 'service_fee', 'parking_fee', 'discount', 'vat', 'total',
    ];

    /**
     * @return array<string, string|null>
     */
    private function amounts(Request $request): array
    {
        $amounts = [];

        foreach ([...self::MONEY_FIELDS, 'energy_kwh'] as $field) {
            $value = $request->input($field);
            $amounts[$field] = $value === null || $value === '' ? null : (string) $value;
        }

        return $amounts;
    }
}
