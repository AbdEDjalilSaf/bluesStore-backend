<?php

use App\Http\Middleware\EnsureAdminToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->redirectGuestsTo(fn () => '/');

        $middleware->alias([
            'admin' => EnsureAdminToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (RuntimeException $exception, Request $request) {
            if (
                str_contains($exception->getMessage(), 'APPWRITE_ENDPOINT')
                && str_contains($exception->getMessage(), 'APPWRITE_PROJECT_ID')
                && str_contains($exception->getMessage(), 'APPWRITE_API_KEY')
                && str_contains($exception->getMessage(), 'APPWRITE_DATABASE_ID')
            ) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'message' => 'Service temporarily unavailable.',
                    ], 503);
                }
            }

            return null;
        });
    })->create();
