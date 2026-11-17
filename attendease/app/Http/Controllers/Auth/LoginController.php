<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Services\DeviceService;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 60;

    public function create()
    {
        return view('auth.login');
    }

    /** FR-AUTH-06..09 */
    public function store(Request $request, DeviceService $devices)
    {
        $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $email = Str::lower(trim((string) $request->input('email')));
        $key = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $credentials = ['email' => $email, 'password' => $request->input('password')];

        // FR-ADM-07: a deactivated account never gets a session.
        if (! Auth::attemptWhen($credentials, fn (User $user) => $user->isActive(), $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // Say "deactivated" only to someone who knows the password; otherwise stay vague (FR-AUTH-08).
            $user = Auth::getProvider()->retrieveByCredentials(['email' => $email]);
            $deactivated = $user instanceof User && ! $user->isActive() && Auth::getProvider()->validateCredentials($user, $credentials);

            throw ValidationException::withMessages([
                'email' => $deactivated
                    ? 'Your account has been deactivated. Contact the administrator.'
                    : 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();

        if ($devices->touch($user, $request)) {
            $user->notify(new SecurityAlert(SecurityAlert::NEW_SIGN_IN, RequestContext::capture($request)));
        }

        return redirect()->intended($user->isAdmin() ? route('admin.dashboard') : route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
