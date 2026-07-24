<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            require base_path('routes/automation.php');
            if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
                require base_path('routes/ai.php');
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\CaptureReferralFromQuery::class,
            \App\Http\Middleware\EnsureAccountCanUseSession::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'internal/automation/*',
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'backend' => \App\Http\Middleware\EnsureBackendAccess::class,
            'automation.signature' => \App\Http\Middleware\VerifyFulfillmentAutomationSignature::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
