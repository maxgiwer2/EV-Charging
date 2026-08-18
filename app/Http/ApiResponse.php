<?php

declare(strict_types=1);

namespace App\Http;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The single JSON envelope for /api/v1 (docs/07 -> consistent JSON envelopes).
 *
 * Success: {"data": ...}            plus {"meta": {...}} for collections
 * Failure: {"error": {"code": ..., "message": ..., "details": ...}}
 *
 * Keeping both shapes in one place is what makes the contract consistent;
 * controllers never hand-roll a response body.
 */
final class ApiResponse
{
    /**
     * @param  JsonResource|array<array-key, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function item(JsonResource|array $data, int $status = 200, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Paginated collection. Pagination metadata is required on every
     * collection endpoint (docs/07 -> paginate collections).
     *
     * LengthAwarePaginator's TValue is invariant, so a concrete
     * paginator (of Vehicle, Station, ...) is not a subtype of one typed
     * to Model. A method-level template accepts any of them.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     */
    public static function paginated(ResourceCollection $collection, LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => $collection,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function error(ErrorCode $code, string $message, ?array $details = null, ?int $status = null): JsonResponse
    {
        $error = [
            'code' => $code->value,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status ?? $code->httpStatus());
    }
}
