<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\StoreChargingStationRequest;
use App\Http\Resources\ChargingStationResource;
use App\Models\ChargingStation;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargingStationController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingStation::class);

        $stations = ChargingStation::query()
            ->with('network')
            ->when($request->filled('network_id'), fn ($q) => $q->where('network_id', $request->integer('network_id')))
            ->when($request->filled('province'), fn ($q) => $q->where('province', $request->string('province')->value()))
            ->when($request->filled('search'), function ($q) use ($request): void {
                // Bound as a parameter by the query builder, so the wildcards
                // are data and not injectable SQL.
                $q->where('name', 'like', '%'.$request->string('search')->value().'%');
            })
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(ChargingStationResource::collection($stations), $stations);
    }

    public function store(StoreChargingStationRequest $request): JsonResponse
    {
        $this->authorize('create', ChargingStation::class);

        $station = DB::transaction(function () use ($request): ChargingStation {
            $station = ChargingStation::create($request->validated());
            $this->audit->logCreate($station);

            return $station;
        });

        return ApiResponse::item(new ChargingStationResource($station->refresh()->load('network')), 201);
    }

    public function show(ChargingStation $chargingStation): JsonResponse
    {
        $this->authorize('view', $chargingStation);

        return ApiResponse::item(
            new ChargingStationResource($chargingStation->load(['network', 'connectors']))
        );
    }

    public function update(StoreChargingStationRequest $request, ChargingStation $chargingStation): JsonResponse
    {
        $this->authorize('update', $chargingStation);

        DB::transaction(function () use ($request, $chargingStation): void {
            // Snapshot before mutating: save() overwrites the model's
            // original attributes, losing the pre-update values.
            $before = $chargingStation->getOriginal();
            $chargingStation->fill($request->validated());
            $chargingStation->save();
            $this->audit->logUpdate($chargingStation, $before);
        });

        return ApiResponse::item(new ChargingStationResource($chargingStation->refresh()->load('network')));
    }

    public function destroy(ChargingStation $chargingStation): JsonResponse
    {
        $this->authorize('delete', $chargingStation);

        DB::transaction(function () use ($chargingStation): void {
            $this->audit->logDelete($chargingStation);
            $chargingStation->delete();
        });

        return ApiResponse::noContent();
    }
}
