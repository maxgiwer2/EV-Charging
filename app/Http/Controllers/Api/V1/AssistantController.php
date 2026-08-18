<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChargingSession;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI assistant endpoint (docs/02 FR-017).
 *
 * Every figure in the response is computed by AnalyticsService and scoped to
 * the caller, so the assistant can only ever discuss data that user is already
 * entitled to see (AT-007). `sources` states where the numbers came from,
 * which is what FR-017 requires.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function ask(Request $request): JsonResponse
    {
        // Reuses the session policy: the assistant reports on charging data, so
        // whoever may not read that may not ask about it either.
        $this->authorize('viewAny', ChargingSession::class);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $result = $this->assistant->ask($validated['question'], $request->user());

        return ApiResponse::item([
            // Null when no provider is configured or the reply was rejected.
            // The facts stand on their own in that case.
            'answer' => $result['answer'],
            'facts' => $result['facts'],
            'sources' => $result['sources'],
        ], meta: [
            'provider' => $result['provider'],
            'model' => $result['model'],
            'intent' => $result['intent'],
            // Stated plainly so a client never presents the narration as
            // authoritative: the figures are, the sentence is not.
            'advisory' => true,
        ]);
    }
}
