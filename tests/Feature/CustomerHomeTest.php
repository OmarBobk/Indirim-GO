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
use App\Support\CustomerHomeFrequentlyOrdered;
use App\Support\CustomerHomeRecentOrders;
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
    $response->assertDontSee('data-test="customer-home-workspace"', false);
    $response->assertDontSee('data-test="customer-home-frequently-ordered"', false);
});

test('authenticated home renders M4.9.2 zones without marketing chrome', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="customer-home"', false);
    $response->assertSee('data-test="customer-home-workspace"', false);
    $response->assertSee('data-test="customer-home-command"', false);
    $response->assertSee('data-test="customer-home-operational-placeholder"', false);
    $response->assertSee('data-test="customer-home-personal"', false);
    $response->assertSee('data-test="customer-home-browse"', false);
    $response->assertSee('data-test="customer-home-catalog"', false);
    $response->assertSee('data-test="customer-home-merch-placeholder"', false);
    $response->assertSee('data-test="customer-home-discover"', false);
    $response->assertSee('data-test="customer-home-search"', false);
    $response->assertSee('data-test="customer-home-frequently-ordered"', false);
    $response->assertSee('data-test="customer-home-category-chips"', false);
    $response->assertSee('data-test="customer-home-popular-packages"', false);
    $response->assertSee(__('main.home_frequently_ordered'), false);
    $response->assertDontSee('data-section="homepage-marquee"', false);
    $response->assertDontSee('data-section="homepage-promos"', false);
    $response->assertDontSee('data-section="homepage-section-of-categories"', false);
});

test('authenticated home hierarchy is command then personal then browse then catalog', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('home'))->assertOk()->getContent();

    $command = strpos($html, 'data-test="customer-home-command"');
    $personal = strpos($html, 'data-test="customer-home-personal"');
    $browse = strpos($html, 'data-test="customer-home-browse"');
    $catalog = strpos($html, 'data-test="customer-home-catalog"');

    expect($command)->not->toBeFalse()
        ->and($personal)->not->toBeFalse()
        ->and($browse)->not->toBeFalse()
        ->and($catalog)->not->toBeFalse()
        ->and($command)->toBeLessThan($personal)
        ->and($personal)->toBeLessThan($browse)
        ->and($browse)->toBeLessThan($catalog);
});

test('authenticated home body omits wallet strip quick actions and recent orders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('data-test="customer-home-wallet"', false)
        ->assertDontSee('data-test="customer-home-quick-actions"', false)
        ->assertDontSee('data-test="customer-home-recent-orders"', false)
        ->assertDontSee('data-event="home-wallet-topup"', false)
        ->assertDontSee('data-event="home-quick-topup"', false)
        ->assertDontSee('data-event="home-recent-orders-all"', false);
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

test('CustomerHomeRecentOrders remains capped at three for future operational use', function () {
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
        ->assertDontSee('ORD-HOME-4', false)
        ->assertDontSee('ORD-HOME-3', false)
        ->assertDontSee('data-test="customer-home-recent-orders"', false);
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
        ->assertSeeHtml('data-test="customer-home-category-scroller"')
        ->assertSee(__('main.home_browse_hint'), false)
        ->assertSee(__('main.home_popular_packages'), false)
        ->assertSee(__('main.home_catalog_shelf_hint'), false)
        ->assertSee('Chip Category', false)
        ->assertDontSeeHtml('data-test="homepage-categories-grid"');
});

test('browse empty state guides toward search and catalog', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="customer-home-categories-empty"')
        ->assertSee(__('main.home_browse_empty_lead'), false)
        ->assertSeeHtml('data-test="customer-home-browse-empty-search"')
        ->assertSeeHtml('data-test="customer-home-browse-empty-packages"')
        ->assertDontSee(__('messages.create_first_category'), false);
});

test('authenticated home is composed from GetCustomerHome and CustomerHomePresenter', function () {
    $user = User::factory()->create();
    Category::factory()->create([
        'name' => 'Read Model Cat',
        'slug' => 'read-model-cat',
        'is_active' => true,
        'parent_id' => null,
        'order' => 1,
    ]);
    Package::factory()->create(['name' => 'Read Model Pack', 'is_active' => true, 'order' => 1]);

    $home = app(\App\Actions\Home\GetCustomerHome::class)->handle($user);
    $view = \App\Support\CustomerHomePresenter::for($user)->present($home);

    expect($view)->toHaveKeys(['command', 'personal', 'browse', 'catalog', 'merch'])
        ->and($view['merch']['visible'])->toBeFalse()
        ->and($view['browse']['categories'])->not->toBeEmpty()
        ->and($view['catalog']['packages'])->not->toBeEmpty();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Read Model Cat', false)
        ->assertSee('Read Model Pack', false)
        ->assertSeeHtml('data-test="customer-home-package-shelf-grid"');
});

test('package and frequently ordered cards expose open affordance', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create(['name' => 'Open Me Pack', 'is_active' => true]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 10]);

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
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Pending,
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('main.home_open'), false)
        ->assertSeeHtml('data-test="home-package-open-affordance"')
        ->assertSee(__('main.home_open_package_aria', ['name' => 'Open Me Pack']), false)
        ->assertSeeHtml('snap-proximity');
});

test('authenticated home command owns a single hero search without shell duplicates', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('main.home_search_placeholder'), false)
        ->assertSeeHtml('id="customer-home-package-search-input"')
        ->assertSeeHtml('data-search-size="hero"')
        ->assertSeeHtml('data-test="customer-home-shopping-lead"')
        ->assertDontSee('data-test="mobile-search-toggle"', false)
        ->assertDontSee('data-test="desktop-header-package-search"', false);
});

test('frequently ordered empty state guides toward shopping', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="customer-home-frequently-ordered-empty"')
        ->assertSee(__('main.home_frequently_ordered_empty_lead'), false)
        ->assertSeeHtml('data-test="customer-home-freq-empty-search"')
        ->assertSeeHtml('data-test="customer-home-freq-empty-categories"')
        ->assertSeeHtml('data-test="customer-home-freq-empty-packages"')
        ->assertSee(__('main.home_frequently_ordered_empty_search'), false)
        ->assertSee(__('main.home_frequently_ordered_empty_categories'), false)
        ->assertSee(__('main.home_frequently_ordered_empty_packages'), false)
        ->assertDontSee(__('main.home_frequently_ordered_empty'), false);
});
