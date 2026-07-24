<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * End the session when the account was blocked or deactivated after login.
 */
final class EnsureAccountCanUseSession
{
    /**
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return $next($request);
        }

        $user->refresh();

        if ($user->canLogin()) {
            return $next($request);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $user->isBlocked()
            ? __('messages.session_ended_blocked')
            : __('messages.session_ended_inactive');

        return redirect()->guest(route('login'))->with('status', $message);
    }
}
