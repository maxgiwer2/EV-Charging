<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Exceptions\InvalidReceiptTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receipt\VerifyReceiptRequest;
use App\Models\ChargingStation;
use App\Models\Receipt;
use App\Models\ReceiptOcrResult;
use App\Services\ReceiptService;
use App\Services\ReceiptStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The receipt review UI (docs/09 M2 -> review UI, docs/04 -> Receipt OCR).
 *
 * A thin server-rendered layer over the same ReceiptService the API uses, so
 * the "only one path to VERIFIED" guarantee holds for the web UI too
 * (FR-005, AT-004).
 */
class ReceiptReviewController extends Controller
{
    public function __construct(
        private readonly ReceiptService $receipts,
        private readonly ReceiptStorageService $storage,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Receipt::class);

        $user = $request->user();

        $query = Receipt::query();

        if (! $user->isAdmin()) {
            $query->uploadedBy($user->id);
        }

        if ($request->string('filter')->value() === 'awaiting') {
            $query->awaitingReview();
        }

        return view('receipts.index', [
            'receipts' => $query->orderByDesc('uploaded_at')->paginate(20),
        ]);
    }

    public function show(Request $request, Receipt $receipt): View
    {
        $this->authorize('view', $receipt);

        $receipt->load(['ocrResults' => fn ($q) => $q->latest('processed_at'), 'verifier']);

        $latest = $receipt->ocrResults->first();

        return view('receipts.show', [
            'receipt' => $receipt,
            // Only the reviewer's own vehicles may be selected (AT-007); the
            // request re-validates this, so the list is convenience, not the
            // control.
            'vehicles' => $request->user()->vehicles()->orderBy('make')->get(),
            'stations' => ChargingStation::query()->active()->orderBy('name')->get(),
            // datetime-local needs `Y-m-d\TH:i` in the displayed zone, so the
            // reviewer sees the local time they read off the receipt.
            'transactionDate' => $this->suggestedTransactionDate(
                $latest instanceof ReceiptOcrResult ? ($latest->extracted_data ?? []) : [],
                $receipt,
            ),
        ]);
    }

    public function verify(VerifyReceiptRequest $request, Receipt $receipt): RedirectResponse
    {
        // Authorization already ran in the form request, before validation.
        try {
            $this->receipts->verify($receipt, $request->validated(), $request->user());
        } catch (InvalidReceiptTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('receipts.review.index')
            ->with('status', 'Receipt confirmed and charging session recorded.');
    }

    public function reject(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->authorize('reject', $receipt);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->receipts->reject($receipt, $request->user(), $validated['reason'] ?? null);
        } catch (InvalidReceiptTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('receipts.review.index')
            ->with('status', 'Receipt rejected.');
    }

    /**
     * Stream the receipt image into the review page.
     *
     * Same policy as the API download route: the disk has no public URL, so
     * this check is what keeps one user's receipt out of another's browser
     * (docs/03, AT-007).
     */
    public function file(Receipt $receipt): StreamedResponse
    {
        $this->authorize('view', $receipt);

        abort_unless($this->storage->exists($receipt), 404);

        return $this->storage->disk()->response(
            $receipt->file_path,
            $receipt->original_filename,
            [
                'Content-Type' => $receipt->mime_type,
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    /**
     * Best-effort pre-fill for the date field, in the display timezone.
     *
     * Falls back to the upload time when OCR read no date -- a plausible
     * default the reviewer can correct, never a value that gets recorded
     * without them submitting it.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function suggestedTransactionDate(array $extracted, Receipt $receipt): string
    {
        $raw = $extracted['transaction_date']['value'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return Carbon::parse($raw)
                    ->timezone(config('app.display_timezone'))
                    ->format('Y-m-d\TH:i');
            } catch (Throwable) {
                // Unparseable provider date: fall through to the upload time.
            }
        }

        return $receipt->uploaded_at
            ->timezone(config('app.display_timezone'))
            ->format('Y-m-d\TH:i');
    }
}
