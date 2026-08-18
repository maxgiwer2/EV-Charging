<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentication endpoints (docs/07 -> Auth).
 *
 * Token-based via Sanctum so the same API serves the web UI and a future
 * mobile client.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // One generic message and one code for both "no such user" and "wrong
        // password": distinguishing them would confirm which email addresses
        // are registered. Hash::check still runs only when a user exists, so
        // the throttle in AppServiceProvider (5/min per email+IP) is what
        // blunts timing-based enumeration.
        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            return ApiResponse::error(
                ErrorCode::UNAUTHENTICATED,
                'These credentials do not match our records.',
            );
        }

        $token = $user->createToken(
            $request->string('device_name')->value() ?: 'api',
        )->plainTextToken;

        // AT-010: authentication events are auditable. The token value is
        // never logged (docs/10 rule 13).
        $this->audit->log(AuditLog::ACTION_LOGIN, $user, null, null, $user->id);

        return ApiResponse::item([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke only the token that made this request, so signing out on one
        // device does not sign the user out everywhere.
        // Only a real issued token can be revoked. Session-guard requests
        // carry a TransientToken, which has nothing to delete.
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($user !== null) {
            $this->audit->log(AuditLog::ACTION_LOGOUT, $user, null, null, $user->id);
        }

        return ApiResponse::noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::item(new UserResource($request->user()));
    }
}
