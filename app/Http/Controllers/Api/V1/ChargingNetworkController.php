<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reference\StoreChargingNetworkRequest;
use App\Http\Resources\ChargingNetworkResource;
use App\Models\ChargingNetwork;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared reference data (docs/02 FR-006): readable by all, writable by admins.
 */
class ChargingNetworkController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingNetwork::class);

        $networks = ChargingNetwork::query()
            ->withCount('stations')
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(ChargingNetworkResource::collection($networks), $networks);
    }

    public function store(StoreChargingNetworkRequest $request): JsonResponse
    {
        $this->authorize('create', ChargingNetwork::class);

        $network = DB::transaction(function () use ($request): ChargingNetwork {
            $network = ChargingNetwork::create($request->validated());
            $this->audit->logCreate($network);

            return $network;
        });

        return ApiResponse::item(new ChargingNetworkResource($network->refresh()), 201);
    }

    public function show(ChargingNetwork $chargingNetwork): JsonResponse
    {
        $this->authorize('view', $chargingNetwork);

        return ApiResponse::item(new ChargingNetworkResource($chargingNetwork->loadCount('stations')));
    }

    public function update(StoreChargingNetworkRequest $request, ChargingNetwork $chargingNetwork): JsonResponse
    {
        $this->authorize('update', $chargingNetwork);

        DB::transaction(function () use ($request, $chargingNetwork): void {
            // Snapshot before mutating: save() overwrites the model's
            // original attributes, losing the pre-update values.
            $before = $chargingNetwork->getOriginal();
            $chargingNetwork->fill($request->validated());
            $chargingNetwork->save();
            $this->audit->logUpdate($chargingNetwork, $before);
        });

        return ApiResponse::item(new ChargingNetworkResource($chargingNetwork->refresh()));
    }

    public function destroy(ChargingNetwork $chargingNetwork): JsonResponse
    {
        $this->authorize('delete', $chargingNetwork);

        DB::transaction(function () use ($chargingNetwork): void {
            $this->audit->logDelete($chargingNetwork);
            $chargingNetwork->delete();
        });

        return ApiResponse::noContent();
    }
}
