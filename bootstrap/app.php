<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\MaterialRequestApiKeyAuth;
use App\Http\Middleware\MaterialRequestClientAuth;
use App\Http\Middleware\MaterialRequestCors;
use App\Services\ShareService;
use App\Support\ApiValidationFailure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Share grant cookies hold a random secret whose hash is checked server-side; keep them readable on stateless API requests.
        $middleware->encryptCookies(except: [ShareService::COOKIE]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'must_change' => EnsurePasswordChanged::class,
            'material.api_key' => MaterialRequestApiKeyAuth::class,
            'material.client' => MaterialRequestClientAuth::class,
            'material.cors' => MaterialRequestCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return app(ApiValidationFailure::class)->response(
                $request,
                $exception->errors(),
                $exception->status,
                $exception->getMessage(),
            );
        });
    })->create();
