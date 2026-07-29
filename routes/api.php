<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(SetApiLocale::class)->group(function (): void {
    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:mobile-login')
        ->name('api.v1.auth.login');

    Route::post('auth/two-factor-challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:mobile-two-factor')
        ->name('api.v1.auth.two-factor-challenge');

    Route::middleware([
        'auth:sanctum',
        'abilities:'.config('mobile_api.token.ability', 'mobile:access'),
        'mobile.account',
    ])->group(function (): void {
        Route::post('auth/logout', LogoutController::class)->name('api.v1.auth.logout');
        Route::get('me', MeController::class)->name('api.v1.me');
    });
});
