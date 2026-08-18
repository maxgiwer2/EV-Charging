<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Receipt\StoreReceiptRequest;
use App\Jobs\ProcessReceiptOcr;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Receipt upload screen (docs/04 -> Scan/Upload).
 *
 * Uses the same StoreReceiptRequest as the API, so the magic-byte check that
 * rejects a disguised file applies identically here -- a browser upload is not
 * more trustworthy than an API one.
 */
class ReceiptUploadController extends Controller
{
    public function __construct(private readonly ReceiptService $receipts) {}

    public function create(): View
    {
        $this->authorize('create', Receipt::class);

        return view('receipts.upload', [
            'maxSizeMb' => round(((int) config('receipts.max_size_kb')) / 1024, 1),
            'accept' => implode(',', config('receipts.mime_types')),
            // Surfaced so a user is not left wondering why nothing was
            // extracted on a system with no OCR provider configured.
            'ocrEnabled' => config('ocr.driver') !== 'none',
        ]);
    }

    public function store(StoreReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', Receipt::class);

        $receipt = $this->receipts->upload(
            $request->file('file'),
            $request->user(),
            $request->integer('charging_session_id') ?: null,
        );

        // Queued after the upload transaction commits, so the worker cannot
        // read a row that is not yet visible to it.
        ProcessReceiptOcr::dispatch($receipt->id);

        $message = $receipt->duplicate_matches === null
            ? 'Receipt uploaded. It will appear for review once processed.'
            // AT-005: flagged, not blocked -- say so rather than silently
            // accepting it.
            : 'Receipt uploaded, but it looks like one already on file. Check before confirming.';

        return redirect()->route('receipts.review.show', $receipt)->with('status', $message);
    }
}
