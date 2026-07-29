<?php

use App\Http\Middleware\EnsureMobileAccountCanAuthenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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
            'abilities' => CheckAbilities::class,
            'mobile.account' => EnsureMobileAccountCanAuthenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return response()->json([
                'message' => __('messages.mobile_api.unauthenticated'),
                'code' => 'unauthenticated',
            ], 401);
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/*')
                || ! ($exception->getPrevious() instanceof MissingAbilityException)) {
                return null;
            }

            return response()->json([
                'message' => __('messages.mobile_api.missing_mobile_ability'),
                'code' => 'missing_mobile_ability',
            ], 403);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            return response()->json([
                'message' => __('messages.mobile_api.too_many_requests'),
                'code' => 'too_many_requests',
            ], 429, $exception->getHeaders());
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/v1/packages', 'api/v1/packages/*')
                && $exception->getMessage() !== 'package_not_found') {
                return null;
            }

            return response()->json([
                'message' => __('messages.mobile_api.package_not_found'),
                'code' => 'package_not_found',
            ], 404);
        });
    })->create();
