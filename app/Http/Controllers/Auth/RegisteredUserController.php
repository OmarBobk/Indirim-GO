<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\Actions\GuardRegistrationAttempt;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Http\Controllers\RegisteredUserController as FortifyRegisteredUserController;

/**
 * Fortify registration entrypoint with security checks applied before user creation.
 *
 * CreateNewUser stays free of Turnstile / rate-limit / honeypot concerns.
 * Success budgets are recorded via the Registered event listener.
 */
final class RegisteredUserController extends FortifyRegisteredUserController
{
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        app(GuardRegistrationAttempt::class)($request);

        return parent::store($request, $creator);
    }
}
