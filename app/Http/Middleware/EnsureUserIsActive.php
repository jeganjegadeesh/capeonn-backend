<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cuts off access immediately when an admin deactivates a user, even if their token is still valid. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is deactivated. Please contact your administrator.',
                'errors'  => null,
            ], 403);
        }

        return $next($request);
    }
}
