<?php

use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\DismissStaleRefundRequest;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
});

/**
 * @return array{user: User, admin: User, fulfillment: Fulfillment, refund: WalletTransaction}
 */
function makeStaleRefundFixture(): array
{
    $user = User::factory()->create();
    Wallet::forUser($user);
    $admin = User::factory()->create();

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 30]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 30,
        'fee' => 0,
        'total' => 30,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 30,
        'quantity' => 1,
        'line_total' => 30,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
        'last_error' => 'Timeout',
    ]);

    $refund = (new RefundOrderItem)->handle($fulfillment, $user->id);
    $fulfillment->update(['status' => FulfillmentStatus::Completed]);

    return compact('user', 'admin', 'fulfillment', 'refund');
}

test('admin can dismiss stale refund when fulfillment is not failed', function () {
    $fixture = makeStaleRefundFixture();

    $result = app(DismissStaleRefundRequest::class)->handle($fixture['refund']->id, $fixture['admin']->id);

    expect($result->status)->toBe(WalletTransaction::STATUS_REJECTED)
        ->and(data_get($result->meta, 'dismiss_reason'))->toBe('stale_refund');
});

test('dismiss stale refund rejects when fulfillment is still failed', function () {
    $fixture = makeStaleRefundFixture();
    $fixture['fulfillment']->update(['status' => FulfillmentStatus::Failed]);

    app(DismissStaleRefundRequest::class)->handle($fixture['refund']->id, $fixture['admin']->id);
})->throws(ValidationException::class);

test('refunds page shows dismiss action for stale pending refund', function () {
    $fixture = makeStaleRefundFixture();

    foreach (['view_refunds', 'process_refunds'] as $permission) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
    }

    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'refund-admin']);
    $role->syncPermissions(['view_refunds', 'process_refunds']);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->test('pages::backend.refunds.index')
        ->assertSee('data-test="dismiss-stale-refund-'.$fixture['refund']->id.'"', false)
        ->call('dismissStaleRefund', $fixture['refund']->id)
        ->assertHasNoErrors();

    expect($fixture['refund']->fresh()->status)->toBe(WalletTransaction::STATUS_REJECTED);
});
