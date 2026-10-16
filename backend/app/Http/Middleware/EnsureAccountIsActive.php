<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FSD 3.4 account lifecycle: a Suspended user's existing tokens must stop
 * working immediately, not just at next login — so this is checked on
 * every authenticated request, not only at login time.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $user->currentAccessToken()?->delete();
            abort(403, 'This account has been suspended.');
        }

        return $next($request);
    }
}
