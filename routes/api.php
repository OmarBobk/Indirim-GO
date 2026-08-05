<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Catalog\CatalogHomeController;
use App\Http\Controllers\Api\V1\Catalog\PackageIndexController;
use App\Http\Controllers\Api\V1\Catalog\PackageShowController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Orders\OrderIndexController;
use App\Http\Controllers\Api\V1\Orders\OrderShowController;
use App\Http\Controllers\Api\V1\Purchase\CheckoutController;
use App\Http\Controllers\Api\V1\Purchase\CheckoutQuoteController;
use App\Http\Controllers\Api\V1\Purchase\CheckoutStatusController;
use App\Http\Controllers\Api\V1\Wallet\WalletSummaryController;
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

        Route::middleware('throttle:mobile-catalog')->group(function (): void {
            Route::get('catalog/home', CatalogHomeController::class)->name('api.v1.catalog.home');
            Route::get('packages', PackageIndexController::class)->name('api.v1.packages.index');
            Route::get('packages/{package}', PackageShowController::class)
                ->whereNumber('package')
                ->name('api.v1.packages.show');
        });

        Route::middleware('throttle:mobile-purchase-read')->group(function (): void {
            Route::get('wallet/summary', WalletSummaryController::class)->name('api.v1.wallet.summary');
            Route::post('checkout/quote', CheckoutQuoteController::class)->name('api.v1.checkout.quote');
            Route::get('checkout/status', CheckoutStatusController::class)->name('api.v1.checkout.status');
            Route::get('orders', OrderIndexController::class)->name('api.v1.orders.index');
            Route::get('orders/{order_number}', OrderShowController::class)
                ->where('order_number', 'ORD-[A-Za-z0-9\-]+')
                ->name('api.v1.orders.show');
        });

        Route::middleware('throttle:mobile-purchase-write')->group(function (): void {
            Route::post('checkout', CheckoutController::class)->name('api.v1.checkout');
        });
    });
});
