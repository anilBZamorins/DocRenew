<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for the FSD 3.2 permission matrix. Registered as the 'role'
 * middleware alias (bootstrap/app.php) and used per-route, e.g.
 * ->middleware('role:ADMIN,HR').
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $allowed = array_map(
            fn (string $r) => UserRole::from(strtoupper($r)),
            $roles
        );

        if (! $user->hasRole(...$allowed)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
