<?php

declare(strict_types=1);

use App\Actions\Orders\PayOrderWithWallet;
use App\Actions\Wallets\AdjustWallet;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletAdjustmentKind;
use App\Enums\WalletType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Permission::findOrCreate('adjust_wallets', 'web');
});

/**
 * @return array{0: User, 1: Wallet, 2: Order}
 */
function overdraftCheckoutFixture(string $balance, string $orderTotal, bool $creditEnabled = true, string $creditLimit = '100.00'): array
{
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => $balance,
        'credit_enabled' => $creditEnabled,
        'credit_limit' => $creditLimit,
        'payment_terms_days' => $creditEnabled ? 30 : null,
        'credit_status' => $creditEnabled ? \App\Enums\CreditFacilityStatus::Active : null,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => $orderTotal,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => $orderTotal,
        'fee' => 0,
        'total' => $orderTotal,
        'status' => OrderStatus::PendingPayment,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => $orderTotal,
        'quantity' => 1,
        'line_total' => $orderTotal,
        'status' => OrderItemStatus::Pending,
    ]);

    return [$user, $wallet, $order];
}

test('checkout debit may overdraw within credit limit', function () {
    [, $wallet, $order] = overdraftCheckoutFixture('10.00', '20.00');

    app(PayOrderWithWallet::class)->handle($order, $wallet);

    $wallet->refresh();
    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and((float) $wallet->balance)->toBe(-10.0)
        ->and($wallet->isOverdrawn())->toBeTrue()
        ->and(WalletTransaction::query()->where('reference_id', $order->id)->exists())->toBeTrue();
});

test('checkout debit is rejected beyond credit limit', function () {
    [, $wallet, $order] = overdraftCheckoutFixture('10.00', '120.00', creditLimit: '100.00');

    expect(fn () => app(PayOrderWithWallet::class)->handle($order, $wallet))
        ->toThrow(ValidationException::class);

    $wallet->refresh();
    $order->refresh();

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and((float) $wallet->balance)->toBe(10.0)
        ->and(WalletTransaction::count())->toBe(0);
});

test('checkout debit is rejected when credit is disabled even if limit is set', function () {
    [, $wallet, $order] = overdraftCheckoutFixture('10.00', '20.00', creditEnabled: false, creditLimit: '500.00');

    expect(fn () => app(PayOrderWithWallet::class)->handle($order, $wallet))
        ->toThrow(ValidationException::class);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(10.0)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

test('topup after overdraft clears debt via normal arithmetic', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('adjust_wallets');

    [, $wallet, $order] = overdraftCheckoutFixture('10.00', '50.00', creditLimit: '100.00');

    app(PayOrderWithWallet::class)->handle($order, $wallet);
    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(-40.0);

    app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $wallet->user,
        amount: '100.00',
        idempotencyKey: 'topup-after-overdraft-'.$order->id,
        kind: WalletAdjustmentKind::AdminCredit,
        reason: 'Repay overdraft via credit',
    );

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(60.0)
        ->and($wallet->isOverdrawn())->toBeFalse()
        ->and($wallet->outstandingDebt())->toBe('0.00');
});

test('wallet adjustments still work while overdrawn', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('adjust_wallets');

    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-25.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => \App\Enums\CreditFacilityStatus::Active,
    ]);

    app(AdjustWallet::class)->handle(
        actor: $admin,
        targetUser: $user,
        amount: '10.00',
        idempotencyKey: 'adjust-while-overdrawn',
        kind: WalletAdjustmentKind::AdminCredit,
    );

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(-15.0)
        ->and($wallet->type)->toBe(WalletType::Customer);
});
