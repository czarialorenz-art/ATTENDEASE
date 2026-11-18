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

    /** FR-AUTH-06..09: log in with the ID number or the school email (accounts may have no email). */
    public function store(Request $request, DeviceService $devices)
    {
        // "email" is still accepted from older forms.
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [], ['login' => 'ID number or email']);

        $login = trim((string) $request->input('login'));
        // Anything with an @ is an email (any case); otherwise it's an ID number, exactly as issued.
        $field = str_contains($login, '@') ? 'email' : 'id_number';
        $login = $field === 'email' ? Str::lower($login) : $login;
        $key = Str::lower($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'login' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $credentials = [$field => $login, 'password' => $request->input('password')];

        // FR-ADM-07: a deactivated account never gets a session.
        if (! Auth::attemptWhen($credentials, fn (User $user) => $user->isActive(), $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            // Say "deactivated" only to someone who knows the password; otherwise stay vague (FR-AUTH-08).
            $user = Auth::getProvider()->retrieveByCredentials([$field => $login]);
            $deactivated = $user instanceof User && ! $user->isActive() && Auth::getProvider()->validateCredentials($user, $credentials);

            throw ValidationException::withMessages([
                'login' => $deactivated
                    ? 'Your account has been deactivated. Contact the administrator.'
                    : 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();

        $newDevice = $devices->touch($user, $request);
        if ($newDevice) {
            $user->notify(new SecurityAlert(SecurityAlert::NEW_SIGN_IN, RequestContext::capture($request)));
        }

        // FR-LOG-02: successful sign-ins only (failed attempts aren't logged).
        \App\Support\Audit::log(\App\Support\Audit::LOGGED_IN,
            \App\Support\Audit::who($user).' logged in with their '.($field === 'email' ? 'email' : 'ID number').' · IP '.$request->ip().($newDevice ? ' · new browser' : ''),
            $user, ['ip' => $request->ip()], $user);

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
