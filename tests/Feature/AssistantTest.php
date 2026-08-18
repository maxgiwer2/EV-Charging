<?php

declare(strict_types=1);

use App\Models\ChargingSession;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Ai\AiProviderManager;
use Illuminate\Support\Facades\Http;

/*
 * docs/02 FR-017 and the architecture rule that AI is advisory while
 * deterministic business rules remain authoritative.
 */

/** An Ollama-shaped chat completion. */
function ollamaResponse(string $content): array
{
    return [
        'model' => 'llama3.1:8b',
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
    ];
}

/** A confirmed session worth exactly 214.00 in the current month. */
function assistantSession(User $user, array $attributes = []): ChargingSession
{
    return ChargingSession::factory()->create(array_merge([
        'user_id' => $user->id,
        'vehicle_id' => Vehicle::factory()->create(['user_id' => $user->id]),
        'started_at' => now()->startOfMonth()->addDays(2),
        'energy_kwh' => '40.000',
        'distance_km' => '200.0',
        'subtotal' => '200.00',
        'vat_amount' => '14.00',
        'discount_amount' => '0.00',
        'total_amount' => '214.00',
    ], $attributes));
}

beforeEach(function (): void {
    config()->set('ai.driver', 'ollama');
    config()->set('ai.ollama.base_url', 'http://ollama.test/v1');
    config()->set('ai.ollama.model', 'llama3.1:8b');
});

it('is selectable as a driver', function (): void {
    expect(app(AiProviderManager::class)->driver()->name())->toBe('ollama');
});

it('answers from computed figures, not from the model', function (): void {
    $user = User::factory()->create();
    assistantSession($user);
    assistantSession($user);

    Http::fake([
        '*' => Http::sequence()
            ->push(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))
            ->push(ollamaResponse('You spent 428.00 across 2 charging sessions this month.')),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend this month?']);

    $response->assertOk();

    // The figures come from AnalyticsService, so they reconcile with the
    // dashboard (AT-009).
    expect($response->json('data.facts.total_spend'))->toBe('428.00')
        ->and($response->json('data.facts.sessions'))->toBe(2)
        ->and($response->json('data.answer'))->toContain('428.00');
});

it('discards a narration that invents a figure', function (): void {
    // This is the guard that makes "advisory" real. It is not hypothetical:
    // asked to divide 1385.84 by 3, the models available here produced
    // 4116.52, nothing, and 461.95.
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake([
        '*' => Http::sequence()
            ->push(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))
            ->push(ollamaResponse('You spent 9999.99 this month, averaging 3333.33 per session.')),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend?']);

    $response->assertOk();

    // The sentence is dropped; the correct figures remain.
    expect($response->json('data.answer'))->toBeNull()
        ->and($response->json('data.facts.total_spend'))->toBe('214.00');
});

it('accepts a narration that only restates known figures', function (): void {
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake([
        '*' => Http::sequence()
            ->push(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))
            ->push(ollamaResponse('Total spend was 214.00 over 1 session, at 5.35 per kWh.')),
    ]);

    $answer = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'Summarise my month'])
        ->json('data.answer');

    expect($answer)->toContain('214.00');
});

it('cites where the figures came from (FR-017)', function (): void {
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake(['*' => Http::response(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend?']);

    expect($response->json('data.sources.computed_by'))->toBe('AnalyticsService')
        ->and($response->json('data.sources.session_count'))->toBe(1)
        ->and($response->json('data.sources.filter'))->toBeArray()
        // The client must not present the sentence as authoritative.
        ->and($response->json('meta.advisory'))->toBeTrue();
});

it('never reports another user\'s data (AT-007)', function (): void {
    // The assistant is scoped to the caller, so it cannot be used as a way
    // around the ownership rules that guard every other endpoint.
    $user = User::factory()->create();
    $victim = User::factory()->create();
    assistantSession($victim);
    assistantSession($victim);

    Http::fake(['*' => Http::response(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did everyone spend?']);

    expect($response->json('data.facts.total_spend'))->toBe('0.00')
        ->and($response->json('data.facts.sessions'))->toBe(0);
});

it('ignores a hallucinated dimension rather than querying it', function (): void {
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake([
        '*' => Http::sequence()
            ->push(ollamaResponse('{"period":"nonsense","dimension":"DROP TABLE users","intent":"???"}'))
            ->push(ollamaResponse('Total spend was 214.00.')),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'anything']);

    $response->assertOk();

    // Falls back to the safe default instead of passing the value through.
    expect($response->json('meta.intent.dimension'))->toBe('none')
        ->and($response->json('meta.intent.period'))->toBe('this_month');
});

it('still answers with figures when the model is unreachable', function (): void {
    // A local model server being down must degrade the assistant, not break
    // the request.
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake(['*' => Http::response([], 500)]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend?']);

    $response->assertOk();

    expect($response->json('data.answer'))->toBeNull()
        ->and($response->json('data.facts.total_spend'))->toBe('214.00');
});

it('works with no provider configured at all', function (): void {
    config()->set('ai.driver', 'none');

    $user = User::factory()->create();
    assistantSession($user);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend?']);

    $response->assertOk();

    expect($response->json('data.answer'))->toBeNull()
        ->and($response->json('data.facts.total_spend'))->toBe('214.00')
        ->and($response->json('meta.provider'))->toBe('none');
});

it('reports a metric as unavailable rather than zero', function (): void {
    // docs/06: a metric with no denominator is unknown, not zero. The prompt
    // tells the model to say so; the fact itself must be null.
    $user = User::factory()->create();
    assistantSession($user, ['distance_km' => null, 'odometer_before_km' => null, 'odometer_after_km' => null]);

    Http::fake(['*' => Http::response(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'What is my cost per km?']);

    expect($response->json('data.facts.cost_per_km'))->toBeNull()
        ->and($response->json('data.facts.cost_per_kwh'))->toBe('5.3500');
});

it('sends a deterministic request to the model', function (): void {
    $user = User::factory()->create();
    assistantSession($user);

    Http::fake(['*' => Http::response(ollamaResponse('{"period":"this_month","dimension":"none","intent":"summary"}'))]);

    $this->actingAs($user)->postJson('/api/v1/assistant/ask', ['question' => 'test'])->assertOk();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'http://ollama.test/v1/chat/completions'
            && $request['model'] === 'llama3.1:8b'
            // The same question must give the same answer.
            && $request['temperature'] === 0.0;
    });
});

it('rejects an empty question', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/assistant/ask', ['question' => ''])
        ->assertStatus(422);
});

it('requires authentication', function (): void {
    $this->postJson('/api/v1/assistant/ask', ['question' => 'How much did I spend?'])
        ->assertStatus(401);
});

it('produces a breakdown when the question asks for one', function (): void {
    $user = User::factory()->create();
    assistantSession($user, ['charging_type' => 'HOME']);
    assistantSession($user, ['charging_type' => 'PUBLIC']);

    Http::fake([
        '*' => Http::sequence()
            ->push(ollamaResponse('{"period":"this_month","dimension":"charging_type","intent":"breakdown"}'))
            ->push(ollamaResponse('Home and public each came to 214.00.')),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/ask', ['question' => 'Where do I charge most?']);

    expect($response->json('data.facts.breakdown'))->toHaveCount(2)
        ->and($response->json('meta.intent.dimension'))->toBe('charging_type');
});
