<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerFinancialOverview;
use App\Enums\CreditFacilityStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CustomerFinancialPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('returns prepaid-only balance without credit fields', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update(['balance' => '100.00']);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->balance->availableToSpend)->toBe('100.00')
        ->and($overview->balance->prepaidBalance)->toBe('100.00')
        ->and($overview->balance->outstandingDebt)->toBe('0.00')
        ->and($overview->balance->creditFacilityActive)->toBeFalse()
        ->and($overview->balance->creditLimit)->toBeNull()
        ->and($overview->balance->remainingCredit)->toBeNull();
});

it('includes remaining credit for an active facility', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update([
        'balance' => '100.00',
        'credit_enabled' => true,
        'credit_limit' => '500.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->balance->availableToSpend)->toBe('600.00')
        ->and($overview->balance->prepaidBalance)->toBe('100.00')
        ->and($overview->balance->creditFacilityActive)->toBeTrue()
        ->and($overview->balance->creditLimit)->toBe('500.00')
        ->and($overview->balance->remainingCredit)->toBe('500.00');
});

it('hides credit fields when facility is suspended', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update([
        'balance' => '50.00',
        'credit_enabled' => true,
        'credit_limit' => '200.00',
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->balance->availableToSpend)->toBe('50.00')
        ->and($overview->balance->creditFacilityActive)->toBeFalse()
        ->and($overview->balance->creditLimit)->toBeNull();
});

it('surfaces outstanding debt with remaining credit availability', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update([
        'balance' => '-200.00',
        'credit_enabled' => true,
        'credit_limit' => '500.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->balance->availableToSpend)->toBe('300.00')
        ->and($overview->balance->prepaidBalance)->toBe('0.00')
        ->and($overview->balance->outstandingDebt)->toBe('200.00')
        ->and($overview->balance->hasOutstandingDebt)->toBeTrue()
        ->and($overview->balance->remainingCredit)->toBe('300.00')
        ->and($overview->pendingCounts['needs_customer_action'])->toBeGreaterThanOrEqual(1);
});

it('caps pending summary at three actionable items', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $paymentMethodId = PaymentMethod::query()->where('name', 'Sham Cash')->value('id');

    foreach (range(1, 2) as $i) {
        TopupRequest::query()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount' => 10 * $i,
            'currency' => 'USD',
            'status' => TopupRequestStatus::Pending,
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    TopupRequest::query()->create([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'amount' => 15,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Rejected,
        'payment_method_id' => $paymentMethodId,
        'note' => 'Please resubmit',
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 12,
        'status' => WalletTransaction::STATUS_PENDING,
        'meta' => ['order_number' => 'ORD-1'],
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->pendingItems)->toHaveCount(3)
        ->and($overview->pendingHasMore)->toBeTrue()
        ->and($overview->pendingCounts['pending_topups'])->toBe(2)
        ->and($overview->pendingCounts['pending_refunds'])->toBe(1)
        ->and($overview->canAddFunds)->toBeFalse();
});

it('includes only posted transactions capped at five', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 7) as $i) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Purchase,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $i,
            'status' => WalletTransaction::STATUS_POSTED,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 99,
        'status' => WalletTransaction::STATUS_PENDING,
        'created_at' => now(),
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 5,
        'status' => WalletTransaction::STATUS_REJECTED,
        'created_at' => now(),
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->recentTransactions)->toHaveCount(5);
    foreach ($overview->recentTransactions as $tx) {
        expect($tx->status)->toBe(WalletTransaction::STATUS_POSTED);
    }
});

it('excludes other users wallet topups refunds and transactions', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWallet = Wallet::forUser($owner);
    $otherWallet = Wallet::forUser($other);
    $paymentMethodId = PaymentMethod::query()->where('name', 'Sham Cash')->value('id');

    TopupRequest::query()->create([
        'user_id' => $other->id,
        'wallet_id' => $otherWallet->id,
        'amount' => 50,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
        'payment_method_id' => $paymentMethodId,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $otherWallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 40,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $ownerWallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 12,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($owner);

    expect($overview->pendingCounts['pending_topups'])->toBe(0)
        ->and($overview->recentTransactions)->toHaveCount(1)
        ->and($overview->recentTransactions[0]->amount)->toBe('12.00');
});

it('shows salesperson link only when permitted', function (): void {
    $user = User::factory()->create();
    $permission = Permission::firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    $role = Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);
    expect($overview->showSalespersonLink)->toBeTrue();

    $plain = User::factory()->create();
    expect(app(GetCustomerFinancialOverview::class)->handle($plain)->showSalespersonLink)->toBeFalse();
});

it('maps rejected refunds that require customer action', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 20,
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 20,
        'fee' => 0,
        'total' => 20,
        'status' => OrderStatus::Paid,
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 20,
        'quantity' => 1,
        'line_total' => 20,
        'status' => \App\Enums\OrderItemStatus::Failed,
    ]);
    $fulfillment = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 20,
        'status' => WalletTransaction::STATUS_REJECTED,
        'meta' => [
            'fulfillment_id' => $fulfillment->id,
            'order_number' => $order->order_number,
        ],
    ]);

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);
    $kinds = array_map(fn ($item) => $item->kind, $overview->pendingItems);

    expect($kinds)->toContain('refund_rejected');
});

it('presenter exposes LTR money and no internal metadata', function (): void {
    $user = User::factory()->create(['locale' => 'en']);
    Wallet::forUser($user)->update(['balance' => '25.00']);

    $dto = app(GetCustomerFinancialOverview::class)->handle($user);
    $view = app(CustomerFinancialPresenter::class)->present($dto, $user);
    $encoded = json_encode($view);

    expect($view['balance']['available_to_spend']['dir'])->toBe('ltr')
        ->and($encoded)->not->toContain('ledger_kernel')
        ->and($encoded)->not->toContain('idempotency_key')
        ->and($encoded)->not->toContain('balance_before');
});
