<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\MobileApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAccountCanAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new MobileApiException(
                'messages.mobile_api.unauthenticated',
                'unauthenticated',
                401,
            );
        }

        if (! $user->currentAccessToken() instanceof PersonalAccessToken) {
            throw new MobileApiException(
                'messages.mobile_api.unauthenticated',
                'unauthenticated',
                401,
            );
        }

        if (! $user->canLogin()) {
            $user->currentAccessToken()?->delete();

            if (! $user->isActive()) {
                throw new MobileApiException(
                    'messages.mobile_api.token_account_inactive',
                    'account_inactive',
                    401,
                );
            }

            throw new MobileApiException(
                'messages.mobile_api.token_account_blocked',
                'account_blocked',
                401,
            );
        }

        if (! $user->hasRole('customer')) {
            $user->currentAccessToken()?->delete();

            throw new MobileApiException(
                'messages.mobile_api.customer_role_required',
                'customer_role_required',
                403,
            );
        }

        return $next($request);
    }
}
