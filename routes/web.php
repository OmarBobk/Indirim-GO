<?php

use App\Exports\UsersExport;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\BugAttachmentController;
use App\Http\Controllers\BuyNowCustomAmountQuoteController;
use App\Http\Controllers\TopupProofController;
use App\Livewire\Admin\CommissionsTable;
use App\Livewire\Admin\PayoutRequestsTable;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Maatwebsite\Excel\Facades\Excel;

Route::livewire('/404', 'pages::errors.404')->name('404');

Route::get('language/{locale}', function (string $locale) {
    if (! in_array($locale, ['en', 'ar'])) {
        abort(400);
    }

    session()->put('locale', $locale);
    session()->save();

    if (auth()->check()) {
        auth()->user()?->forceFill([
            'locale' => $locale,
            'locale_manually_set' => true,
        ])->save();
    }

    app()->setLocale($locale);

    return redirect()->back();
})->name('language.switch');

// Route::view('/', 'main')
//    ->name('home');

Route::livewire('/', 'pages::frontend.main')->name('home');
Route::livewire('/categories/{category:slug}', 'pages::frontend.category-show')->name('categories.show');
Route::livewire('/contact', 'pages::frontend.contact')->name('contact');
Route::livewire('/cart', 'pages::frontend.cart')->name('cart');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/profile', 'pages::frontend.profile')->name('profile');
    Route::livewire('/profile/edit', 'pages::frontend.profile-edit')->name('profile.edit-information');
    Route::livewire('/wallet', 'pages::frontend.wallet')->name('wallet');
    Route::livewire('/loyalty', 'pages::frontend.loyalty')->name('loyalty');
    Route::livewire('/referral-link', 'pages::frontend.referral-link')
        ->middleware('can:view_referrals')
        ->name('referral-link');
    Route::livewire('/orders', 'pages::frontend.orders')->name('orders.index');
    Route::livewire('/orders/{order:order_number}', 'pages::frontend.order-details')->name('orders.show');
    Route::livewire('/notifications', 'pages::frontend.notifications')->name('notifications.index');
    Route::get('/topup-proofs/{proof}', [TopupProofController::class, 'show'])->name('topup-proofs.show');
    Route::get('/bug-attachments/{attachment}', [BugAttachmentController::class, 'show'])->name('bug-attachments.show');
    Route::post('/api/pricing/buy-now-custom-amount-quote', BuyNowCustomAmountQuoteController::class)
        ->name('api.pricing.buy-now-custom-amount-quote');
});

Route::post('api/admin/push/register-token', [PushTokenController::class, 'register'])
    ->middleware(['auth', 'verified', 'backend'])
    ->name('api.admin.push.register-token');

Route::middleware(['auth', 'verified', 'backend'])->group(function () {
    Route::livewire('/dashboard', 'pages::backend.dashboard')
        ->middleware('can:view_dashboard')
        ->name('dashboard');
    Route::livewire('/salesperson-dashboard', 'pages::backend.salesperson-dashboard')
        ->middleware('can:view_referrals')
        ->name('salesperson.dashboard');
    Route::livewire('/salesperson/users', 'pages::backend.salesperson-users.index')
        ->middleware('can:manage_referred_users')
        ->name('salesperson.users.index');
    Route::livewire('/salesperson/users/{user}', 'pages::backend.users.show')
        ->middleware('can:manage_referred_users')
        ->name('salesperson.users.show');
    Route::livewire('/categories', 'pages::backend.categories.index')->name('categories');
    Route::livewire('/packages', 'pages::backend.packages.index')->name('packages');
    Route::livewire('/products', 'pages::backend.products.index')->name('products');
    Route::livewire('/product-entry-prices', 'pages::backend.product-entry-prices.index')
        ->middleware('can:update_product_prices')
        ->name('product-entry-prices');
    Route::livewire('/pricing-rules', 'pages::backend.pricing-rules.index')->name('pricing-rules');
    Route::livewire('/loyalty-tiers', 'pages::backend.loyalty-tiers.index')->name('loyalty-tiers');
    Route::livewire('/admin/orders', 'pages::backend.orders.index')->name('admin.orders.index');
    Route::livewire('/admin/orders/{order}', 'pages::backend.orders.show')->name('admin.orders.show');
    Route::livewire('/admin/activities', 'pages::backend.activities.index')->name('admin.activities.index');
    Route::livewire('/admin/system-events', 'pages::backend.system-events.index')->name('admin.system-events.index');
    Route::middleware('can:manage_users')->group(function () {
        Route::livewire('/admin/users', 'pages::backend.users.index')->name('admin.users.index');
        Route::get('/admin/users/export', function () {
            abort_unless(auth()->user()?->can('viewAny', User::class), 403);

            return Excel::download(new UsersExport, 'users.xlsx');
        })->name('admin.users.export');
        Route::livewire('/admin/users/{user}', 'pages::backend.users.show')->name('admin.users.show');
        Route::livewire('/admin/users/{user}/audit', 'pages::backend.users.audit')->name('admin.users.audit');
    });
    Route::livewire('/fulfillments', 'pages::backend.fulfillments.index')->name('fulfillments');
    Route::livewire('/refunds', 'pages::backend.refunds.index')->name('refunds');
    Route::livewire('/topups', 'pages::backend.topups.index')->name('topups');
    Route::livewire('/customer-funds', 'pages::backend.customer-funds.index')->name('customer-funds');
    Route::livewire('/settlements', 'pages::backend.settlements.index')->name('settlements');
    Route::livewire('/admin/commissions', CommissionsTable::class)
        ->middleware('can:manage_settlements')
        ->name('admin.commissions');
    Route::livewire('/admin/payout-requests', PayoutRequestsTable::class)
        ->middleware('can:manage_settlements')
        ->name('admin.payout-requests');
    Route::livewire('/admin/notifications', 'pages::backend.notifications.index')->name('admin.notifications.index');
});

Route::middleware(['auth', 'verified', 'backend', 'can:manage_bugs'])->group(function () {
    Route::livewire('/admin/bugs', 'bugs.admin-index')->name('admin.bugs.index');
    Route::livewire('/admin/bugs/{bug}', 'bugs.admin-show')->name('admin.bugs.show');
});

Route::middleware(['auth', 'verified', 'backend', 'admin'])->group(function () {
    Route::livewire('/admin/website-settings', 'pages::backend.website-settings.index')->name('admin.website-settings');
});

require __DIR__.'/settings.php';
