<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('issues a token for valid credentials', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);

    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'role']]]);
});

it('records an audit entry on login (AT-010)', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertOk();

    expect(AuditLog::where('action', AuditLog::ACTION_LOGIN)->where('user_id', $user->id)->exists())
        ->toBeTrue();
});

it('never writes the issued token into the audit trail', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);

    $token = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->json('data.token');

    // docs/10 rule 13: tokens must never be logged.
    $logged = AuditLog::where('user_id', $user->id)->get()
        ->map(fn (AuditLog $log): string => json_encode([$log->before_data, $log->after_data]) ?: '')
        ->implode(' ');

    expect($logged)->not->toContain($token);
});

it('rejects a wrong password with a generic message', function (): void {
    $user = User::factory()->create(['password' => 'secret-password']);

    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('gives the same response for an unknown email as for a wrong password', function (): void {
    // Distinguishing the two would let an attacker enumerate registered
    // accounts.
    $user = User::factory()->create(['password' => 'secret-password']);

    $wrongPassword = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $unknownEmail = postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.test',
        'password' => 'wrong-password',
    ]);

    expect($unknownEmail->status())->toBe($wrongPassword->status())
        ->and($unknownEmail->json('error.message'))->toBe($wrongPassword->json('error.message'));
});

it('refuses unauthenticated access to protected endpoints', function (): void {
    getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('returns the authenticated user without exposing the password hash', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

    $response->assertOk()->assertJsonPath('data.email', $user->email);
    expect($response->json('data'))->not->toHaveKey('password');
});
