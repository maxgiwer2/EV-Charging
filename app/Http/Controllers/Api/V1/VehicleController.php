<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * docs/02 FR-002, docs/07 -> Vehicles.
 */
class VehicleController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vehicle::class);

        $user = $request->user();

        $query = Vehicle::query();

        // Non-admins see only their own vehicles. Scoping at the query level
        // rather than filtering after the fact is what makes AT-007 hold even
        // if a policy check is later missed.
        if (! $user->isAdmin()) {
            $query->ownedBy($user->id);
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $vehicles = $query->orderBy('make')->orderBy('model')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(VehicleResource::collection($vehicles), $vehicles);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $this->authorize('create', Vehicle::class);

        $vehicle = DB::transaction(function () use ($request): Vehicle {
            $vehicle = new Vehicle($request->validated());
            // Ownership comes from the authenticated user, never from input.
            $vehicle->user_id = $request->user()->id;
            $vehicle->save();

            $this->audit->logCreate($vehicle);

            return $vehicle;
        });

        return ApiResponse::item(new VehicleResource($vehicle->refresh()), 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return ApiResponse::item(new VehicleResource($vehicle));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        DB::transaction(function () use ($request, $vehicle): void {
            // Snapshot before mutating: save() overwrites the model's
            // original attributes, losing the pre-update values.
            $before = $vehicle->getOriginal();
            $vehicle->fill($request->validated());
            $vehicle->save();

            // Returns null when nothing actually changed, so a no-op PUT does
            // not pollute the audit trail.
            $this->audit->logUpdate($vehicle, $before);
        });

        return ApiResponse::item(new VehicleResource($vehicle->refresh()));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('delete', $vehicle);

        DB::transaction(function () use ($vehicle): void {
            $this->audit->logDelete($vehicle);
            // Soft delete: historical sessions still reference this vehicle.
            $vehicle->delete();
        });

        return ApiResponse::noContent();
    }
}
