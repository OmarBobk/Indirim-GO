<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class CaptureReferralFromQuery
{
    /**
     * Last-click attribution: when ?ref= matches a user's referral_code, set (overwrite) cookie.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->query('ref');
        $name = (string) config('referral.cookie_name', 'karman_ref');

        if (! is_string($raw) || trim($raw) === '') {
            return $next($request);
        }

        $code = strtoupper(trim($raw));

        if (strlen($code) > 16) {
            Cookie::queue(Cookie::forget($name, '/'));

            return $next($request);
        }

        if (! User::query()->where('referral_code', $code)->exists()) {
            Cookie::queue(Cookie::forget($name, '/'));

            return $next($request);
        }

        $minutes = (int) config('referral.cookie_ttl_minutes', 30 * 24 * 60);

        Cookie::queue(
            Cookie::make(
                name: $name,
                value: $code,
                minutes: $minutes,
                path: '/',
                domain: null,
                secure: $request->secure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax'
            )
        );

        return $next($request);
    }
}
