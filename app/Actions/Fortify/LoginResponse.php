<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\SyncAuthenticatedUserLocale;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        // Update last login timestamp and log once (admin login or user login, not both)
        if (Auth::check()) {
            $user = Auth::user();
            $user->update(['last_login_at' => now()]);

            $properties = [
                'user_id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->getRoleNames()->toArray(),
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at?->format('M d, Y H:i') ?? '—',
                'phone' => $user->phone,
            ];

            if ($user->hasAnyRole(['admin', 'supervisor'])) {
                activity()
                    ->inLog('admin')
                    ->event('admin.login')
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties($properties)
                    ->log('Admin login');
            } else {
                activity()
                    ->inLog('admin')
                    ->event('user.login')
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties($properties)
                    ->log('User login');
            }
        }

        if (Auth::check()) {
            app(SyncAuthenticatedUserLocale::class)->execute($request, Auth::user());
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(route('home'));
    }
}
