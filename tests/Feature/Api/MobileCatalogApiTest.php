<?php

declare(strict_types=1);

use App\Enums\LoyaltyTier;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Category;
use App\Models\LoyaltyTierConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPricingRule;
use App\Models\WebsiteSetting;
use App\Services\CustomerPriceService;
use App\Support\FrontendMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

function m21Customer(array $attributes = []): User
{
    $role = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    $user = User::factory()->create(array_merge([
        'password' => Hash::make('password'),
        'preferred_currency' => 'USD',
        'locale' => 'en',
    ], $attributes));
    $user->assignRole($role);

    return $user;
}

function m21Token(User $user, string $ability = 'mobile:access'): string
{
    return $user->createToken('mobile: test', [$ability], now()->addDays(30))->plainTextToken;
}

/**
 * @return array<string, string>
 */
function m21AuthHeaders(string $plainTextToken, string $acceptLanguage = 'en'): array
{
    return [
        'Authorization' => 'Bearer '.$plainTextToken,
        'Accept' => 'application/json',
        'Accept-Language' => $acceptLanguage,
    ];
}

function m21EnsurePricingRule(): void
{
    PricingRule::query()->firstOrCreate(
        [
            'min_price' => 0,
            'max_price' => 999999.99,
            'priority' => 0,
        ],
        [
            'retail_percentage' => 0,
            'wholesale_percentage' => 0,
            'is_active' => true,
        ]
    );
}

function m21EnsureLoyaltyTiers(): void
{
    foreach (['customer', 'salesperson'] as $role) {
        LoyaltyTierConfig::query()->upsert(
            [
                ['role' => $role, 'name' => 'bronze', 'min_spend' => 0, 'discount_percentage' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['role' => $role, 'name' => 'silver', 'min_spend' => 500, 'discount_percentage' => 5, 'created_at' => now(), 'updated_at' => now()],
                ['role' => $role, 'name' => 'gold', 'min_spend' => 2000, 'discount_percentage' => 10, 'created_at' => now(), 'updated_at' => now()],
            ],
            ['role', 'name'],
            ['min_spend', 'discount_percentage', 'updated_at']
        );
    }
}

function m21Website(array $attributes = []): WebsiteSetting
{
    WebsiteSetting::query()->delete();

    return WebsiteSetting::query()->create(array_merge([
        'contact_email' => null,
        'primary_phone' => null,
        'secondary_phone' => null,
        'prices_visible' => true,
        'usd_try_rate' => 40,
        'usd_try_rate_updated_at' => now(),
    ], $attributes));
}

/**
 * @return array{category: Category, package: Package, product: Product}
 */
function m21FixedPackage(array $packageAttributes = [], array $productAttributes = []): array
{
    $category = Category::factory()->create([
        'is_active' => true,
        'parent_id' => null,
        'order' => fake()->unique()->numberBetween(1000, 9999),
        'image' => 'images/categories/demo.webp',
    ]);
    $package = Package::factory()->create(array_merge([
        'category_id' => $category->id,
        'is_active' => true,
        'order' => fake()->unique()->numberBetween(1000, 9999),
        'image' => 'images/packages/demo.webp',
        'name' => 'Catalog Pack '.fake()->unique()->numerify('###'),
        'description' => 'Searchable catalog description',
    ], $packageAttributes));
    $product = Product::factory()->create(array_merge([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
        'amount_mode' => ProductAmountMode::Fixed,
        'order' => fake()->unique()->numberBetween(1000, 9999),
        'name' => 'Fixed Option',
    ], $productAttributes));

    return compact('category', 'package', 'product');
}

/**
 * @param  list<string>  $forbidden
 */
function m21AssertNoSensitiveKeys(mixed $payload, array $forbidden): void
{
    if (! is_array($payload)) {
        return;
    }

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            expect($forbidden)->not->toContain($key);
        }

        m21AssertNoSensitiveKeys($value, $forbidden);
    }
}

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'app.url' => 'http://localhost',
        'filesystems.disks.public.url' => 'http://localhost/storage',
    ]);
    m21EnsurePricingRule();
    m21EnsureLoyaltyTiers();
    m21Website();
});

test('catalog endpoints reject missing auth and web sessions', function (): void {
    $user = m21Customer();
    m21FixedPackage();

    $this->getJson('/api/v1/catalog/home')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');

    // Session auth must not satisfy protected mobile routes (TransientToken).
    $this->actingAs($user)
        ->getJson('/api/v1/catalog/home')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

test('tokens without the mobile ability cannot browse catalog', function (): void {
    $user = m21Customer();
    m21FixedPackage();
    $token = $user->createToken('other-client', ['orders:read'], now()->addDays(30))->plainTextToken;

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages')
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_mobile_ability');
});

test('catalog home returns shelves with featured admin order and frequently ordered scoping', function (): void {
    $user = m21Customer();
    $other = m21Customer(['username' => 'other-customer']);

    $first = m21FixedPackage(['name' => 'Alpha Featured', 'order' => 1]);
    $second = m21FixedPackage(['name' => 'Beta Featured', 'order' => 2]);
    $inactive = m21FixedPackage(['name' => 'Inactive', 'is_active' => false, 'order' => 0]);

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
            'product_id' => $first['product']->id,
            'package_id' => $first['package']->id,
            'name' => $first['product']->name,
            'unit_price' => 10,
            'quantity' => 1,
            'line_total' => 10,
            'status' => OrderItemStatus::Pending,
        ]);
    }

    $otherOrder = Order::create([
        'user_id' => $other->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);
    OrderItem::create([
        'order_id' => $otherOrder->id,
        'product_id' => $second['product']->id,
        'package_id' => $second['package']->id,
        'name' => $second['product']->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Pending,
    ]);

    $pending = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::PendingPayment,
    ]);
    OrderItem::create([
        'order_id' => $pending->id,
        'product_id' => $second['product']->id,
        'package_id' => $second['package']->id,
        'name' => $second['product']->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Pending,
    ]);

    $response = $this->withHeaders(m21AuthHeaders(m21Token($user)))
        ->getJson('/api/v1/catalog/home')
        ->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    expect($response->json('meta.prices_visible'))->toBeTrue()
        ->and($response->json('data.frequently_ordered.0.id'))->toBe($first['package']->id)
        ->and($response->json('data.frequently_ordered.0.times_ordered'))->toBe(3)
        ->and(collect($response->json('data.frequently_ordered'))->pluck('id'))
        ->not->toContain($second['package']->id)
        ->and(collect($response->json('data.featured_packages'))->pluck('name')->all())
        ->toBe(['Alpha Featured', 'Beta Featured'])
        ->and(collect($response->json('data.featured_packages'))->pluck('id'))
        ->not->toContain($inactive['package']->id)
        ->and($response->json('data.categories.0'))->toHaveKeys(['id', 'name', 'slug', 'image_url']);

    m21AssertNoSensitiveKeys($response->json(), [
        'entry_price',
        'retail_price',
        'wholesale_price',
        'fulfillment_provider',
        'package_api',
        'product_api',
        'supplier_scanned_price',
        'uses_user_pricing',
        'is_floor_applied',
        'roles',
        'permissions',
        'balance',
    ]);
});

test('package list filters search paginates and validates category_id exactly', function (): void {
    $user = m21Customer();
    $parent = Category::factory()->create([
        'is_active' => true,
        'parent_id' => null,
        'order' => 10,
        'name' => 'Parent Cat',
    ]);
    $child = Category::factory()->create([
        'is_active' => true,
        'parent_id' => $parent->id,
        'order' => 11,
        'name' => 'Child Cat',
    ]);
    $inactiveCategory = Category::factory()->create([
        'is_active' => false,
        'order' => 12,
    ]);

    $inParent = m21FixedPackage([
        'category_id' => $parent->id,
        'name' => 'Parent Only Pack',
        'order' => 20,
        'description' => 'unique-search-token-alpha',
    ]);
    m21FixedPackage([
        'category_id' => $child->id,
        'name' => 'Child Only Pack',
        'order' => 21,
        'description' => 'unique-search-token-beta',
    ]);
    m21FixedPackage([
        'name' => 'No Products Pack',
        'order' => 22,
        'is_active' => true,
    ], ['is_active' => false]);

    $token = m21Token($user);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?category_id='.$parent->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $inParent['package']->id)
        ->assertJsonPath('meta.pagination.total', 1);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?category_id='.$inactiveCategory->id)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?category_id=999999')
        ->assertStatus(422);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?q=a')
        ->assertStatus(422);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?q=unique-search-token-alpha')
        ->assertOk()
        ->assertJsonPath('data.0.id', $inParent['package']->id)
        ->assertJsonPath('meta.pagination.total', 1);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?per_page=1&page=1')
        ->assertOk()
        ->assertJsonPath('meta.pagination.per_page', 1)
        ->assertJsonPath('meta.pagination.page', 1)
        ->assertJsonPath('meta.pagination.last_page', 2);
});

test('package detail returns active products and 404 for inactive packages', function (): void {
    $user = m21Customer();
    $fixture = m21FixedPackage(['description' => null]);
    Product::factory()->create([
        'package_id' => $fixture['package']->id,
        'is_active' => false,
        'entry_price' => 20,
        'order' => fake()->unique()->numberBetween(10000, 20000),
        'name' => 'Hidden Option',
    ]);
    $inactive = m21FixedPackage(['is_active' => false, 'order' => 30]);

    $token = m21Token($user);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$fixture['package']->id)
        ->assertOk()
        ->assertJsonPath('data.id', $fixture['package']->id)
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.products.0.amount_mode', 'fixed')
        ->assertJsonPath('data.products.0.custom_amount', null)
        ->assertJsonMissingPath('data.products.0.is_available')
        ->assertJsonCount(1, 'data.products');

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$inactive['package']->id)
        ->assertNotFound()
        ->assertJsonPath('code', 'package_not_found')
        ->assertJsonPath('message', 'Package not found.');

    $this->withHeaders(m21AuthHeaders($token, 'ar'))
        ->getJson('/api/v1/packages/999999')
        ->assertNotFound()
        ->assertJsonPath('code', 'package_not_found')
        ->assertJsonPath('message', 'الحزمة غير موجودة.');
});

test('fixed custom and nonlinear from_price use trusted pricing services', function (): void {
    $user = m21Customer(['loyalty_tier' => LoyaltyTier::Gold]);
    $category = Category::factory()->create([
        'is_active' => true,
        'order' => 40,
        'parent_id' => null,
    ]);
    $package = Package::factory()->create([
        'category_id' => $category->id,
        'is_active' => true,
        'order' => 41,
        'image' => 'images/packages/price.webp',
    ]);

    PricingRule::query()->delete();
    PricingRule::query()->create([
        'min_price' => 0,
        'max_price' => 50,
        'retail_percentage' => 0,
        'wholesale_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);
    PricingRule::query()->create([
        'min_price' => 50,
        'max_price' => 999999.99,
        'retail_percentage' => 100,
        'wholesale_percentage' => 0,
        'priority' => 1,
        'is_active' => true,
    ]);

    $fixed = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
        'amount_mode' => ProductAmountMode::Fixed,
        'order' => 1,
        'name' => 'Fixed Ten',
    ]);
    $custom = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 1,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 100,
        'custom_amount_max' => 1000,
        'custom_amount_step' => 100,
        'amount_unit_label' => 'Coins',
        'order' => 2,
        'name' => 'Custom Coins',
    ]);

    $service = app(CustomerPriceService::class);
    $fixedTotal = $service->finalPrice($fixed, $user);
    $customMinTotal = (float) $service->finalPriceForAmount($custom, 100, $user)['final_price'];
    $misleadingUnit = (float) $service->finalPriceForAmount($custom, 1, $user)['final_price'];
    $expectedFrom = min($fixedTotal, $customMinTotal);

    // Amount 1 lands in the low bracket; amount 100 lands in the high bracket (nonlinear).
    expect($customMinTotal)->toBe(180.0)
        ->and($misleadingUnit * 100)->not->toBe($customMinTotal)
        ->and($misleadingUnit)->toBe(1.0);

    $token = m21Token($user);
    $detail = $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$package->id)
        ->assertOk();

    expect($detail->json('data.products.0.unit_price.amount'))->toBe(number_format($fixedTotal, 2, '.', ''))
        ->and($detail->json('data.products.0.minimum_price'))->toBeNull()
        ->and($detail->json('data.products.1.unit_price'))->toBeNull()
        ->and($detail->json('data.products.1.custom_amount'))->toMatchArray([
            'min' => 100,
            'max' => 1000,
            'step' => 100,
            'unit_label' => 'Coins',
        ])
        ->and($detail->json('data.products.1.minimum_price.amount'))->toBe('180.00')
        ->and($detail->json('data.from_price.amount'))->toBe(number_format($expectedFrom, 2, '.', ''))
        ->and($detail->json('data.from_price.currency'))->toBe('USD');

    $list = $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages?q='.$package->name)
        ->assertOk();

    expect($list->json('data.0.from_price.amount'))->toBe(number_format($expectedFrom, 2, '.', ''));
});

test('loyalty user pricing rules floors and prices_visible are honored', function (): void {
    $user = m21Customer(['loyalty_tier' => LoyaltyTier::Gold, 'preferred_currency' => 'TRY']);
    $fixture = m21FixedPackage([], ['entry_price' => 100]);

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 0,
        'wholesale_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $service = app(CustomerPriceService::class);
    $priced = $service->priceFor($fixture['product'], $user);
    expect($priced['meta']['is_floor_applied'])->toBeTrue()
        ->and($priced['final_price'])->toBe(100.0);

    $token = m21Token($user);
    $visible = $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$fixture['package']->id)
        ->assertOk();

    $expectedDisplay = FrontendMoney::for($user)->displayForUsdAmount(100, 2);

    expect($visible->json('data.from_price.amount'))->toBe('100.00')
        ->and($visible->json('data.from_price.currency'))->toBe('USD')
        ->and($visible->json('data.from_price.display'))->toBe($expectedDisplay)
        ->and($visible->json('data.from_price.display.currency'))->toBe('TRY');

    m21Website(['prices_visible' => false]);

    $hidden = $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$fixture['package']->id)
        ->assertOk();

    expect($hidden->json('meta.prices_visible'))->toBeFalse()
        ->and($hidden->json('data.from_price'))->toBeNull()
        ->and($hidden->json('data.products.0.unit_price'))->toBeNull()
        ->and($hidden->json('data.products.0.minimum_price'))->toBeNull();
});

test('image urls are absolute hardened and never svg placeholders', function (): void {
    $user = m21Customer();
    $ok = m21FixedPackage(['image' => 'images/packages/ok.webp']);
    $hostile = m21FixedPackage([
        'image' => '../etc/passwd',
        'order' => 50,
    ]);
    $svg = m21FixedPackage([
        'image' => 'images/icons/category-placeholder.svg',
        'order' => 51,
    ]);
    $scheme = m21FixedPackage([
        'image' => 'https://evil.test/x.png',
        'order' => 52,
    ]);
    $missing = m21FixedPackage([
        'image' => null,
        'order' => 53,
    ]);

    $token = m21Token($user);

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/packages/'.$ok['package']->id)
        ->assertOk()
        ->assertJsonPath('data.image_url', 'http://localhost/images/packages/ok.webp');

    foreach ([$hostile, $svg, $scheme, $missing] as $fixture) {
        $this->withHeaders(m21AuthHeaders($token))
            ->getJson('/api/v1/packages/'.$fixture['package']->id)
            ->assertOk()
            ->assertJsonPath('data.image_url', null);
    }
});

test('catalog rate limiter returns stable 429 envelope', function (): void {
    $user = m21Customer();
    m21FixedPackage();
    $token = m21Token($user);

    for ($i = 0; $i < 60; $i++) {
        $this->withHeaders(m21AuthHeaders($token))
            ->getJson('/api/v1/catalog/home')
            ->assertOk();
    }

    $this->withHeaders(m21AuthHeaders($token))
        ->getJson('/api/v1/catalog/home')
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

test('catalog home and list stay within query budgets', function (): void {
    $user = m21Customer();

    foreach (range(1, 8) as $i) {
        m21FixedPackage([
            'name' => "Budget Pack {$i}",
            'order' => 100 + $i,
        ], [
            'entry_price' => 10 + $i,
            'order' => 200 + $i,
        ]);
    }

    $token = m21Token($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withHeaders(m21AuthHeaders($token))->getJson('/api/v1/catalog/home')->assertOk();
    $homeQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $this->withHeaders(m21AuthHeaders($token))->getJson('/api/v1/packages?per_page=24')->assertOk();
    $listQueries = count(DB::getQueryLog());

    DB::flushQueryLog();
    $packageId = Package::query()->where('is_active', true)->value('id');
    $this->withHeaders(m21AuthHeaders($token))->getJson('/api/v1/packages/'.$packageId)->assertOk();
    $detailQueries = count(DB::getQueryLog());

    // Measured for 8 packages × 1 active product (and detail of one package).
    expect($homeQueries)->toBeLessThanOrEqual(40)
        ->and($listQueries)->toBeLessThanOrEqual(50)
        ->and($detailQueries)->toBeLessThanOrEqual(25);
});

test('blocked accounts lose catalog access with account_blocked', function (): void {
    $user = m21Customer();
    $token = $user->createToken('mobile', ['mobile:access'], now()->addDays(30));
    $user->forceFill(['blocked_at' => now()])->save();
    m21FixedPackage();

    $this->withHeaders(m21AuthHeaders($token->plainTextToken))
        ->getJson('/api/v1/packages')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_blocked');
});

test('inactive accounts lose catalog access with account_inactive', function (): void {
    $user = m21Customer();
    $token = $user->createToken('mobile', ['mobile:access'], now()->addDays(30));
    $user->forceFill(['is_active' => false, 'blocked_at' => null])->save();
    expect($user->fresh()->isActive())->toBeFalse();
    m21FixedPackage();

    $this->withHeaders(m21AuthHeaders($token->plainTextToken))
        ->getJson('/api/v1/packages')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_inactive');
});

test('non customer accounts cannot browse catalog', function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create(['password' => Hash::make('password')]);
    $admin->assignRole('admin');
    $adminToken = $admin->createToken('mobile: test', ['mobile:access'], now()->addDays(30))->plainTextToken;
    m21FixedPackage();

    $this->withHeaders(m21AuthHeaders($adminToken))
        ->getJson('/api/v1/packages')
        ->assertForbidden()
        ->assertJsonPath('code', 'customer_role_required');
});

test('personal access token remains the only accepted auth for catalog', function (): void {
    $user = m21Customer();
    m21FixedPackage();
    $plain = m21Token($user);
    $token = PersonalAccessToken::findToken($plain);
    expect($token)->not->toBeNull();

    $this->withHeaders(m21AuthHeaders($plain))
        ->getJson('/api/v1/me')
        ->assertOk();
});
