<?php

use App\Http\Middleware\EnsureHasPermission;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Flutter clients (mobile, web, desktop) all use Bearer tokens,
        // so Sanctum's cookie/SPA mode (statefulApi) is intentionally not enabled.

        // API clients are never redirected to a login page; a missing token
        // should produce a 401 JSON response, not a lookup of route('login').
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'permission' => EnsureHasPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One consistent JSON error shape for every /api/* request:
        // { success: false, message: string, errors: object|null }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null; // let Laravel handle non-API requests normally
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $e->errors(),
                ], 422);
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors'  => null,
                ], 401);
            }

            // Covers 403 (incl. AuthorizationException), 404 (incl. ModelNotFound),
            // 405, 419, 429, etc. Laravel converts those into HTTP exceptions first.
            if ($e instanceof HttpExceptionInterface) {
                $status  = $e->getStatusCode();
                $message = match ($status) {
                    404     => 'Resource not found.',
                    429     => 'Too many requests. Please try again later.',
                    default => $e->getMessage() ?: 'Request failed.',
                };

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors'  => null,
                ], $status, $e->getHeaders());
            }

            // Anything unexpected: never leak internals outside debug mode.
            return response()->json([
                'success' => false,
                'message' => config('app.debug') ? $e->getMessage() : 'Server error.',
                'errors'  => null,
            ], 500);
        });
    })->create();