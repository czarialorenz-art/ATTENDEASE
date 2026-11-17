<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-ACL-03: route guard by role, e.g. ->middleware('role:admin') or 'role:teacher'.
 * Several roles may be listed: 'role:teacher,student'.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user && $user->hasRole(...$roles), 403, match (true) {
            $roles === ['admin'] => 'Only administrators can open this page.',
            $roles === ['teacher'] => 'Only teachers can do this. Ask the administrator if your account should be a Teacher account.',
            $roles === ['student'] => 'Only students can do this.',
            default => 'Your account role cannot open this page.',
        });

        return $next($request);
    }
}
