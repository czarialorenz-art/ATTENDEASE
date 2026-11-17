<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-ADM-07: an account deactivated while logged in is signed out on its next request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'Your account has been deactivated.');
            }

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated. Contact the administrator.']);
        }

        return $next($request);
    }
}
