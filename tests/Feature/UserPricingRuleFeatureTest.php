<?php

use App\Actions\Orders\CreateOrderFromCartPayload;
use App\Actions\UserPricingRules\UpsertUserPricingRule;
use App\Enums\LoyaltyTier;
use App\Enums\ProductAmountMode;
use App\Livewire\Users\UserPricingRules;
use App\Models\LoyaltyTierConfig;
use App\Models\Package;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPricingRule;
use App\Notifications\OrderPriceFlooredNotification;
use App\Services\CustomerPriceService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Permission::firstOrCreate(['name' => 'manage_users', 'guard_name' => 'web']);

    PricingRule::query()->create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 10,
        'wholesale_percentage' => 2,
        'priority' => 0,
        'is_active' => true,
    ]);

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
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

    Permission::firstOrCreate(['name' => 'manage_user_prices', 'guard_name' => 'web']);
});

test('user pricing rule applies custom retail markup before loyalty discount', function (): void {
    $user = User::factory()->create(['loyalty_tier' => LoyaltyTier::Gold]);
    $user->assignRole('customer');
    $product = Product::factory()->create(['entry_price' => 100]);

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 5,
        'wholesale_percentage' => 1,
        'priority' => 0,
        'is_active' => true,
    ]);

    $result = app(CustomerPriceService::class)->priceFor($product, $user);

    expect($result['base_price'])->toBe(105.0);
    expect($result['discount_amount'])->toBe(5.0);
    expect($result['final_price'])->toBe(100.0);
    expect($result['tier_name'])->toBe('gold');
    expect($result['meta']['uses_user_pricing'])->toBeTrue();
    expect($result['meta']['is_floor_applied'])->toBeTrue();
});

test('user pricing rule uses wholesale when user is salesperson', function (): void {
    $user = User::factory()->create();
    $user->assignRole('salesperson');
    $product = Product::factory()->create(['entry_price' => 100]);

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 20,
        'wholesale_percentage' => 3,
        'priority' => 0,
        'is_active' => true,
    ]);

    $result = app(CustomerPriceService::class)->priceFor($product, $user);

    expect($result['base_price'])->toBe(103.0);
    expect($result['meta']['uses_user_pricing'])->toBeTrue();
});

test('no user rule falls back to global pricing rules', function (): void {
    $user = User::factory()->create(['loyalty_tier' => LoyaltyTier::Gold]);
    $user->assignRole('customer');
    $product = Product::factory()->create(['entry_price' => 100]);

    $result = app(CustomerPriceService::class)->priceFor($product, $user);

    expect($result['base_price'])->toBe(110.0);
    expect($result['discount_amount'])->toBe(10.0);
    expect($result['final_price'])->toBe(100.0);
    expect($result['meta']['uses_user_pricing'])->toBeFalse();
    expect($result['meta']['is_floor_applied'])->toBeTrue();
});

test('finalPriceForAmount returns expected structure with user pricing rule', function (): void {
    $user = User::factory()->create(['loyalty_tier' => LoyaltyTier::Gold]);
    $user->assignRole('customer');

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 10,
        'wholesale_percentage' => 2,
        'priority' => 0,
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'entry_price' => 0.01,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 1,
        'custom_amount_max' => 200000,
        'custom_amount_step' => 1,
    ]);

    $result = app(CustomerPriceService::class)->finalPriceForAmount($product, 9539, $user);

    expect($result)->toHaveKeys(['base_price', 'discount_amount', 'final_price', 'tier_name', 'meta']);
    expect($result['base_price'])->toBe(104.93);
    expect($result['discount_amount'])->toBe(9.54);
    expect($result['final_price'])->toBe(95.39);
    expect($result['tier_name'])->toBe('gold');
    expect(data_get($result, 'meta.uses_user_pricing'))->toBeTrue();
});

test('order item uses user pricing rule when creating order', function (): void {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 50,
        'wholesale_percentage' => 5,
        'priority' => 0,
        'is_active' => true,
    ]);

    $order = app(CreateOrderFromCartPayload::class)->handle($user, [
        [
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 3,
        ],
    ], null, false);

    $item = $order->items->first();
    expect($item)->not->toBeNull();
    expect((float) $item->unit_price)->toBe(150.0);
    expect((float) $item->line_total)->toBe(450.0);
});

test('sends admin notification when order item price is clamped to entry price floor', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $user = User::factory()->create(['loyalty_tier' => LoyaltyTier::Gold]);
    $user->assignRole('customer');
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 5,
        'wholesale_percentage' => 1,
        'priority' => 0,
        'is_active' => true,
    ]);

    app(CreateOrderFromCartPayload::class)->handle($user, [
        [
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ],
    ]);

    $admin->refresh();
    $notification = $admin->notifications()->latest()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe(OrderPriceFlooredNotification::class);
});

test('user pricing rules livewire can create a rule', function (): void {
    $admin = User::factory()->create();
    $admin->givePermissionTo(['manage_users', 'manage_user_prices']);

    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(UserPricingRules::class, ['user' => $target])
        ->call('openCreate')
        ->set('retailPercentage', '8')
        ->set('wholesalePercentage', '3')
        ->call('save')
        ->assertHasNoErrors();

    expect(UserPricingRule::query()->where('user_id', $target->id)->count())->toBe(1);
});

test('upsert user pricing rule action requires permission', function (): void {
    $admin = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => app(UpsertUserPricingRule::class)->handle($target, null, [
        'min_price' => 0,
        'max_price' => 999999.99,
        'retail_percentage' => 5,
        'wholesale_percentage' => 2,
        'priority' => 0,
        'is_active' => true,
    ], $admin))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});
