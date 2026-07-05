<?php

declare(strict_types=1);

use App\Actions\SupplierPrices\ApplyWasimScannedEntryPrices;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\SupplierPriceScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    WebsiteSetting::instance()->update([
        'automation_enabled' => true,
        'wasim_automation_username' => 'wasim@test.com',
        'wasim_automation_password' => 'secret-pass',
    ]);

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate(['name' => 'view_dashboard']);
    Permission::firstOrCreate(['name' => 'update_product_prices']);
});

function priceDriftUser(): User
{
    $role = Role::firstOrCreate(['name' => 'price_editor']);
    $role->syncPermissions(['view_dashboard', 'update_product_prices']);
    $user = User::factory()->create();
    $user->assignRole('price_editor');

    return $user;
}

function makeDriftProduct(float $entry, float $scanned): Product
{
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);

    return Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=101',
        'entry_price' => $entry,
        'supplier_scanned_price' => $scanned,
        'supplier_scanned_at' => now(),
    ]);
}

test('guest cannot access price drift page', function () {
    $this->get('/price-drift')->assertRedirect();
});

test('user with permission can view price drift page', function () {
    $this->actingAs(priceDriftUser())
        ->get('/price-drift')
        ->assertOk()
        ->assertSee('data-test="price-drift-page"', false);
});

test('has price drift uses stored supplier and entry prices', function () {
    $product = makeDriftProduct(1.0, 1.5);

    expect(app(SupplierPriceScanService::class)->hasPriceDrift($product->fresh()))->toBeTrue();
});

test('monitor query returns drifted wasim products', function () {
    makeDriftProduct(1.0, 1.5);

    expect(app(SupplierPriceScanService::class)->monitorProductsQuery(null, 'all')->count())->toBe(1)
        ->and(app(SupplierPriceScanService::class)->monitorProductsQuery(null, 'drifted')->count())->toBe(1);
});

test('price drift page shows drifted product by default', function () {
    $drifted = makeDriftProduct(1.0, 1.5);
    $package = Package::query()->findOrFail($drifted->package_id);
    $unchanged = Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=102',
        'entry_price' => '2.00000000',
        'supplier_scanned_price' => '2.00000000',
        'supplier_scanned_at' => now(),
    ]);

    $this->actingAs(priceDriftUser());

    Livewire::test('pages::backend.price-drift.index')
        ->assertSee($drifted->name)
        ->assertDontSee($unchanged->name);
});

test('admin can apply wasim scanned price from price drift page', function () {
    $product = makeDriftProduct(1.0, 1.42);

    $this->actingAs(priceDriftUser());

    Livewire::test('pages::backend.price-drift.index')
        ->call('applyWasimPrice', $product->id)
        ->assertHasNoErrors();

    expect((float) $product->fresh()->entry_price)->toBe(1.42);
});

test('price drift scan now dispatches worker request', function () {
    Http::fake([
        'automation-worker.test/v1/price-scans' => Http::response(['accepted' => true], 202),
    ]);

    makeDriftProduct(1.0, 1.2);

    $this->actingAs(priceDriftUser());

    Livewire::test('pages::backend.price-drift.index')
        ->call('startScan')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request): bool => $request->url() === 'http://automation-worker.test/v1/price-scans');
});

test('apply wasim scanned entry prices action updates multiple products', function () {
    $first = makeDriftProduct(1.0, 1.1);
    $second = makeDriftProduct(2.0, 2.2);

    $updated = app(ApplyWasimScannedEntryPrices::class)->handle([$first->id, $second->id]);

    expect($updated)->toBe(2)
        ->and((float) $first->fresh()->entry_price)->toBe(1.1)
        ->and((float) $second->fresh()->entry_price)->toBe(2.2);
});
