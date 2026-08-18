<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Enums\ReceiptStatus;
use App\Exceptions\InvalidReceiptTransition;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receipt\StoreReceiptRequest;
use App\Http\Requests\Receipt\VerifyReceiptRequest;
use App\Http\Resources\ReceiptResource;
use App\Jobs\ProcessReceiptOcr;
use App\Models\Receipt;
use App\Services\ReceiptService;
use App\Services\ReceiptStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * docs/07 -> Receipts, docs/04 -> Receipt OCR flow.
 *
 * Upload -> queue OCR -> review -> verify. The controller orchestrates only;
 * every status change goes through ReceiptService, which is the single writer
 * (docs/10 rule 10).
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly ReceiptStorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Receipt::class);

        $user = $request->user();

        $query = Receipt::query()->with('ocrResults');

        if (! $user->isAdmin()) {
            $query->uploadedBy($user->id);
        }

        $query->when($request->boolean('awaiting_review'), fn ($q) => $q->awaitingReview())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()));

        $receipts = $query->orderByDesc('uploaded_at')
            ->paginate(perPage: min($request->integer('per_page', 15), 100));

        return ApiResponse::paginated(ReceiptResource::collection($receipts), $receipts);
    }

    /**
     * Upload a receipt (AT-003) and queue OCR.
     *
     * A probable duplicate does not block the upload (AT-005): it is flagged
     * on the record and surfaced here so a human can decide.
     */
    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', Receipt::class);

        $receipt = $this->receipts->upload(
            $request->file('file'),
            $request->user(),
            $request->integer('charging_session_id') ?: null,
        );

        // Dispatched after the upload transaction commits, so the worker can
        // never read a receipt row that is not yet visible to it.
        ProcessReceiptOcr::dispatch($receipt->id);

        return ApiResponse::item(new ReceiptResource($receipt->refresh()), 201);
    }

    public function show(Receipt $receipt): JsonResponse
    {
        $this->authorize('view', $receipt);

        return ApiResponse::item(new ReceiptResource($receipt->load('ocrResults')));
    }

    /**
     * Re-run OCR, e.g. after a provider outage or a configuration change.
     *
     * Safe to call repeatedly: the job is unique per receipt and refuses a
     * receipt that is already terminal (docs/03 -> idempotent OCR jobs).
     */
    public function ocr(Receipt $receipt): JsonResponse
    {
        $this->authorize('update', $receipt);

        if ($receipt->status->isTerminal()) {
            return ApiResponse::error(
                ErrorCode::INVALID_STATE_TRANSITION,
                'A verified or rejected receipt cannot be re-processed.',
            );
        }

        // Return to pending so the queued job can claim it; a receipt sitting
        // in OCR_REVIEW is otherwise not in a state markProcessing() accepts.
        if ($receipt->status === ReceiptStatus::OCR_REVIEW) {
            return ApiResponse::error(
                ErrorCode::INVALID_STATE_TRANSITION,
                'This receipt is already awaiting review. Confirm or reject it first.',
            );
        }

        ProcessReceiptOcr::dispatch($receipt->id);

        return ApiResponse::item(new ReceiptResource($receipt->refresh()), 202);
    }

    /**
     * Human confirmation (AT-004). This is the only path to VERIFIED.
     */
    public function verify(VerifyReceiptRequest $request, Receipt $receipt): JsonResponse
    {
        $this->authorize('verify', $receipt);

        try {
            $verified = $this->receipts->verify(
                $receipt,
                $request->validated(),
                $request->user(),
            );
        } catch (InvalidReceiptTransition $e) {
            return ApiResponse::error(ErrorCode::INVALID_STATE_TRANSITION, $e->getMessage());
        }

        return ApiResponse::item(new ReceiptResource($verified->load('ocrResults')));
    }

    public function reject(Request $request, Receipt $receipt): JsonResponse
    {
        $this->authorize('reject', $receipt);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $rejected = $this->receipts->reject(
                $receipt,
                $request->user(),
                $validated['reason'] ?? null,
            );
        } catch (InvalidReceiptTransition $e) {
            return ApiResponse::error(ErrorCode::INVALID_STATE_TRANSITION, $e->getMessage());
        }

        return ApiResponse::item(new ReceiptResource($rejected));
    }

    /**
     * Stream a receipt file to an authorized caller.
     *
     * This route exists because the receipts disk has no public URL: it is the
     * only way to read a receipt, and the policy above is what makes it safe
     * (docs/03, AT-007). The file is streamed rather than redirected to, so no
     * storage path is ever disclosed (docs/07).
     */
    public function download(Receipt $receipt): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $receipt);

        if (! $this->storage->exists($receipt)) {
            return ApiResponse::error(ErrorCode::NOT_FOUND, 'Receipt file is no longer available.');
        }

        return $this->storage->disk()->download(
            $receipt->file_path,
            $receipt->original_filename,
            [
                'Content-Type' => $receipt->mime_type,
                // Receipts are private financial documents; keep them out of
                // shared caches and proxies.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
