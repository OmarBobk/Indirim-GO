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
use App\Http\Controllers\Api\V1\Wallet\PaymentMethodIndexController;
use App\Http\Controllers\Api\V1\Wallet\TopupIndexController;
use App\Http\Controllers\Api\V1\Wallet\TopupProofController;
use App\Http\Controllers\Api\V1\Wallet\TopupShowController;
use App\Http\Controllers\Api\V1\Wallet\TopupStatusController;
use App\Http\Controllers\Api\V1\Wallet\TopupStoreController;
use App\Http\Controllers\Api\V1\Wallet\WalletSummaryController;
use App\Http\Controllers\Api\V1\Wallet\WalletTransactionIndexController;
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
            Route::get('wallet/transactions', WalletTransactionIndexController::class)
                ->name('api.v1.wallet.transactions');
            Route::get('wallet/payment-methods', PaymentMethodIndexController::class)
                ->name('api.v1.wallet.payment-methods');
            Route::get('wallet/topups/status', TopupStatusController::class)
                ->name('api.v1.wallet.topups.status');
            Route::get('wallet/topups', TopupIndexController::class)->name('api.v1.wallet.topups.index');
            Route::get('wallet/topups/{public_ref}', TopupShowController::class)
                ->where('public_ref', 'TUP-[A-Za-z0-9]+')
                ->name('api.v1.wallet.topups.show');
            Route::get('wallet/topups/{public_ref}/proof', TopupProofController::class)
                ->where('public_ref', 'TUP-[A-Za-z0-9]+')
                ->name('api.v1.wallet.topups.proof');
            Route::post('checkout/quote', CheckoutQuoteController::class)->name('api.v1.checkout.quote');
            Route::get('checkout/status', CheckoutStatusController::class)->name('api.v1.checkout.status');
            Route::get('orders', OrderIndexController::class)->name('api.v1.orders.index');
            Route::get('orders/{order_number}', OrderShowController::class)
                ->where('order_number', 'ORD-[A-Za-z0-9\-]+')
                ->name('api.v1.orders.show');
        });

        Route::middleware('throttle:mobile-purchase-write')->group(function (): void {
            Route::post('checkout', CheckoutController::class)->name('api.v1.checkout');
            Route::post('wallet/topups', TopupStoreController::class)->name('api.v1.wallet.topups.store');
        });
    });
});
