<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle management screens (docs/02 FR-002).
 *
 * Reuses the API form requests, so the web and API paths validate identically
 * and a rule can never be enforced on one but not the other.
 */
class VehicleController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vehicle::class);

        $user = $request->user();
        $query = Vehicle::query()->withCount('chargingSessions');

        if (! $user->isAdmin()) {
            $query->ownedBy($user->id);
        }

        return view('vehicles.index', [
            'vehicles' => $query->orderBy('make')->orderBy('model')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Vehicle::class);

        return view('vehicles.form', ['vehicle' => new Vehicle]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        $this->authorize('create', Vehicle::class);

        DB::transaction(function () use ($request): void {
            $vehicle = new Vehicle($request->validated());
            // Ownership comes from the session, never from input.
            $vehicle->user_id = $request->user()->id;
            $vehicle->save();

            $this->audit->logCreate($vehicle);
        });

        return redirect()->route('vehicles.manage.index')->with('status', 'Vehicle added.');
    }

    public function edit(Vehicle $vehicle): View
    {
        $this->authorize('update', $vehicle);

        return view('vehicles.form', ['vehicle' => $vehicle]);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('update', $vehicle);

        DB::transaction(function () use ($request, $vehicle): void {
            // Snapshot before mutating: save() overwrites the model's original
            // attributes, losing the pre-update values.
            $before = $vehicle->getOriginal();
            $vehicle->fill($request->validated());
            $vehicle->save();

            $this->audit->logUpdate($vehicle, $before);
        });

        return redirect()->route('vehicles.manage.index')->with('status', 'Vehicle updated.');
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('delete', $vehicle);

        DB::transaction(function () use ($vehicle): void {
            $this->audit->logDelete($vehicle);
            // Soft delete: historical sessions still reference this vehicle.
            $vehicle->delete();
        });

        return redirect()->route('vehicles.manage.index')->with('status', 'Vehicle removed.');
    }
}
