<?php

use App\Actions\Orders\GetCustomerOrders;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Category;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CustomerOrderCardPresenter;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makeOrderForUser(User $user, FulfillmentStatus $status): Order
{
    $category = Category::factory()->create([
        'order' => ((int) Category::query()->max('order')) + 1,
    ]);
    $package = Package::factory()->create([
        'category_id' => $category->id,
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 40,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 40,
        'fee' => 0,
        'total' => 40,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 40,
        'quantity' => 1,
        'line_total' => 40,
        'status' => $status === FulfillmentStatus::Failed ? OrderItemStatus::Failed : OrderItemStatus::Pending,
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => $status,
        'attempts' => 0,
    ]);

    return $order;
}

function addOrderItemForFulfillmentState(Order $order, ?FulfillmentStatus $status): OrderItem
{
    $sourceItem = $order->items()->firstOrFail();
    $itemStatus = match ($status) {
        FulfillmentStatus::Completed => OrderItemStatus::Fulfilled,
        FulfillmentStatus::Processing => OrderItemStatus::Processing,
        FulfillmentStatus::Failed => OrderItemStatus::Failed,
        default => OrderItemStatus::Pending,
    };

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $sourceItem->product_id,
        'package_id' => $sourceItem->package_id,
        'name' => $sourceItem->name,
        'unit_price' => $sourceItem->unit_price,
        'quantity' => 1,
        'line_total' => $sourceItem->line_total,
        'status' => $itemStatus,
    ]);

    if ($status !== null) {
        Fulfillment::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'provider' => 'manual',
            'status' => $status,
            'attempts' => 0,
        ]);
    }

    return $item;
}

test('orders page uses foundation layout markers and status-first card hierarchy', function () {
    $user = User::factory()->create();
    makeOrderForUser($user, FulfillmentStatus::Queued);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee('data-test="orders-page"', false)
        ->assertSee('data-test="orders-header"', false)
        ->assertSee('data-test="orders-summary-strip-placeholder"', false)
        ->assertSee('data-test="orders-search"', false)
        ->assertSee('data-event="orders-search"', false)
        ->assertSee('data-test="orders-filter-bar"', false)
        ->assertSee('data-event="orders-filter-all"', false)
        ->assertSee('data-event="orders-filter-needs-attention"', false)
        ->assertSee('data-event="orders-filter-in-progress"', false)
        ->assertSee('data-event="orders-filter-delivered"', false)
        ->assertSee('data-event="orders-filter-refunded"', false)
        ->assertSee('data-test="orders-list"', false)
        ->assertSee('data-test="order-card-status"', false)
        ->assertSee('data-test="order-card-lines"', false)
        ->assertSee('data-test="order-card-meta"', false)
        ->assertSee('data-test="order-card-total"', false)
        ->assertSee('data-test="order-card-actions"', false);
});

test('orders filters use existing order and fulfillment states', function () {
    $user = User::factory()->create();

    $attentionOrder = makeOrderForUser($user, FulfillmentStatus::Failed);

    $paymentPendingOrder = makeOrderForUser($user, FulfillmentStatus::Queued);
    $paymentPendingOrder->update(['status' => OrderStatus::PendingPayment]);

    $inProgressOrder = makeOrderForUser($user, FulfillmentStatus::Queued);

    $deliveredOrder = makeOrderForUser($user, FulfillmentStatus::Completed);

    $refundedOrder = makeOrderForUser($user, FulfillmentStatus::Failed);
    $refundedOrder->update(['status' => OrderStatus::Refunded]);
    $refundedOrder->items()->first()->fulfillments()->first()->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_POSTED,
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'needs_attention')
        ->assertSet('filter', 'needs_attention')
        ->assertSee($attentionOrder->order_number)
        ->assertSee($paymentPendingOrder->order_number)
        ->assertDontSee($inProgressOrder->order_number)
        ->assertDontSee($deliveredOrder->order_number)
        ->assertDontSee($refundedOrder->order_number);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'in_progress')
        ->assertSee($inProgressOrder->order_number)
        ->assertDontSee($attentionOrder->order_number)
        ->assertDontSee($paymentPendingOrder->order_number)
        ->assertDontSee($deliveredOrder->order_number)
        ->assertDontSee($refundedOrder->order_number);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'delivered')
        ->assertSee($deliveredOrder->order_number)
        ->assertDontSee($attentionOrder->order_number)
        ->assertDontSee($inProgressOrder->order_number)
        ->assertDontSee($refundedOrder->order_number);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'refunded')
        ->assertSee($refundedOrder->order_number)
        ->assertDontSee($attentionOrder->order_number)
        ->assertDontSee($inProgressOrder->order_number)
        ->assertDontSee($deliveredOrder->order_number);
});

test('all orders pins attention ahead of newer chronological orders', function () {
    $user = User::factory()->create();

    $attentionOrder = makeOrderForUser($user, FulfillmentStatus::Failed);
    $attentionOrder->update(['created_at' => now()->subDay()]);

    $newerOrder = makeOrderForUser($user, FulfillmentStatus::Queued);
    $newerOrder->update(['created_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->assertSee('data-test="orders-attention-section"', false)
        ->assertSeeInOrder([
            $attentionOrder->order_number,
            __('messages.orders_recent_section'),
            $newerOrder->order_number,
        ]);
});

test('all orders hides attention section when no order needs action', function () {
    $user = User::factory()->create();
    makeOrderForUser($user, FulfillmentStatus::Queued);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->assertDontSee('data-test="orders-attention-section"', false);
});

test('pending refunds stay in progress and do not appear as actionable', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Failed);
    $order->items()->first()->fulfillments()->first()->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->assertDontSee('data-test="orders-attention-section"', false)
        ->call('setFilter', 'needs_attention')
        ->assertDontSee($order->order_number)
        ->call('setFilter', 'in_progress')
        ->assertSee($order->order_number);
});

test('partially fulfilled orders with a missing fulfillment stay in progress', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Completed);
    addOrderItemForFulfillmentState($order, null);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'in_progress')
        ->assertSee($order->order_number)
        ->call('setFilter', 'delivered')
        ->assertDontSee($order->order_number);
});

test('cancelled fulfillments use the in progress fallback classification', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Cancelled);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'in_progress')
        ->assertSee($order->order_number)
        ->call('setFilter', 'delivered')
        ->assertDontSee($order->order_number)
        ->call('setFilter', 'needs_attention')
        ->assertDontSee($order->order_number);
});

test('mixed completed and actionable failed fulfillments need attention', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Completed);
    addOrderItemForFulfillmentState($order, FulfillmentStatus::Failed);
    addOrderItemForFulfillmentState($order, FulfillmentStatus::Queued);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'needs_attention')
        ->assertSee($order->order_number)
        ->call('setFilter', 'in_progress')
        ->assertDontSee($order->order_number)
        ->call('setFilter', 'delivered')
        ->assertDontSee($order->order_number);
});

test('mixed completed and failed fulfillments with a pending refund stay in progress', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Completed);
    $failedItem = addOrderItemForFulfillmentState($order, FulfillmentStatus::Failed);
    addOrderItemForFulfillmentState($order, FulfillmentStatus::Queued);
    $failedItem->fulfillments()->firstOrFail()->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'in_progress')
        ->assertSee($order->order_number)
        ->call('setFilter', 'needs_attention')
        ->assertDontSee($order->order_number)
        ->call('setFilter', 'delivered')
        ->assertDontSee($order->order_number);
});

test('missing fulfillments do not hide an actionable failed fulfillment', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Failed);
    addOrderItemForFulfillmentState($order, null);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'needs_attention')
        ->assertSee($order->order_number)
        ->assertSee(__('messages.fulfillment_status_failed'))
        ->call('setFilter', 'in_progress')
        ->assertDontSee($order->order_number);
});

test('mixed posted failure and queued fulfillment stays in progress', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Completed);
    $failedItem = addOrderItemForFulfillmentState($order, FulfillmentStatus::Failed);
    $failedItem->fulfillments()->firstOrFail()->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_POSTED,
            ],
        ],
    ]);
    addOrderItemForFulfillmentState($order, FulfillmentStatus::Queued);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', 'in_progress')
        ->assertSee($order->order_number)
        ->call('setFilter', 'needs_attention')
        ->assertDontSee($order->order_number)
        ->call('setFilter', 'delivered')
        ->assertDontSee($order->order_number);
});

test('each orders filter has a specific empty state', function (string $filter, string $messageKey) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setFilter', $filter)
        ->assertSee(__($messageKey))
        ->assertDontSee(__('messages.no_orders'));
})->with([
    'needs attention' => ['needs_attention', 'messages.orders_empty_needs_attention'],
    'in progress' => ['in_progress', 'messages.orders_empty_in_progress'],
    'delivered' => ['delivered', 'messages.orders_empty_delivered'],
    'refunded' => ['refunded', 'messages.orders_empty_refunded'],
]);

test('changing orders filter resets pagination', function () {
    $user = User::factory()->create();
    makeOrderForUser($user, FulfillmentStatus::Queued);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setPage', 2)
        ->call('setFilter', 'in_progress')
        ->assertSet('paginators.page', 1);
});

test('orders search finds owned orders by order number and snapshot item name', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $matchingOrder = makeOrderForUser($user, FulfillmentStatus::Queued);
    $matchingOrder->items()->firstOrFail()->update(['name' => 'Legacy Gold Pack']);

    $otherOwnedOrder = makeOrderForUser($user, FulfillmentStatus::Queued);
    $otherOwnedOrder->items()->firstOrFail()->update(['name' => 'Silver Pack']);

    $foreignOrder = makeOrderForUser($otherUser, FulfillmentStatus::Queued);
    $foreignOrder->items()->firstOrFail()->update(['name' => 'Legacy Gold Pack']);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->set('search', substr($matchingOrder->order_number, -6))
        ->assertSee($matchingOrder->order_number)
        ->assertDontSee($otherOwnedOrder->order_number)
        ->assertDontSee($foreignOrder->order_number)
        ->set('search', 'Legacy Gold')
        ->assertSee($matchingOrder->order_number)
        ->assertDontSee($otherOwnedOrder->order_number)
        ->assertDontSee($foreignOrder->order_number);
});

test('orders search uses immutable item names not renamed products', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Queued);
    $item = $order->items()->firstOrFail();
    $item->update(['name' => 'Purchased Snapshot Name']);
    Product::query()->findOrFail($item->product_id)->update([
        'name' => 'Renamed Catalog Product',
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->set('search', 'Purchased Snapshot')
        ->assertSee($order->order_number)
        ->set('search', 'Renamed Catalog')
        ->assertDontSee($order->order_number)
        ->assertSee(__('messages.orders_empty_no_matches'));
});

test('orders search composes with filters and pagination reset', function () {
    $user = User::factory()->create();

    $queued = makeOrderForUser($user, FulfillmentStatus::Queued);
    $queued->items()->firstOrFail()->update(['name' => 'Shared Search Pack']);

    $failed = makeOrderForUser($user, FulfillmentStatus::Failed);
    $failed->items()->firstOrFail()->update(['name' => 'Shared Search Pack']);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('setPage', 2)
        ->set('search', 'Shared Search')
        ->assertSet('paginators.page', 1)
        ->assertSee($queued->order_number)
        ->assertSee($failed->order_number)
        ->call('setFilter', 'needs_attention')
        ->assertSee($failed->order_number)
        ->assertDontSee($queued->order_number);
});

test('orders search persists in the query string and shows no-match empty state', function () {
    $user = User::factory()->create();
    makeOrderForUser($user, FulfillmentStatus::Queued);

    Livewire::withQueryParams(['search' => 'missing-order-xyz'])
        ->actingAs($user)
        ->test('pages::frontend.orders')
        ->assertSet('search', 'missing-order-xyz')
        ->assertSee(__('messages.orders_empty_no_matches'))
        ->assertSee(__('messages.orders_empty_no_matches_hint'))
        ->assertDontSee(__('messages.no_orders'));
});

test('user sees only their orders', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $order = makeOrderForUser($user, FulfillmentStatus::Queued);
    $otherOrder = makeOrderForUser($otherUser, FulfillmentStatus::Queued);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertDontSee($otherOrder->order_number)
        ->assertDontSee(__('messages.request_refund'))
        ->assertSee(__('messages.order_status_paid'), false)
        ->assertSee(__('messages.view_order'), false)
        ->assertSee(route('orders.show', $order->order_number), false);
});

test('orders list shows purchased amount for custom amount line items', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 0.01,
        'amount_mode' => ProductAmountMode::Custom,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 66.90,
        'fee' => 0,
        'total' => 66.90,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 0.01,
        'quantity' => 1,
        'amount_mode' => ProductAmountMode::Custom,
        'requested_amount' => 6_690,
        'amount_unit_label' => 'Crystal',
        'line_total' => 66.90,
        'status' => OrderItemStatus::Pending,
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee(__('messages.order_item_purchased_amount'), false)
        ->assertSee(number_format(6_690), false);
});

test('orders list shows refund status badge when fulfillment failed', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Failed);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee(__('messages.refund'))
        ->assertSee('data-test="order-card-request-refund"', false);

    $fulfillment = $order->items()->first()->fulfillments()->first();
    $fulfillment->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee(__('messages.refund_requested'));

    $fulfillment->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_POSTED,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee(__('messages.refunded'));
});

test('orders list refund button requests refund for failed fulfillment', function () {
    $user = User::factory()->create();
    Wallet::forUser($user);
    $order = makeOrderForUser($user, FulfillmentStatus::Failed);

    Livewire::actingAs($user)
        ->test('pages::frontend.orders')
        ->call('requestRefundForOrder', $order->id);

    $fulfillment = $order->items()->first()->fulfillments()->first();

    expect(data_get($fulfillment->fresh()->meta, 'refund.status'))->toBe(WalletTransaction::STATUS_PENDING);
});

test('orders list shows package requirement labels for requirement keys', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'Player display name',
        'type' => 'string',
        'is_required' => true,
        'order' => 1,
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 40,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 40,
        'fee' => 0,
        'total' => 40,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 40,
        'quantity' => 1,
        'line_total' => 40,
        'status' => OrderItemStatus::Pending,
        'requirements_payload' => ['id' => 'abc-123'],
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    $this->actingAs($user)
        ->get('/orders')
        ->assertOk()
        ->assertSee('Player display name:', false)
        ->assertSee('abc-123', false);
});

test('orders list hydrates only the card read model', function () {
    $user = User::factory()->create();
    $order = makeOrderForUser($user, FulfillmentStatus::Failed);
    $item = $order->items()->firstOrFail();
    $item->fulfillments()->firstOrFail()->update([
        'meta' => [
            'refund' => ['status' => WalletTransaction::STATUS_POSTED],
            'provider_response' => ['unused_by_list' => str_repeat('x', 100)],
        ],
    ]);
    PackageRequirement::factory()->create([
        'package_id' => $item->package_id,
        'key' => 'id',
        'label' => 'Player display name',
        'type' => 'string',
        'is_required' => true,
        'order' => 1,
    ]);
    PackageRequirement::factory()->create([
        'package_id' => $item->package_id,
        'key' => 'email',
        'label' => 'Account email',
        'type' => 'string',
        'is_required' => true,
        'order' => 2,
    ]);
    $item->update(['requirements_payload' => ['id' => 'abc-123']]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $orders = app(GetCustomerOrders::class)->handle(
        $user->id,
        CustomerOrderFulfillmentClassifier::ALL,
        10,
    );

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $listedOrder = $orders->items()[0];
    $listedItem = $listedOrder->items->firstOrFail();
    $listedFulfillment = $listedItem->fulfillments->firstOrFail();

    expect($queries)->toHaveCount(6)
        ->and($queries->contains(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'products')
        ))->toBeFalse()
        ->and(array_keys($listedOrder->getAttributes()))->toEqualCanonicalizing([
            'id',
            'user_id',
            'order_number',
            'currency',
            'total',
            'status',
            'created_at',
            CustomerOrderFulfillmentClassifier::ATTRIBUTE,
        ])
        ->and(array_keys($listedItem->getAttributes()))->toEqualCanonicalizing([
            'id',
            'order_id',
            'package_id',
            'name',
            'unit_price',
            'quantity',
            'amount_mode',
            'requested_amount',
            'amount_unit_label',
            'line_total',
            'requirements_payload',
        ])
        ->and($listedItem->relationLoaded('product'))->toBeFalse()
        ->and(array_keys($listedFulfillment->getAttributes()))->toEqualCanonicalizing([
            'id',
            'order_item_id',
            'status',
            'refund_status',
        ])
        ->and($listedFulfillment->getAttribute('refund_status'))->toBe(WalletTransaction::STATUS_POSTED)
        ->and($listedItem->package->requirements)->toHaveCount(1)
        ->and($listedItem->package->requirements->first()->key)->toBe('id');

    $card = CustomerOrderCardPresenter::for($user)->present($listedOrder);

    expect($card['lines'][0]['title'])->toBe($item->name)
        ->and($card['lines'][0]['meta'])->toContain('Player display name: abc-123')
        ->and($card['refundSummary'])->toMatchArray([
            'kind' => 'badge',
            'color' => 'green',
        ]);
});

test('order details shows package requirement labels for requirement keys', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'Account UID',
        'type' => 'string',
        'is_required' => true,
        'order' => 1,
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 40,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 40,
        'fee' => 0,
        'total' => 40,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 40,
        'quantity' => 1,
        'line_total' => 40,
        'status' => OrderItemStatus::Pending,
        'requirements_payload' => ['id' => 'uid-999'],
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertSee('Account UID', false)
        ->assertSee('uid-999', false);
});
