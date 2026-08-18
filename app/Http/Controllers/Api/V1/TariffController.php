<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ImmutableTariffVersion;
use App\Exceptions\TariffPeriodOverlap;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tariff\StoreTariffRequest;
use App\Http\Requests\Tariff\StoreTariffVersionRequest;
use App\Http\Resources\TariffResource;
use App\Http\Resources\TariffVersionResource;
use App\Models\ChargingTariff;
use App\Models\TariffVersion;
use App\Services\AuditLogService;
use App\Services\TariffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * docs/07 -> Tariffs, docs/04 -> Admin Tariff.
 *
 * Tariffs are shared reference data: everyone reads them (a user needs to see
 * what they were charged), only admins write them.
 */
class TariffController extends Controller
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingTariff::class);

        $tariffs = ChargingTariff::query()
            ->with(['network', 'station'])
            ->withCount('versions')
            ->when($request->filled('charging_type'), fn ($q) => $q->where('charging_type', $request->string('charging_type')->value()))
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(TariffResource::collection($tariffs), $tariffs);
    }

    public function store(StoreTariffRequest $request): JsonResponse
    {
        $this->authorize('create', ChargingTariff::class);

        $tariff = DB::transaction(function () use ($request): ChargingTariff {
            $tariff = ChargingTariff::create($request->validated());
            $this->audit->logCreate($tariff);

            return $tariff;
        });

        return ApiResponse::item(new TariffResource($tariff->refresh()), 201);
    }

    public function show(ChargingTariff $tariff): JsonResponse
    {
        $this->authorize('view', $tariff);

        return ApiResponse::item(new TariffResource(
            $tariff->load(['network', 'station', 'versions' => fn ($q) => $q->orderByDesc('effective_from')])
        ));
    }

    public function update(StoreTariffRequest $request, ChargingTariff $tariff): JsonResponse
    {
        $this->authorize('update', $tariff);

        DB::transaction(function () use ($request, $tariff): void {
            $before = $tariff->getOriginal();
            $tariff->fill($request->validated());
            $tariff->save();
            $this->audit->logUpdate($tariff, $before);
        });

        return ApiResponse::item(new TariffResource($tariff->refresh()));
    }

    public function destroy(ChargingTariff $tariff): JsonResponse
    {
        $this->authorize('delete', $tariff);

        DB::transaction(function () use ($tariff): void {
            $this->audit->logDelete($tariff);
            // Soft delete: historical sessions resolve through its versions.
            $tariff->delete();
        });

        return ApiResponse::noContent();
    }

    /**
     * Publish a new priced version (docs/04 -> Admin Tariff).
     */
    public function storeVersion(StoreTariffVersionRequest $request, ChargingTariff $tariff): JsonResponse
    {
        $this->authorize('update', $tariff);

        try {
            $version = $this->tariffs->publishVersion($tariff, $request->validated(), $request->user());
        } catch (TariffPeriodOverlap $e) {
            return ApiResponse::error(ErrorCode::TARIFF_OVERLAP, $e->getMessage());
        }

        return ApiResponse::item(new TariffVersionResource($version), 201);
    }

    /**
     * Amend a version no session has used.
     *
     * Once a session has been priced against it the version is evidence, not
     * configuration (AT-006), and the request is refused.
     */
    public function updateVersion(
        StoreTariffVersionRequest $request,
        ChargingTariff $tariff,
        TariffVersion $version,
    ): JsonResponse {
        $this->authorize('update', $tariff);

        if ($version->charging_tariff_id !== $tariff->id) {
            return ApiResponse::error(ErrorCode::NOT_FOUND, 'Resource not found.');
        }

        try {
            $amended = $this->tariffs->amendVersion($version, $request->validated(), $request->user());
        } catch (ImmutableTariffVersion $e) {
            return ApiResponse::error(ErrorCode::CONFLICT, $e->getMessage());
        }

        return ApiResponse::item(new TariffVersionResource($amended));
    }
}
