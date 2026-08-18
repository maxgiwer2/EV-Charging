<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Session-cookie login for the web UI.
 *
 * The API uses Sanctum tokens; the browser uses a regular session, so the
 * review screens get CSRF protection and no token has to live in JavaScript.
 */
class SessionController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One message for both an unknown email and a wrong password, so
            // the form cannot be used to discover which accounts exist.
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        // Prevents session fixation: the pre-login session id must not remain
        // valid once the session is authenticated.
        $request->session()->regenerate();

        $user = $request->user();
        $this->audit->log(AuditLog::ACTION_LOGIN, $user, null, null, $user->id);

        return redirect()->intended(route('receipts.review.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->audit->log(AuditLog::ACTION_LOGOUT, $user, null, null, $user->id);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('web.login');
    }
}
