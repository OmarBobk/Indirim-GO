<?php

declare(strict_types=1);

use App\Actions\Orders\PayOrderWithWallet;
use App\Actions\Wallets\AdjustWallet;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletAdjustmentKind;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Financial\ReceiptSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
});

it('stores safe receipt snapshot on purchase posting without secrets', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 100]);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 20]);
    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 20,
        'fee' => 0,
        'total' => 20,
        'status' => OrderStatus::PendingPayment,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => 'Receipt Product',
        'unit_price' => 20,
        'quantity' => 1,
        'line_total' => 20,
        'status' => OrderItemStatus::Pending,
    ]);

    app(PayOrderWithWallet::class)->handle($order->fresh(), $wallet->fresh());

    $tx = WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', WalletTransactionType::Purchase)
        ->where('status', WalletTransaction::STATUS_POSTED)
        ->firstOrFail();

    $receipt = ReceiptSnapshot::fromMeta($tx->meta ?? []);

    expect($receipt)->not->toBeNull()
        ->and($receipt['version'] ?? null)->toBe(1)
        ->and($receipt['order_number'] ?? null)->toBe($order->order_number)
        ->and($receipt['product_label'] ?? null)->toContain('Receipt Product')
        ->and(json_encode($tx->meta))->not->toContain('proof')
        ->and(json_encode($tx->meta))->not->toContain('storage/');
});

it('stores customer-safe reason on adjustment posting', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'adjust_wallets', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $user = User::factory()->create();
    Wallet::forUser($user);

    app(AdjustWallet::class)->handle(
        $admin,
        $user,
        '15.00',
        'adj-receipt-'.uniqid(),
        WalletAdjustmentKind::AdminCredit,
        'Customer goodwill credit',
    );

    $tx = WalletTransaction::query()
        ->where('type', WalletTransactionType::Adjustment)
        ->where('status', WalletTransaction::STATUS_POSTED)
        ->latest('id')
        ->firstOrFail();

    expect(ReceiptSnapshot::string($tx->meta ?? [], 'customer_safe_reason'))->toBe('Customer goodwill credit')
        ->and(json_encode(ReceiptSnapshot::fromMeta($tx->meta ?? [])))->not->toContain('ip_address')
        ->and(json_encode(ReceiptSnapshot::fromMeta($tx->meta ?? [])))->not->toContain('actor_id');
});
