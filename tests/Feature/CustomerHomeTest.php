<?php

declare(strict_types=1);

use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CustomerHomeFrequentlyOrdered;
use App\Support\CustomerHomeRecentOrders;
use App\Support\FrontendMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest home keeps marketing sections and omits customer home', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="guest-home"', false);
    $response->assertSee('data-section="homepage-marquee"', false);
    $response->assertSee('data-section="homepage-promos"', false);
    $response->assertSee('data-section="homepage-section-of-categories"', false);
    $response->assertSee('data-section="homepage-section-of-packages"', false);
    $response->assertDontSee('data-test="customer-home"', false);
    $response->assertDontSee('data-test="customer-home-wallet"', false);
    $response->assertDontSee('data-test="customer-home-frequently-ordered"', false);
});

test('authenticated home renders operational sections without marketing chrome', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="customer-home"', false);
    $response->assertSee('data-test="customer-home-wallet"', false);
    $response->assertSee('data-test="customer-home-frequently-ordered"', false);
    $response->assertSee('data-test="customer-home-search"', false);
    $response->assertSee('data-test="customer-home-recent-orders"', false);
    $response->assertSee('data-test="customer-home-quick-actions"', false);
    $response->assertSee('data-test="customer-home-category-chips"', false);
    $response->assertSee('data-test="customer-home-popular-packages"', false);
    $response->assertSee(__('main.home_frequently_ordered'), false);
    $response->assertDontSee('data-section="homepage-marquee"', false);
    $response->assertDontSee('data-section="homepage-promos"', false);
    $response->assertDontSee('data-section="homepage-section-of-categories"', false);
});

test('authenticated home wallet strip shows available to spend from CustomerWalletDisplay', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '42.50']);

    $available = FrontendMoney::for($user)->format(42.50, 'USD', 2);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="customer-home-wallet"')
        ->assertSeeHtml('data-test="wallet-chrome-summary"')
        ->assertSee(__('messages.wallet_available_to_spend'), false)
        ->assertSee($available, false)
        ->assertSeeHtml('data-event="home-wallet-topup"');
});

test('frequently ordered lists distinct packages ranked by purchase count', function () {
    $user = User::factory()->create();
    $first = Package::factory()->create(['name' => 'Alpha Pack', 'is_active' => true]);
    $second = Package::factory()->create(['name' => 'Beta Pack', 'is_active' => true]);
    $productA = Product::factory()->create(['package_id' => $first->id, 'entry_price' => 10]);
    $productB = Product::factory()->create(['package_id' => $second->id, 'entry_price' => 10]);

    foreach (range(1, 3) as $i) {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => Order::temporaryOrderNumber(),
            'currency' => 'USD',
            'subtotal' => 10,
            'fee' => 0,
            'total' => 10,
            'status' => OrderStatus::Paid,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $productA->id,
            'package_id' => $first->id,
            'name' => $productA->name,
            'unit_price' => 10,
            'quantity' => 1,
            'line_total' => 10,
            'status' => OrderItemStatus::Pending,
        ]);
    }

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $productB->id,
        'package_id' => $second->id,
        'name' => $productB->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Pending,
    ]);

    $items = CustomerHomeFrequentlyOrdered::forUser($user);

    expect(collect($items)->pluck('name')->all())->toBe(['Alpha Pack', 'Beta Pack'])
        ->and($items[0]['times_ordered'])->toBe(3);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Alpha Pack', false)
        ->assertSee('Beta Pack', false)
        ->assertSeeHtml('data-event="home-frequently-ordered-item"')
        ->assertDontSeeHtml('data-test="customer-home-frequently-ordered-empty"');
});

test('recent orders on home are capped at three', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $i) {
        Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-HOME-'.$i,
            'currency' => 'USD',
            'subtotal' => 10,
            'fee' => 0,
            'total' => 10,
            'status' => OrderStatus::Paid,
        ]);
    }

    $rows = CustomerHomeRecentOrders::forUser($user);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->pluck('order_number')->all())->toBe([
            'ORD-HOME-4',
            'ORD-HOME-3',
            'ORD-HOME-2',
        ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('ORD-HOME-4', false)
        ->assertSee('ORD-HOME-3', false)
        ->assertSee('ORD-HOME-2', false)
        ->assertDontSee('ORD-HOME-1', false);
});

test('authenticated home shows category chips instead of category explorer grid', function () {
    $user = User::factory()->create();
    Category::factory()->create([
        'name' => 'Chip Category',
        'slug' => 'chip-category',
        'is_active' => true,
        'parent_id' => null,
        'order' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="customer-home-category-chips"')
        ->assertSeeHtml('data-test="customer-home-category-chip"')
        ->assertSee('Chip Category', false)
        ->assertDontSeeHtml('data-test="homepage-categories-grid"');
});
