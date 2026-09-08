<?php

declare(strict_types=1);

use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Category;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-read-user|1');
});

/**
 * @param  list<FulfillmentStatus|string>  $unitStatuses
 * @return array{order: Order, item: OrderItem}
 */
function m41SeedOrder(
    User $user,
    array $unitStatuses = [FulfillmentStatus::Queued],
    array $orderAttributes = [],
    int $extraItems = 0,
    string $itemName = 'Alpha Line',
): array {
    $nextPackageOrder = ((int) Package::query()->max('order')) + 1;
    $nextCategoryOrder = ((int) Category::query()->max('order')) + 1;
    $package = Package::factory()->create([
        'is_active' => true,
        'order' => $nextPackageOrder,
        'slug' => 'm41-pkg-'.str_replace('.', '', uniqid('', true)),
        'category_id' => Category::factory()->create([
            'is_active' => true,
            'parent_id' => null,
            'order' => $nextCategoryOrder,
            'slug' => 'm41-cat-'.str_replace('.', '', uniqid('', true)),
        ]),
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
        'amount_mode' => ProductAmountMode::Fixed,
        'name' => 'M41 Product',
    ]);

    $order = Order::query()->create(array_merge([
        'user_id' => $user->id,
        'order_number' => 'ORD-TEST-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT).'-'.uniqid(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ], $orderAttributes));

    // Ensure order_number matches route pattern ORD-[A-Za-z0-9\-]+
    $order->forceFill([
        'order_number' => 'ORD-2026-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
    ])->save();

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $itemName,
        'unit_price' => 10,
        'entry_price' => 5,
        'quantity' => max(1, count($unitStatuses)),
        'amount_mode' => ProductAmountMode::Fixed,
        'line_total' => 10,
        'requirements_payload' => ['id' => 'SECRET_PLAYER_99'],
        'status' => OrderItemStatus::Pending,
        'pricing_meta' => ['margin' => 'HIDDEN_MARGIN'],
    ]);

    foreach ($unitStatuses as $status) {
        Fulfillment::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'provider' => 'browser:secret-provider',
            'status' => $status instanceof FulfillmentStatus ? $status : FulfillmentStatus::from((string) $status),
            'attempts' => 1,
            'last_error' => 'SECRET_LAST_ERROR',
            'claimed_by' => null,
            'meta' => [
                'automation' => ['secret' => 'SECRET_AUTOMATION'],
                'delivered_payload' => ['code' => 'SECRET_DELIVERED'],
            ],
        ]);
    }

    for ($i = 0; $i < $extraItems; $i++) {
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'name' => 'Extra Line '.$i,
            'unit_price' => 1,
            'entry_price' => 0.5,
            'quantity' => 1,
            'amount_mode' => ProductAmountMode::Fixed,
            'line_total' => 1,
            'requirements_payload' => ['id' => 'EXTRA_SECRET'],
            'status' => OrderItemStatus::Pending,
        ]);
    }

    return ['order' => $order->fresh(), 'item' => $item->fresh()];
}

function m41ForbiddenSubstrings(): array
{
    return [
        'SECRET_PLAYER_99',
        'SECRET_LAST_ERROR',
        'SECRET_AUTOMATION',
        'SECRET_DELIVERED',
        'HIDDEN_MARGIN',
        'browser:secret-provider',
        'requirements_payload',
        'entry_price',
        'last_error',
        'claimed_by',
        'fulfillment_provider',
    ];
}

test('owner lists only owned orders with exact contract fields', function (): void {
    $owner = m31Customer();
    $other = m31Customer();
    m41SeedOrder($owner, [FulfillmentStatus::Queued]);
    m41SeedOrder($other, [FulfillmentStatus::Completed]);
    $token = m31Token($owner);

    $response = $this->getJson('/api/v1/orders', m31Headers($token));

    $response->assertOk()
        ->assertJsonPath('meta.pagination.page', 1)
        ->assertJsonPath('meta.pagination.per_page', 20)
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('meta.pagination.last_page', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.payment_status', 'paid')
        ->assertJsonPath('data.0.fulfillment_status', 'queued')
        ->assertJsonPath('data.0.customer_state', 'in_progress')
        ->assertJsonPath('data.0.item_count', 1)
        ->assertJsonPath('data.0.currency', 'USD')
        ->assertJsonPath('data.0.total.amount', '10.00')
        ->assertJsonPath('data.0.total.currency', 'USD')
        ->assertJsonStructure([
            'data' => [[
                'order_number',
                'created_at',
                'paid_at',
                'currency',
                'total' => ['amount', 'currency', 'display' => ['currency', 'formatted']],
                'payment_status',
                'fulfillment_status',
                'customer_state',
                'title',
                'item_count',
            ]],
            'meta' => ['pagination' => ['page', 'per_page', 'total', 'last_page']],
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $json = json_encode($response->json());
    foreach (m41ForbiddenSubstrings() as $needle) {
        expect($json)->not->toContain($needle);
    }
    m31AssertNoSensitiveKeys($response->json('data'), array_merge(m31SensitiveKeys(), [
        'provider',
        'last_error',
        'claimed_by',
        'claimed_at',
        'meta',
    ]));
});

test('empty order history returns empty data with valid pagination', function (): void {
    $user = m31Customer();
    $token = m31Token($user);

    $this->getJson('/api/v1/orders', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.pagination.total', 0)
        ->assertJsonPath('meta.pagination.last_page', 1);
});

test('order list sorts by created_at desc then id desc for identical timestamps', function (): void {
    $user = m31Customer();
    $stamp = now()->subMinute();

    $first = m41SeedOrder($user, [FulfillmentStatus::Completed], [
        'created_at' => $stamp,
        'updated_at' => $stamp,
        'total' => 1,
        'subtotal' => 1,
    ])['order'];
    $second = m41SeedOrder($user, [FulfillmentStatus::Completed], [
        'created_at' => $stamp,
        'updated_at' => $stamp,
        'total' => 2,
        'subtotal' => 2,
    ])['order'];
    $newer = m41SeedOrder($user, [FulfillmentStatus::Completed], [
        'created_at' => $stamp->copy()->addMinute(),
        'updated_at' => $stamp->copy()->addMinute(),
        'total' => 3,
        'subtotal' => 3,
    ])['order'];

    $token = m31Token($user);
    $numbers = collect($this->getJson('/api/v1/orders', m31Headers($token))->json('data'))
        ->pluck('order_number')
        ->all();

    expect($numbers)->toBe([
        $newer->order_number,
        $second->order_number,
        $first->order_number,
    ]);
});

test('pagination defaults max and invalid values', function (): void {
    $user = m31Customer();
    foreach (range(1, 3) as $i) {
        m41SeedOrder($user, [FulfillmentStatus::Queued], [
            'total' => $i,
            'subtotal' => $i,
            'created_at' => now()->subSeconds($i),
        ]);
    }
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?per_page=1&page=2', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.page', 2)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/orders?per_page=50', m31Headers($token))->assertOk();
    $this->getJson('/api/v1/orders?per_page=51', m31Headers($token))->assertStatus(422);
    $this->getJson('/api/v1/orders?page=0', m31Headers($token))->assertStatus(422);
});

test('anonymous missing ability and blocked account cannot list orders', function (): void {
    $this->getJson('/api/v1/orders')->assertUnauthorized();

    $user = m31Customer();
    $badAbility = m31Token($user, 'web:access');
    $this->getJson('/api/v1/orders', m31Headers($badAbility))->assertForbidden();

    $blocked = m31Customer();
    $blockedToken = m31Token($blocked);
    $blocked->forceFill(['blocked_at' => now()])->save();

    $this->getJson('/api/v1/orders', m31Headers($blockedToken))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_blocked');
});

test('inactive account cannot list orders', function (): void {
    $user = m31Customer();
    $token = m31Token($user);
    $user->forceFill(['is_active' => false, 'blocked_at' => null])->save();

    $this->getJson('/api/v1/orders', m31Headers($token))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_inactive');
});

test('owned detail includes additive fulfillment fields and preserves m3 keys', function (): void {
    $user = m31Customer();
    $seed = m41SeedOrder($user, [FulfillmentStatus::Processing, FulfillmentStatus::Queued]);
    $token = m31Token($user);

    $response = $this->getJson('/api/v1/orders/'.$seed['order']->order_number, m31Headers($token));

    $response->assertOk()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.order.payment_status', 'paid')
        ->assertJsonPath('data.order.fulfillment_status', 'processing')
        ->assertJsonPath('data.order.customer_state', 'in_progress')
        ->assertJsonPath('data.order.fulfillment_summary.total', 2)
        ->assertJsonPath('data.order.fulfillment_summary.queued', 1)
        ->assertJsonPath('data.order.fulfillment_summary.processing', 1)
        ->assertJsonPath('data.order.status', 'paid')
        ->assertJsonStructure([
            'data' => [
                'replayed',
                'order' => [
                    'order_number',
                    'status',
                    'payment_status',
                    'currency',
                    'total',
                    'paid_at',
                    'created_at',
                    'fulfillment_status',
                    'customer_state',
                    'fulfillment_summary' => [
                        'total', 'queued', 'processing', 'completed', 'failed', 'cancelled',
                    ],
                    'items',
                ],
            ],
        ]);

    $json = json_encode($response->json());
    foreach (m41ForbiddenSubstrings() as $needle) {
        expect($json)->not->toContain($needle);
    }
});

test('missing and cross-customer detail share identical 404 shape', function (): void {
    $owner = m31Customer();
    $other = m31Customer();
    $seed = m41SeedOrder($owner, [FulfillmentStatus::Completed]);
    $ownerToken = m31Token($owner);
    $otherToken = m31Token($other);

    $missing = $this->getJson('/api/v1/orders/ORD-2026-999999', m31Headers($ownerToken));
    $cross = $this->getJson('/api/v1/orders/'.$seed['order']->order_number, m31Headers($otherToken));

    $missing->assertNotFound()->assertJsonPath('code', 'order_not_found');
    $cross->assertNotFound()->assertJsonPath('code', 'order_not_found');

    expect(array_keys($missing->json()))->toBe(array_keys($cross->json()))
        ->and($missing->json('code'))->toBe($cross->json('code'))
        ->and($missing->status())->toBe($cross->status());
});

test('fulfillment aggregation keeps failed plus unfinished as processing', function (): void {
    $user = m31Customer();
    $seed = m41SeedOrder($user, [FulfillmentStatus::Failed, FulfillmentStatus::Queued]);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders/'.$seed['order']->order_number, m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.order.fulfillment_status', 'processing')
        ->assertJsonPath('data.order.customer_state', 'needs_attention');
});

test('status matrix covers terminal and non-terminal aggregates', function (array $statuses, string $expectedFulfillment, ?string $orderStatus = null): void {
    $user = m31Customer();
    $attrs = [];
    if ($orderStatus !== null) {
        $attrs['status'] = OrderStatus::from($orderStatus);
        if ($orderStatus === 'refunded') {
            $attrs['paid_at'] = now();
        }
    }
    $seed = m41SeedOrder($user, $statuses, $attrs);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders/'.$seed['order']->order_number, m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.order.fulfillment_status', $expectedFulfillment);
})->with([
    'zero rows' => [[], 'pending'],
    'all queued' => [[FulfillmentStatus::Queued, FulfillmentStatus::Queued], 'queued'],
    'any processing' => [[FulfillmentStatus::Processing], 'processing'],
    'queued plus processing' => [[FulfillmentStatus::Queued, FulfillmentStatus::Processing], 'processing'],
    'completed plus queued' => [[FulfillmentStatus::Completed, FulfillmentStatus::Queued], 'processing'],
    'completed plus processing' => [[FulfillmentStatus::Completed, FulfillmentStatus::Processing], 'processing'],
    'failed plus queued' => [[FulfillmentStatus::Failed, FulfillmentStatus::Queued], 'processing'],
    'failed plus processing' => [[FulfillmentStatus::Failed, FulfillmentStatus::Processing], 'processing'],
    'failed plus completed' => [[FulfillmentStatus::Failed, FulfillmentStatus::Completed], 'failed'],
    'all failed' => [[FulfillmentStatus::Failed, FulfillmentStatus::Failed], 'failed'],
    'all completed' => [[FulfillmentStatus::Completed, FulfillmentStatus::Completed], 'completed'],
    'all cancelled' => [[FulfillmentStatus::Cancelled, FulfillmentStatus::Cancelled], 'cancelled'],
    'completed plus cancelled' => [[FulfillmentStatus::Completed, FulfillmentStatus::Cancelled], 'cancelled'],
    'refunded order completed units' => [[FulfillmentStatus::Completed], 'completed', 'refunded'],
]);

test('legacy order processing and fulfilled map payment_status to paid', function (): void {
    $user = m31Customer();
    foreach ([OrderStatus::Processing, OrderStatus::Fulfilled] as $status) {
        $seed = m41SeedOrder($user, [FulfillmentStatus::Queued], ['status' => $status]);
        $token = m31Token($user);
        $this->getJson('/api/v1/orders/'.$seed['order']->order_number, m31Headers($token))
            ->assertOk()
            ->assertJsonPath('data.order.payment_status', 'paid')
            ->assertJsonPath('data.order.status', $status->value);
    }
});

test('checkout success and receipt recovery emit additive fulfillment fields', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'ig-m41-'.bin2hex(random_bytes(8));

    $result = m31CheckoutOnce($user, $token, $product, $package, $key);
    $orderNumber = $result['order_number'];

    $show = $this->getJson('/api/v1/orders/'.$orderNumber, m31Headers($token));
    $show->assertOk()
        ->assertJsonPath('data.order.order_number', $orderNumber)
        ->assertJsonStructure([
            'data' => [
                'order' => [
                    'created_at',
                    'fulfillment_status',
                    'customer_state',
                    'fulfillment_summary',
                ],
            ],
        ]);

    $status = $this->getJson('/api/v1/checkout/status', m31Headers($token, $key));
    $status->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.order.order_number', $orderNumber)
        ->assertJsonStructure([
            'data' => [
                'order' => [
                    'created_at',
                    'fulfillment_status',
                    'customer_state',
                    'fulfillment_summary',
                ],
            ],
        ]);
});

test('list query count stays bounded for multi-unit pages', function (): void {
    $user = m31Customer();
    foreach (range(1, 3) as $i) {
        m41SeedOrder($user, [
            FulfillmentStatus::Queued,
            FulfillmentStatus::Processing,
        ], [
            'created_at' => now()->subSeconds($i),
        ], extraItems: 1);
    }
    $token = m31Token($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/v1/orders?per_page=3', m31Headers($token))->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // count + orders + items + fulfillments (+ incidental auth/permission lookups)
    expect($queries)->toBeLessThanOrEqual(20);
});

test('historical totals remain visible when catalog prices are hidden', function (): void {
    m31Website(['prices_visible' => false]);
    $user = m31Customer();
    m41SeedOrder($user, [FulfillmentStatus::Completed]);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.0.total.amount', '10.00');
});

test('search finds an owned order by full order number', function (): void {
    $user = m31Customer();
    $match = m41SeedOrder($user)['order'];
    m41SeedOrder($user);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.$match->order_number, m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $match->order_number);
});

test('search finds an owned order by partial order number', function (): void {
    $user = m31Customer();
    $match = m41SeedOrder($user)['order'];
    m41SeedOrder($user);
    $token = m31Token($user);
    $partial = substr($match->order_number, -6);

    $this->getJson('/api/v1/orders?q='.$partial, m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $match->order_number);
});

test('search finds an owned order by historical item snapshot name', function (): void {
    $user = m31Customer();
    $match = m41SeedOrder($user, itemName: 'Unique Snapshot Title')['order'];
    m41SeedOrder($user, itemName: 'Unrelated Line');
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.urlencode('Unique Snapshot'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $match->order_number)
        ->assertJsonPath('data.0.title', 'Unique Snapshot Title');
});

test('live package rename does not affect historical snapshot search', function (): void {
    $user = m31Customer();
    $seed = m41SeedOrder($user, itemName: 'Searchable Snapshot');
    Package::query()->whereKey($seed['item']->package_id)->update([
        'name' => 'Renamed Live Package',
    ]);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.urlencode('Searchable Snapshot'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $seed['order']->order_number);

    $this->getJson('/api/v1/orders?q='.urlencode('Renamed Live'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 0)
        ->assertJsonPath('data', []);
});

test('search never returns another customer matching order', function (): void {
    $owner = m31Customer();
    $other = m31Customer();
    $owned = m41SeedOrder($owner, itemName: 'Shared Snapshot Name')['order'];
    m41SeedOrder($other, itemName: 'Shared Snapshot Name');
    $token = m31Token($owner);

    $this->getJson('/api/v1/orders?q='.urlencode('Shared Snapshot'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $owned->order_number);
});

test('search treats percent underscore and backslash as literals', function (): void {
    $user = m31Customer();
    $percent = m41SeedOrder($user, itemName: 'Pack %% Special')['order'];
    $underscore = m41SeedOrder($user, itemName: 'Pack a_b Exact')['order'];
    $backslash = m41SeedOrder($user, itemName: 'Pack path\\safe')['order'];
    m41SeedOrder($user, itemName: 'Unrelated Pack');
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.urlencode('%%'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $percent->order_number);

    $this->getJson('/api/v1/orders?q='.urlencode('a_b'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $underscore->order_number);

    $this->getJson('/api/v1/orders?q='.urlencode('path\\safe'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $backslash->order_number);
});

test('two matching line items do not duplicate the parent order', function (): void {
    $user = m31Customer();
    $seed = m41SeedOrder($user, extraItems: 1, itemName: 'Dup Snapshot Title');
    OrderItem::query()->where('order_id', $seed['order']->id)->update(['name' => 'Dup Snapshot Title']);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.urlencode('Dup Snapshot'), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.order_number', $seed['order']->order_number)
        ->assertJsonPath('data.0.item_count', 2);
});

test('q and customer_state compose without changing sort', function (): void {
    $user = m31Customer();
    $stamp = now()->subMinute();
    $olderMatch = m41SeedOrder($user, [FulfillmentStatus::Completed], [
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], itemName: 'Compose Token')['order'];
    $newerMatch = m41SeedOrder($user, [FulfillmentStatus::Queued], [
        'created_at' => $stamp->copy()->addMinute(),
        'updated_at' => $stamp->copy()->addMinute(),
    ], itemName: 'Compose Token')['order'];
    m41SeedOrder($user, [FulfillmentStatus::Completed], itemName: 'Other Title');
    $token = m31Token($user);

    $delivered = $this->getJson(
        '/api/v1/orders?q='.urlencode('Compose Token').'&customer_state=delivered',
        m31Headers($token),
    );
    $delivered->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.order_number', $olderMatch->order_number)
        ->assertJsonPath('data.0.customer_state', 'delivered');

    $searchOnly = $this->getJson('/api/v1/orders?q='.urlencode('Compose Token'), m31Headers($token));
    $searchOnly->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.order_number', $newerMatch->order_number)
        ->assertJsonPath('data.1.order_number', $olderMatch->order_number);
});

test('each allowed customer_state filter matches the response classification', function (): void {
    $user = m31Customer();
    $needsAttention = m41SeedOrder($user, [FulfillmentStatus::Failed, FulfillmentStatus::Queued])['order'];
    $inProgress = m41SeedOrder($user, [FulfillmentStatus::Queued])['order'];
    $delivered = m41SeedOrder($user, [FulfillmentStatus::Completed])['order'];
    $refunded = m41SeedOrder($user, [FulfillmentStatus::Completed], [
        'status' => OrderStatus::Refunded,
    ])['order'];
    $cancelled = m41SeedOrder($user, [FulfillmentStatus::Cancelled], [
        'status' => OrderStatus::Cancelled,
    ])['order'];
    $token = m31Token($user);

    $unfiltered = $this->getJson('/api/v1/orders', m31Headers($token));
    $unfiltered->assertOk()->assertJsonPath('meta.pagination.total', 5);

    $filters = [
        'needs_attention' => $needsAttention,
        'in_progress' => $inProgress,
        'delivered' => $delivered,
        'refunded' => $refunded,
    ];

    foreach ($filters as $state => $order) {
        $response = $this->getJson('/api/v1/orders?customer_state='.$state, m31Headers($token));
        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.customer_state', $state);
    }

    $allNumbers = collect($unfiltered->json('data'))->pluck('order_number')->all();
    expect($allNumbers)->toContain($cancelled->order_number);
});

test('q of length 1 returns localized 422', function (): void {
    $user = m31Customer();
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q=a', m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('message', __('messages.mobile_api.validation_failed'))
        ->assertJsonValidationErrors(['q']);

    $this->getJson('/api/v1/orders?q=a', array_merge(m31Headers($token), [
        'Accept-Language' => 'ar',
    ]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'البيانات المقدمة غير صالحة.')
        ->assertJsonValidationErrors(['q']);
});

test('q of length 100 is accepted and 101 is rejected', function (): void {
    $user = m31Customer();
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.str_repeat('a', 100), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->getJson('/api/v1/orders?q='.str_repeat('a', 101), m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('message', __('messages.mobile_api.validation_failed'))
        ->assertJsonValidationErrors(['q']);
});

test('whitespace-only q behaves as omitted', function (): void {
    $user = m31Customer();
    m41SeedOrder($user);
    m41SeedOrder($user);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?q='.urlencode('   '), m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonCount(2, 'data');
});

test('invalid customer_state returns localized 422', function (string $value): void {
    $user = m31Customer();
    $token = m31Token($user);

    $this->getJson('/api/v1/orders?customer_state='.$value, m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('message', __('messages.mobile_api.validation_failed'))
        ->assertJsonValidationErrors(['customer_state']);
})->with([
    'other',
    'all',
    'bogus',
]);

test('search pagination metadata and stable ordering remain unchanged', function (): void {
    $user = m31Customer();
    $stamp = now()->subMinute();
    $first = m41SeedOrder($user, [FulfillmentStatus::Queued], [
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], itemName: 'Paged Snapshot')['order'];
    $second = m41SeedOrder($user, [FulfillmentStatus::Queued], [
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ], itemName: 'Paged Snapshot')['order'];
    $newer = m41SeedOrder($user, [FulfillmentStatus::Queued], [
        'created_at' => $stamp->copy()->addMinute(),
        'updated_at' => $stamp->copy()->addMinute(),
    ], itemName: 'Paged Snapshot')['order'];
    $token = m31Token($user);

    $pageTwo = $this->getJson(
        '/api/v1/orders?q='.urlencode('Paged Snapshot').'&per_page=1&page=2',
        m31Headers($token),
    );
    $pageTwo->assertOk()
        ->assertJsonPath('meta.pagination.page', 2)
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.last_page', 3)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.order_number', $second->order_number);

    $numbers = collect(
        $this->getJson('/api/v1/orders?q='.urlencode('Paged Snapshot'), m31Headers($token))->json('data'),
    )->pluck('order_number')->all();

    expect($numbers)->toBe([
        $newer->order_number,
        $second->order_number,
        $first->order_number,
    ]);
});

test('default list without q or customer_state remains backward compatible', function (): void {
    $user = m31Customer();
    m41SeedOrder($user, [FulfillmentStatus::Queued]);
    m41SeedOrder($user, [FulfillmentStatus::Completed]);
    m41SeedOrder($user, [FulfillmentStatus::Failed, FulfillmentStatus::Queued]);
    $token = m31Token($user);

    $this->getJson('/api/v1/orders', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('meta.pagination.page', 1)
        ->assertJsonPath('meta.pagination.per_page', 20)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [[
                'order_number',
                'created_at',
                'paid_at',
                'currency',
                'total' => ['amount', 'currency', 'display' => ['currency', 'formatted']],
                'payment_status',
                'fulfillment_status',
                'customer_state',
                'title',
                'item_count',
            ]],
            'meta' => ['pagination' => ['page', 'per_page', 'total', 'last_page']],
        ]);
});

test('search results exclude existing sensitive-field test values', function (): void {
    $user = m31Customer();
    m41SeedOrder($user, itemName: 'Privacy Snapshot');
    $token = m31Token($user);

    $response = $this->getJson('/api/v1/orders?q='.urlencode('Privacy Snapshot'), m31Headers($token));
    $response->assertOk()->assertJsonCount(1, 'data');

    $json = json_encode($response->json());
    foreach (m41ForbiddenSubstrings() as $needle) {
        expect($json)->not->toContain($needle);
    }
    m31AssertNoSensitiveKeys($response->json('data'), array_merge(m31SensitiveKeys(), [
        'provider',
        'last_error',
        'claimed_by',
        'claimed_at',
        'meta',
    ]));
});
