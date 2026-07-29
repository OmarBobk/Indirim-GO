<?php

namespace App\Actions\Fortify;

use App\Actions\Auth\RecordSuccessfulLogin;
use App\Actions\Auth\SyncAuthenticatedUserLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
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
            ? new JsonResponse('', 204)
            : redirect()->intended(Fortify::redirects('login'));
    }
}
