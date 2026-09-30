<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard:  ->middleware('permission:employees.manage')
 *               ->middleware('permission:leave.approve,tasks.manage')   (any one is enough)
 *
 * This only answers "may this role use this feature at all?". Which records the
 * user may touch (own department / own team / self) is decided by AccessControl.
 * An unknown permission slug is denied for everyone, so a typo fails closed.
 */
class EnsureHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to perform this action.');
    }
}
