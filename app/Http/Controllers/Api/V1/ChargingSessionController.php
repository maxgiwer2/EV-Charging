<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SessionStatus;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChargingSession\StoreChargingSessionRequest;
use App\Http\Requests\ChargingSession\UpdateChargingSessionRequest;
use App\Http\Resources\ChargingSessionResource;
use App\Models\ChargingSession;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * docs/02 FR-003, docs/07 -> Charging.
 *
 * Sessions are created as DRAFT. Cost calculation and confirmation arrive in
 * M3; until then totals stay at their zero defaults rather than being guessed.
 */
class ChargingSessionController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingSession::class);

        $user = $request->user();

        $query = ChargingSession::query()
            // Eager loaded because the resource renders both (docs/03 ->
            // avoid N+1 queries).
            ->with(['vehicle', 'station']);

        if (! $user->isAdmin()) {
            $query->ownedBy($user->id);
        }

        // Filters (docs/02 FR-010, docs/06 -> Dimensions).
        $query->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->when($request->filled('station_id'), fn ($q) => $q->where('station_id', $request->integer('station_id')))
            ->when($request->filled('charging_type'), fn ($q) => $q->where('charging_type', $request->string('charging_type')->value()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('from'), fn ($q) => $q->where('started_at', '>=', $request->date('from')))
            // Half-open upper bound so a session at midnight is not counted in
            // two adjacent periods.
            ->when($request->filled('to'), fn ($q) => $q->where('started_at', '<', $request->date('to')));

        $sessions = $query->orderByDesc('started_at')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(ChargingSessionResource::collection($sessions), $sessions);
    }

    public function store(StoreChargingSessionRequest $request): JsonResponse
    {
        $this->authorize('create', ChargingSession::class);

        $session = DB::transaction(function () use ($request): ChargingSession {
            $session = new ChargingSession($request->validated());
            $session->user_id = $request->user()->id;
            // Explicit rather than relying on the column default, so the
            // starting state is visible in the code that creates it.
            $session->status = SessionStatus::DRAFT;

            $session->duration_minutes ??= $this->deriveDurationMinutes($session);
            $session->save();

            $this->audit->logCreate($session);

            return $session;
        });

        return ApiResponse::item(
            new ChargingSessionResource($session->refresh()->load(['vehicle', 'station'])),
            201
        );
    }

    public function show(ChargingSession $chargingSession): JsonResponse
    {
        $this->authorize('view', $chargingSession);

        return ApiResponse::item(
            new ChargingSessionResource($chargingSession->load(['vehicle', 'station', 'costLines']))
        );
    }

    public function update(UpdateChargingSessionRequest $request, ChargingSession $chargingSession): JsonResponse
    {
        $this->authorize('update', $chargingSession);

        DB::transaction(function () use ($request, $chargingSession): void {
            // Snapshot before mutating: save() overwrites the model's
            // original attributes, losing the pre-update values.
            $before = $chargingSession->getOriginal();
            $chargingSession->fill($request->validated());
            $chargingSession->duration_minutes ??= $this->deriveDurationMinutes($chargingSession);
            $chargingSession->save();

            $this->audit->logUpdate($chargingSession, $before);
        });

        return ApiResponse::item(
            new ChargingSessionResource($chargingSession->refresh()->load(['vehicle', 'station']))
        );
    }

    public function destroy(ChargingSession $chargingSession): JsonResponse
    {
        $this->authorize('delete', $chargingSession);

        DB::transaction(function () use ($chargingSession): void {
            $this->audit->logDelete($chargingSession);
            // Soft delete only -- financial records are never removed
            // (docs/10 rule 15).
            $chargingSession->delete();
        });

        return ApiResponse::noContent();
    }

    /**
     * Fill duration from the timestamps when the client did not supply it.
     * Returns null when the session has no end time, rather than inventing a
     * zero that would later read as an instantaneous charge.
     */
    private function deriveDurationMinutes(ChargingSession $session): ?int
    {
        if ($session->ended_at === null) {
            return null;
        }

        return (int) $session->started_at->diffInMinutes($session->ended_at);
    }
}
