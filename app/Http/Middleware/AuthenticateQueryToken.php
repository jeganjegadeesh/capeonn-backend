<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQueryToken
{
    /**
     * Handle an incoming request.
     * Allows requests with ?token= or ?auth_token= query parameters
     * to authenticate via Sanctum Bearer token headers.
     * This is crucial for:
     * - Browser file downloads in new tabs
     * - Image previews (<img src="...">)
     * - External viewers
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken()) {
            $token = $request->query('token') ?? $request->query('auth_token');
            if ($token && is_string($token)) {
                $request->headers->set('Authorization', 'Bearer ' . trim($token));
            }
        }

        return $next($request);
    }
}
