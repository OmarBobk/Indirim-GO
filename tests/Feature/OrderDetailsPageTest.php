<?php

use App\Actions\Orders\GetCustomerOrderDetail;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\CustomerOrderDetailPresenter;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

/**
 * @return array{order: Order, item: OrderItem, fulfillment: Fulfillment}
 */
function makeCompletedOrder(User $user, array $payload): array
{
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 25,
        'is_active' => true,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'requirements_payload' => ['id' => '12345'],
        'status' => OrderItemStatus::Fulfilled,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 1,
        'meta' => ['delivered_payload' => $payload],
    ]);

    return [
        'order' => $order,
        'item' => $item,
        'fulfillment' => $fulfillment,
    ];
}

function makeOrderWithItem(User $user, FulfillmentStatus $status): Order
{
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 10,
        'is_active' => true,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
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

test('order details page renders for owner', function () {
    $user = User::factory()->create();
    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('data-test="back-button"', false);
});

test('customer order list and details use the purchased item name', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'ABC-12345']);
    $purchasedName = 'Purchased Snapshot Name';
    $currentProductName = 'Renamed Catalog Product';

    $payload['item']->update(['name' => $purchasedName]);
    Product::query()->findOrFail($payload['item']->product_id)->update([
        'name' => $currentProductName,
    ]);

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee($purchasedName)
        ->assertDontSee($currentProductName);

    $this->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertSee($purchasedName)
        ->assertDontSee($currentProductName);
});

test('order details page is forbidden for other users', function () {
    $owner = User::factory()->create();
    $payload = makeCompletedOrder($owner, ['code' => 'ABC-12345']);

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertForbidden();
});

test('delivered payload renders for order owner', function () {
    $user = User::factory()->create();
    $payload = [
        'code' => 'ABC-12345',
        'pin' => '9876',
        'server' => 'EU',
    ];
    $orderPayload = makeCompletedOrder($user, $payload);

    $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee($payload['code'], false)
        ->assertSee($payload['pin'], false)
        ->assertSee($payload['server']);
});

test('order details hides internal automation payload keys from customer', function () {
    $user = User::factory()->create();
    $orderPayload = makeCompletedOrder($user, [
        'phase' => 'reconcile',
        'automation' => true,
        'checkpoint' => 'reconcile_completed',
        'product_api' => '/Customer/Home/ProductRequest?productId=1999',
        'product_url' => 'https://wasim-store.com/Customer/Home/ProductRequest?productId=1999',
        'screenshots' => [['label' => 'orders_page'], ['label' => 'reconcile_completed']],
        'reconcile_tab' => 'completed',
        'automation_run_uuid' => 'd9891435-78ec-438d-9063-a6a7985016ba',
        'supplier_processing_time' => null,
        'supplier_status' => 'Completed',
        'supplier_order_id' => '20724',
        'supplier_description' => 'عملية التحويل تمت بنجاح / قهوة',
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee('20724')
        ->assertSee('Completed')
        ->assertSee('عملية التحويل تمت بنجاح / قهوة', false)
        ->assertDontSee('reconcile_completed', false)
        ->assertDontSee('d9891435-78ec-438d-9063-a6a7985016ba', false)
        ->assertDontSee('productId=1999', false)
        ->assertDontSee('wasim-store.com', false)
        ->assertDontSee('orders_page', false);
});

test('order details renders image urls found inside delivery details', function () {
    $user = User::factory()->create();
    $imageUrl = 'https://res.echoliveapp.com/fdc725aa1a3247ba9371d4c4a3ea94d7.jpg';
    $orderPayload = makeCompletedOrder($user, [
        'supplier_description' => 'عملية التحويل تمت بنجاح / قهوه / '.$imageUrl,
    ]);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee('عملية التحويل تمت بنجاح / قهوه', false)
        ->assertSee('data-test="delivery-payload-image"', false)
        ->assertSee('src="'.$imageUrl.'"', false)
        ->getContent();

    expect($html)->not->toContain('>'.$imageUrl.'<');
});

test('order details renders image gateway urls without file extensions', function () {
    $user = User::factory()->create();
    $imageUrl = 'http://img.znet.tr/Img.php?key=bzVhSmdCVDRQdmR3YnBlaWJPSGdPUFlXanRTNVBBPT0';
    $orderPayload = makeCompletedOrder($user, [
        'supplier_description' => '20260712015016000002 ✭عاشـ༗҈ــق༗҈ℳ❥.. -- '.$imageUrl,
    ]);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee('20260712015016000002', false)
        ->assertSee('data-test="delivery-payload-image"', false)
        ->assertSee('src="'.$imageUrl.'"', false)
        ->getContent();

    expect($html)->not->toContain('>'.$imageUrl.'<');
});

test('order details still works when details have no image url', function () {
    $user = User::factory()->create();
    $orderPayload = makeCompletedOrder($user, [
        'supplier_description' => 'عملية التحويل تمت بنجاح / قهوه',
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee('عملية التحويل تمت بنجاح / قهوه', false)
        ->assertDontSee('data-test="delivery-payload-image"', false);
});

test('delivered payload section includes overflow-safe classes for long codes', function () {
    $user = User::factory()->create();
    $longCode = str_repeat('a', 200).'Z9X7';
    $orderPayload = makeCompletedOrder($user, ['code' => $longCode]);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('break-all')
        ->and($html)->toContain('min-w-0');
});

test('completed delivery payload fields render independent copy affordances', function () {
    $user = User::factory()->create();
    $orderPayload = makeCompletedOrder($user, [
        'code' => 'COPY-CODE-1',
        'pin' => '4321',
    ]);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $orderPayload['order']->order_number))
        ->assertOk()
        ->assertSee('data-test="delivery-payload-copy-field"', false)
        ->assertSee('data-test="delivery-payload-copy"', false)
        ->assertSee(__('messages.copy'))
        ->assertSee('navigator.clipboard.writeText', false)
        ->getContent();

    expect(substr_count($html, 'data-test="delivery-payload-copy"'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($html, 'x-data="{ copied: false }"'))->toBeGreaterThanOrEqual(2);
});

test('failed units do not render delivery copy controls', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertDontSee('data-test="delivery-payload-copy"', false)
        ->assertDontSee('data-test="delivery-payload-copy-field"', false)
        ->assertDontSee('navigator.clipboard.writeText', false);
});

test('order details copy experience does not add livewire actions', function () {
    $source = file_get_contents(resource_path('views/pages/frontend/⚡order-details.blade.php'));

    preg_match_all('/public function\s+(\w+)\s*\(/', $source, $matches);

    expect($matches[1])->toEqualCanonicalizing([
        'mount',
        'render',
        'retryFulfillment',
        'requestRefund',
        'getViewModelProperty',
    ])
        ->and($source)->not->toContain('function copy')
        ->and($source)->not->toContain('clipboard');
});

test('order details page shows refund actions only for failed items', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Queued);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertDontSee(__('messages.request_refund'))
        ->assertDontSee(__('messages.retry_fulfillment'))
        ->assertDontSee('requestRefund')
        ->assertDontSee('retryFulfillment');

    $failedOrder = makeOrderWithItem($user, FulfillmentStatus::Failed);

    $this->actingAs($user)
        ->get(route('orders.show', $failedOrder->order_number))
        ->assertOk()
        ->assertSee(__('messages.refund'))
        ->assertSee('data-test="fulfillment-request-refund"', false);
});

test('failed units render one recovery section controlled by presenter flags', function () {
    $user = User::factory()->create();
    $failedOrder = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $failedFulfillment = $failedOrder->items()->firstOrFail()->fulfillments()->firstOrFail();

    $failedHtml = $this->actingAs($user)
        ->get(route('orders.show', $failedOrder->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-recovery"', false)
        ->assertSee('data-test="fulfillment-retry"', false)
        ->assertSee('data-test="fulfillment-request-refund"', false)
        ->assertSee('wire:click="retryFulfillment('.$failedFulfillment->id.')"', false)
        ->assertSee('wire:click="requestRefund('.$failedFulfillment->id.')"', false)
        ->getContent();

    expect(substr_count($failedHtml, 'data-test="order-detail-recovery"'))->toBe(1);

    $completed = makeCompletedOrder($user, ['code' => 'NO-RECOVERY']);

    $this->actingAs($user)
        ->get(route('orders.show', $completed['order']->order_number))
        ->assertOk()
        ->assertDontSee('data-test="order-detail-recovery"', false)
        ->assertDontSee('data-test="fulfillment-retry"', false)
        ->assertDontSee('data-test="fulfillment-request-refund"', false)
        ->assertDontSee('retryFulfillment')
        ->assertDontSee('requestRefund');

    $pendingOrder = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $pendingOrder->items->first()->fulfillments->first()->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $pendingOrder->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-recovery"', false)
        ->assertSee(__('messages.order_detail_recovery_no_action'))
        ->assertDontSee('data-test="fulfillment-retry"', false)
        ->assertDontSee('data-test="fulfillment-request-refund"', false);
});

test('order details page shows refund requested state when refund is pending', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $fulfillment = $order->items->first()->fulfillments->first();
    $fulfillment->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertSee(__('messages.refund_requested'))
        ->assertDontSee('data-test="fulfillment-request-refund"', false);
});

test('order details page shows refunded state when refund is posted', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $fulfillment = $order->items->first()->fulfillments->first();
    $fulfillment->update([
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_POSTED,
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertSee(__('messages.refunded'))
        ->assertDontSee('data-test="fulfillment-request-refund"', false);
});

test('order details page composes passive detail workspace components', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'STRUCT-1']);

    $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-attention-strip-placeholder"', false)
        ->assertSee('data-test="back-button"', false)
        ->assertSee($payload['order']->order_number)
        ->assertSee('STRUCT-1', false);
});

test('order details delivery workspace renders header attention units line-context then summary', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'HIER-1']);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->getContent();

    $header = strpos($html, 'data-section="order-detail-header"');
    $attention = strpos($html, 'data-section="order-detail-attention-strip"');
    $units = strpos($html, 'data-section="order-detail-units"');
    $lineContext = strpos($html, 'data-section="order-detail-line-context"');
    $summary = strpos($html, 'data-section="order-detail-summary"');

    expect($header)->not->toBeFalse()
        ->and($attention)->not->toBeFalse()
        ->and($units)->not->toBeFalse()
        ->and($lineContext)->not->toBeFalse()
        ->and($summary)->not->toBeFalse()
        ->and($header)->toBeLessThan($attention)
        ->and($attention)->toBeLessThan($units)
        ->and($units)->toBeLessThan($lineContext)
        ->and($lineContext)->toBeLessThan($summary);
});

test('order details attention strip appears when classification needs attention', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);

    $this->actingAs($user)
        ->get(route('orders.show', $order->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-attention-strip"', false)
        ->assertDontSee('data-test="order-detail-attention-strip-placeholder"', false)
        ->assertSee(__('messages.orders_needs_attention_section'));
});

test('get customer order detail enforces ownership and loads the detail read model', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $payload = makeCompletedOrder($owner, ['code' => 'OWN-1']);

    $loaded = app(GetCustomerOrderDetail::class)->handle($payload['order'], $owner->id);

    expect($loaded->relationLoaded('items'))->toBeTrue()
        ->and($loaded->items->first()->relationLoaded('fulfillments'))->toBeTrue()
        ->and($loaded->items->first()->relationLoaded('package'))->toBeTrue()
        ->and($loaded->items->first()->relationLoaded('product'))->toBeTrue()
        ->and($loaded->getAttribute(CustomerOrderFulfillmentClassifier::ATTRIBUTE))->toBeString();

    expect(fn () => app(GetCustomerOrderDetail::class)->handle($payload['order'], $other->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('customer order detail presenter maps without querying', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'MAP-1']);
    $order = app(GetCustomerOrderDetail::class)->handle($payload['order'], $user->id);
    $presenter = CustomerOrderDetailPresenter::for($user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $view = $presenter->present($order);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty()
        ->and($view['items'][0]['name'])->toBe($payload['item']->name)
        ->and($view['items'][0]['units'][0]['payloadEntries'][0]['value'] ?? null)->toBe('MAP-1')
        ->and($view['items'][0]['units'][0]['showRefundAction'])->toBeFalse()
        ->and($view['items'][0]['units'][0]['timeline'])->toBeArray()
        ->and(collect($view['items'][0]['units'][0]['timeline'])->pluck('key')->all())
        ->toContain('order_placed', 'delivery_started', 'delivery_completed');
});

test('order details retry action still queues failed fulfillments', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $fulfillment = $order->items()->firstOrFail()->fulfillments()->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::frontend.order-details', ['order' => $order])
        ->call('retryFulfillment', $fulfillment->id)
        ->assertSet('actionMessage', __('messages.fulfillment_marked_queued'));

    expect($fulfillment->fresh()->status)->toBe(FulfillmentStatus::Queued);
});

test('order details refund action still requests refund for failed fulfillments', function () {
    $user = User::factory()->create();
    \App\Models\Wallet::forUser($user);
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $fulfillment = $order->items()->firstOrFail()->fulfillments()->firstOrFail();

    Livewire::actingAs($user)
        ->test('pages::frontend.order-details', ['order' => $order])
        ->call('requestRefund', $fulfillment->id)
        ->assertSet('actionMessage', __('messages.refund_waiting_approval'));

    expect(data_get($fulfillment->fresh()->meta, 'refund.status'))->toBe(WalletTransaction::STATUS_PENDING);
});

test('order again shows when package and product are active and reuses buy now dispatch', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'AGAIN-1']);

    $html = $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-order-again"', false)
        ->assertSee('data-test="order-detail-order-again-button"', false)
        ->assertSee(__('messages.order_again'))
        ->assertSee('open-buy-now', false)
        ->assertSee('productId: '.$payload['item']->product_id, false)
        ->getContent();

    expect($html)->toContain('$dispatch(\'open-buy-now\'')
        ->and($html)->not->toContain('function orderAgain');
});

test('order again hides when package is inactive', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'AGAIN-OFF']);
    $payload['item']->package()->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertDontSee('data-test="order-detail-order-again"', false)
        ->assertDontSee('data-test="order-detail-order-again-button"', false);
});

test('order again hides when product is inactive', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'AGAIN-PROD-OFF']);
    Product::query()->whereKey($payload['item']->product_id)->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertDontSee('data-test="order-detail-order-again"', false);
});

test('order again keeps snapshot name after product rename', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'AGAIN-SNAP']);
    $snapshot = 'Snapshot Order Again Name';
    $payload['item']->update(['name' => $snapshot]);
    Product::query()->whereKey($payload['item']->product_id)->update(['name' => 'Renamed Live Product']);

    $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->order_number))
        ->assertOk()
        ->assertSee($snapshot)
        ->assertDontSee('Renamed Live Product')
        ->assertSee('data-test="order-detail-order-again"', false);
});

test('presenter owns order again eligibility without querying', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'AGAIN-PRESENTER']);
    $order = app(GetCustomerOrderDetail::class)->handle($payload['order'], $user->id);
    $presenter = CustomerOrderDetailPresenter::for($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $view = $presenter->present($order);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBeEmpty()
        ->and($view['items'][0]['showOrderAgain'])->toBeTrue()
        ->and($view['items'][0]['orderAgainPackageId'])->toBe($payload['item']->package_id)
        ->and($view['items'][0]['orderAgainProductId'])->toBe($payload['item']->product_id)
        ->and($view['items'][0]['orderAgainLabel'])->toBe(__('messages.order_again'));

    $order->items->first()->package->is_active = false;
    $hidden = $presenter->present($order);
    expect($hidden['items'][0]['showOrderAgain'])->toBeFalse()
        ->and($hidden['items'][0]['orderAgainPackageId'])->toBeNull()
        ->and($hidden['items'][0]['orderAgainProductId'])->toBeNull();
});

test('order again blade does not compute package eligibility', function () {
    $source = file_get_contents(resource_path('views/components/orders/detail/order-again.blade.php'));

    expect($source)->toContain('open-buy-now')
        ->and($source)->not->toContain('is_active')
        ->and($source)->not->toContain('Package::')
        ->and($source)->not->toContain('Product::')
        ->and($source)->not->toContain('::query');
});

test('order again uses get customer order detail as the only detail query owner', function () {
    $relations = GetCustomerOrderDetail::eagerLoadRelations();

    expect($relations)->toContain('items.fulfillments')
        ->and($relations)->toContain('items.package.requirements')
        ->and(array_key_exists('items.product', $relations))->toBeTrue();
});

test('presenter builds delivered timeline without fabricating payment or processing events', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'TL-DONE']);
    $payload['fulfillment']->update(['completed_at' => now()]);
    $order = app(GetCustomerOrderDetail::class)->handle($payload['order']->fresh(), $user->id);
    $timeline = CustomerOrderDetailPresenter::for($user)->present($order)['items'][0]['units'][0]['timeline'];
    $keys = collect($timeline)->pluck('key')->all();

    expect($keys)->toContain('order_placed', 'delivery_started', 'delivery_completed')
        ->and($keys)->not->toContain('payment_completed')
        ->and($keys)->not->toContain('delivery_processing')
        ->and($keys)->not->toContain('delivery_failed')
        ->and(collect($timeline)->last()['key'])->toBe('delivery_completed')
        ->and(collect($timeline)->last()['current'])->toBeTrue()
        ->and(collect($timeline)->last()['date'])->not->toBeNull();
});

test('presenter builds failed and refund timelines from existing state only', function () {
    $user = User::factory()->create();
    $order = makeOrderWithItem($user, FulfillmentStatus::Failed);
    $fulfillment = $order->items()->firstOrFail()->fulfillments()->firstOrFail();
    $order->update(['paid_at' => now()->subHour()]);
    $fulfillment->update([
        'processed_at' => now()->subMinutes(30),
        'meta' => [
            'refund' => [
                'status' => WalletTransaction::STATUS_PENDING,
                'requested_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ],
    ]);

    $loaded = app(GetCustomerOrderDetail::class)->handle($order->fresh(), $user->id);
    $timeline = CustomerOrderDetailPresenter::for($user)->present($loaded)['items'][0]['units'][0]['timeline'];
    $keys = collect($timeline)->pluck('key')->all();

    expect($keys)->toContain(
        'order_placed',
        'payment_completed',
        'delivery_started',
        'delivery_processing',
        'delivery_failed',
        'refund_requested',
    )
        ->and($keys)->not->toContain('delivery_completed')
        ->and($keys)->not->toContain('refund_completed')
        ->and(collect($timeline)->last()['key'])->toBe('refund_requested')
        ->and(collect($timeline)->firstWhere('key', 'delivery_failed')['date'])->toBeNull();
});

test('order details renders collapsed delivery timeline from presenter events', function () {
    $user = User::factory()->create();
    $payload = makeCompletedOrder($user, ['code' => 'TL-UI']);
    $payload['order']->update(['paid_at' => now()->subDay()]);
    $payload['fulfillment']->update(['completed_at' => now()]);

    $this->actingAs($user)
        ->get(route('orders.show', $payload['order']->fresh()->order_number))
        ->assertOk()
        ->assertSee('data-test="order-detail-timeline"', false)
        ->assertDontSee('data-test="order-detail-timeline-placeholder"', false)
        ->assertSee(__('messages.order_detail_timeline'))
        ->assertSee('data-timeline-key="payment_completed"', false)
        ->assertSee('data-timeline-key="delivery_completed"', false)
        ->assertSee('data-timeline-current="1"', false);
});
