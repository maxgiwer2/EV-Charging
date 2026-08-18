<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\StoreChargingConnectorRequest;
use App\Http\Resources\ChargingConnectorResource;
use App\Models\ChargingConnector;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChargingConnectorController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingConnector::class);

        $connectors = ChargingConnector::query()
            ->when($request->filled('station_id'), fn ($q) => $q->where('station_id', $request->integer('station_id')))
            ->orderBy('station_id')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(ChargingConnectorResource::collection($connectors), $connectors);
    }

    public function store(StoreChargingConnectorRequest $request): JsonResponse
    {
        $this->authorize('create', ChargingConnector::class);

        $connector = DB::transaction(function () use ($request): ChargingConnector {
            $connector = ChargingConnector::create($request->validated());
            $this->audit->logCreate($connector);

            return $connector;
        });

        return ApiResponse::item(new ChargingConnectorResource($connector->refresh()), 201);
    }

    public function show(ChargingConnector $chargingConnector): JsonResponse
    {
        $this->authorize('view', $chargingConnector);

        return ApiResponse::item(new ChargingConnectorResource($chargingConnector));
    }

    public function update(StoreChargingConnectorRequest $request, ChargingConnector $chargingConnector): JsonResponse
    {
        $this->authorize('update', $chargingConnector);

        DB::transaction(function () use ($request, $chargingConnector): void {
            // Snapshot before mutating: save() overwrites the model's
            // original attributes, losing the pre-update values.
            $before = $chargingConnector->getOriginal();
            $chargingConnector->fill($request->validated());
            $chargingConnector->save();
            $this->audit->logUpdate($chargingConnector, $before);
        });

        return ApiResponse::item(new ChargingConnectorResource($chargingConnector->refresh()));
    }

    public function destroy(ChargingConnector $chargingConnector): JsonResponse
    {
        $this->authorize('delete', $chargingConnector);

        DB::transaction(function () use ($chargingConnector): void {
            $this->audit->logDelete($chargingConnector);
            $chargingConnector->delete();
        });

        return ApiResponse::noContent();
    }
}
