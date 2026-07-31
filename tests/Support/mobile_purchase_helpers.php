<?php

declare(strict_types=1);

use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

function m31Customer(array $attributes = []): User
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

function m31Token(User $user, string $ability = 'mobile:access'): string
{
    return $user->createToken('mobile: test', [$ability], now()->addDays(30))->plainTextToken;
}

/**
 * @return array<string, string>
 */
function m31Headers(string $token, ?string $idempotencyKey = null): array
{
    // HTTP tests reuse the app container; clear sticky auth before switching tokens.
    app('auth')->forgetGuards();

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Accept-Language' => 'en',
    ];

    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    return $headers;
}

function m31Website(array $attributes = []): WebsiteSetting
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

function m31EnsurePricingRule(): void
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

/**
 * @return array{package: Package, product: Product}
 */
function m31FixedProduct(array $productAttributes = [], array $requirementAttributes = []): array
{
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create(array_merge([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
        'amount_mode' => ProductAmountMode::Fixed,
        'name' => 'Fixed Mobile Product',
    ], $productAttributes));

    if ($requirementAttributes !== []) {
        PackageRequirement::factory()->create(array_merge([
            'package_id' => $package->id,
            'key' => 'id',
            'label' => 'Player ID',
            'type' => 'string',
            'is_required' => true,
            'validation_rules' => 'required|string|max:64',
            'order' => 1,
        ], $requirementAttributes));
    }

    return compact('package', 'product');
}

/**
 * @return array{package: Package, product: Product}
 */
function m31CustomProduct(): array
{
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 0.01,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 100,
        'custom_amount_max' => 1000,
        'custom_amount_step' => 50,
        'amount_unit_label' => 'Coins',
        'name' => 'Custom Mobile Product',
    ]);

    return compact('package', 'product');
}

function m31Fund(User $user, string|float $balance = 100): Wallet
{
    return Wallet::query()->updateOrCreate(
        ['user_id' => $user->id, 'type' => WalletType::Customer],
        [
            'balance' => $balance,
            'currency' => 'USD',
            'credit_enabled' => false,
            'credit_limit' => 0,
            'payment_terms_days' => null,
            'credit_status' => null,
        ]
    );
}

/**
 * @param  list<string>  $forbidden
 */
function m31AssertNoSensitiveKeys(mixed $payload, array $forbidden): void
{
    if (! is_array($payload)) {
        return;
    }

    foreach ($payload as $key => $value) {
        if (is_string($key)) {
            expect($forbidden)->not->toContain($key);
        }
        m31AssertNoSensitiveKeys($value, $forbidden);
    }
}

function m31SensitiveKeys(): array
{
    return [
        'entry_price',
        'retail_price',
        'wholesale_price',
        'base_price',
        'discount_amount',
        'tier_name',
        'uses_user_pricing',
        'is_floor_applied',
        'fulfillment_provider',
        'package_api',
        'product_api',
        'validation_rules',
        'supplier_scanned_price',
        'requirements_payload',
        'key_hash',
        'request_hash',
    ];
}

/**
 * @return array{order_number: string, quote_fingerprint: string}
 */
function m31CheckoutOnce(User $user, string $token, Product $product, Package $package, string $idempotencyKey, int $quantity = 1, array $requirements = []): array
{
    $item = [
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => $quantity,
    ];
    if ($requirements !== []) {
        $item['requirements'] = $requirements;
    }

    $quote = test()->postJson('/api/v1/checkout/quote', [
        'items' => [$item],
    ], m31Headers($token))->assertOk();

    $checkout = test()->postJson('/api/v1/checkout', [
        'items' => [$item],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, $idempotencyKey))->assertOk();

    return [
        'order_number' => (string) $checkout->json('data.order.order_number'),
        'quote_fingerprint' => (string) $quote->json('data.quote_fingerprint'),
        'receipt' => $checkout->json('data.order'),
    ];
}

function m31AssertSinglePurchase(User $user, string $orderNumber): void
{
    expect(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Order::query()->where('order_number', $orderNumber)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1)
        ->and(Fulfillment::query()->count())->toBe(Order::query()->where('order_number', $orderNumber)->firstOrFail()->items()->sum('quantity'));
}
