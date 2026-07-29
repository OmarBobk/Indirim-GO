<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\RecordSuccessfulLogin;
use App\Actions\Auth\SyncAuthenticatedUserLocale;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function __construct(
        private readonly RecordSuccessfulLogin $recordSuccessfulLogin,
    ) {}

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        if (Auth::check()) {
            $user = Auth::user();
            $this->recordSuccessfulLogin->execute($user);
            app(SyncAuthenticatedUserLocale::class)->execute($request, $user);
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(route('home'));
    }
}
