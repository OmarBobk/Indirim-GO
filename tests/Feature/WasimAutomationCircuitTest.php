<?php

declare(strict_types=1);

use App\Actions\Fulfillments\CreateFulfillmentsForOrder;
use App\Actions\Fulfillments\EnsureAutomationSupplierCircuits;
use App\Actions\Fulfillments\ObserveAutomationSafetySignal;
use App\Actions\Fulfillments\PauseAutomationSupplierCircuit;
use App\Actions\Fulfillments\ResumeAutomationSupplierCircuit;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitPauseReason;
use App\Enums\AutomationCircuitState;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Livewire\Admin\AutomationMonitor;
use App\Models\AutomationSupplierCircuit;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\FulfillmentAutomationService;
use App\Support\Automation\WasimHealthProbeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.suppliers.wasim.session_key' => 'wasim-main',
        'fulfillment_automation.suppliers.wasim.credentials.username' => 'wasim-user',
        'fulfillment_automation.suppliers.wasim.credentials.password' => 'wasim-pass',
        'fulfillment_automation.circuits.purchase.threshold_count' => 3,
        'fulfillment_automation.circuits.purchase.threshold_window_minutes' => 10,
        'fulfillment_automation.circuits.probe_freshness_seconds' => 1800,
        'fulfillment_automation.circuits.supported_ui_versions' => ['wasim-ui-v1'],
    ]);

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    WebsiteSetting::query()->delete();
    WebsiteSetting::create(['automation_enabled' => true]);

    app(EnsureAutomationSupplierCircuits::class)->handle('wasim');
});

function circuitAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function queuedWasimFulfillment(): Fulfillment
{
    $user = User::factory()->create();
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:wasim',
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 25]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    (new CreateFulfillmentsForOrder)->handle($order);

    $fulfillment = Fulfillment::query()->where('order_id', $order->id)->firstOrFail();
    $fulfillment->update([
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Queued,
        'claimed_by' => null,
    ]);

    return $fulfillment->fresh();
}

function processingWasimAwaitingReconcile(): Fulfillment
{
    $fulfillment = queuedWasimFulfillment();
    $fulfillment->update([
        'status' => FulfillmentStatus::Processing,
        'meta' => [
            'automation' => [
                'awaiting_wasim_reconcile' => true,
                'supplier_order_id' => 'WS-KEEP',
            ],
        ],
    ]);

    return $fulfillment->fresh();
}

function purchaseCircuit(): AutomationSupplierCircuit
{
    return AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->where('capability', AutomationCircuitCapability::Purchase->value)
        ->firstOrFail();
}

it('creates one circuit row per wasim capability', function (): void {
    expect(AutomationSupplierCircuit::query()->where('supplier_key', 'wasim')->count())->toBe(3);
});

it('opens purchase circuit immediately for unsupported_ui', function (): void {
    Notification::fake();

    $circuit = app(ObserveAutomationSafetySignal::class)->handle([
        'supplier_key' => 'wasim',
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-1:unsupported_ui',
        'capability_hint' => 'purchase',
    ]);

    expect($circuit)->not->toBeNull()
        ->and($circuit->state)->toBe(AutomationCircuitState::PausedAuto)
        ->and($circuit->reason_code)->toBe('unsupported_ui');
});

it('does not open a circuit for order-specific required_field_missing', function (): void {
    $circuit = app(ObserveAutomationSafetySignal::class)->handle([
        'supplier_key' => 'wasim',
        'failure_code' => 'required_field_missing',
        'source_type' => 'automation_run',
        'source_key' => 'run-order-1',
    ]);

    expect($circuit)->toBeNull()
        ->and(purchaseCircuit()->state)->toBe(AutomationCircuitState::Enabled);
});

it('does not open purchase circuit below threshold for authentication_required', function (): void {
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'authentication_required',
        'source_type' => 'automation_run',
        'source_key' => 'run-a',
    ]);
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'authentication_required',
        'source_type' => 'automation_run',
        'source_key' => 'run-b',
    ]);

    expect(purchaseCircuit()->state)->toBe(AutomationCircuitState::Enabled);
});

it('opens purchase circuit after threshold of authentication_required', function (): void {
    foreach (['run-a', 'run-b', 'run-c'] as $key) {
        app(ObserveAutomationSafetySignal::class)->handle([
            'failure_code' => 'authentication_required',
            'source_type' => 'automation_run',
            'source_key' => $key,
        ]);
    }

    $circuit = purchaseCircuit();

    expect($circuit->state)->toBe(AutomationCircuitState::PausedAuto)
        ->and($circuit->consecutive_failure_count)->toBe(3);
});

it('ignores replayed identical signal keys', function (): void {
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'authentication_required',
        'source_type' => 'automation_run',
        'source_key' => 'same-run',
    ]);
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'authentication_required',
        'source_type' => 'automation_run',
        'source_key' => 'same-run',
    ]);

    $circuit = purchaseCircuit();

    expect($circuit->consecutive_failure_count)->toBe(1)
        ->and($circuit->state)->toBe(AutomationCircuitState::Enabled);
});

it('blocks purchase eligibility when purchase circuit is paused without failing the fulfillment', function (): void {
    $fulfillment = queuedWasimFulfillment();

    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-ui',
    ]);

    expect(app(FulfillmentAutomationService::class)->isEligible($fulfillment))->toBeFalse();

    $fulfillment->refresh();
    expect($fulfillment->status)->toBe(FulfillmentStatus::Queued);
});

it('keeps reconcile eligibility independent when only purchase is paused', function (): void {
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-ui',
        'capability_hint' => 'purchase',
    ]);

    $fulfillment = processingWasimAwaitingReconcile();

    expect(app(FulfillmentAutomationService::class)->isEligibleForReconcile($fulfillment))->toBeTrue();
});

it('blocks reconcile eligibility when reconcile circuit is paused', function (): void {
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'orders_ui_unsupported',
        'source_type' => 'automation_run',
        'source_key' => 'run-orders',
        'capability_hint' => 'reconcile',
    ]);

    $fulfillment = processingWasimAwaitingReconcile();

    expect(app(FulfillmentAutomationService::class)->isEligibleForReconcile($fulfillment))->toBeFalse();
    expect(data_get($fulfillment->fresh()->meta, 'automation.supplier_order_id'))->toBe('WS-KEEP');
});

it('moves paused_auto to probe_required after healthy probe', function (): void {
    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-ui',
    ]);

    app(ObserveAutomationSafetySignal::class)->handleHealthyProbe('wasim', [
        'checked_at' => now()->toIso8601String(),
        'state' => 'healthy',
        'failure_codes' => [],
        'detected_ui_version' => 'wasim-ui-v1',
        'purchase_contract_state' => 'healthy',
        'reconcile_contract_state' => 'healthy',
    ]);

    expect(purchaseCircuit()->state)->toBe(AutomationCircuitState::ProbeRequired);
});

it('denies resume without a healthy fresh probe', function (): void {
    $admin = circuitAdmin();

    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-ui',
    ]);
    app(ObserveAutomationSafetySignal::class)->handleHealthyProbe('wasim', [
        'checked_at' => now()->toIso8601String(),
        'state' => 'healthy',
        'failure_codes' => [],
        'detected_ui_version' => 'wasim-ui-v1',
        'purchase_contract_state' => 'healthy',
        'reconcile_contract_state' => 'healthy',
    ]);

    app(WasimHealthProbeStore::class)->record([
        'checked_at' => now()->subHours(5)->toIso8601String(),
        'worker_build' => null,
        'worker_instance_id' => null,
        'driver_version' => null,
        'detected_ui_version' => 'wasim-ui-v1',
        'purchase_contract_version' => null,
        'orders_contract_version' => null,
        'session_state' => null,
        'purchase_contract_state' => 'healthy',
        'reconcile_contract_state' => 'healthy',
        'test_product_state' => null,
        'state' => 'unreachable',
        'failure_codes' => ['probe_unreachable'],
        'duration_ms' => null,
        'operational_classification' => null,
    ]);

    expect(fn () => app(ResumeAutomationSupplierCircuit::class)->handle(
        $admin,
        'wasim',
        AutomationCircuitCapability::Purchase,
        confirmed: true,
    ))->toThrow(ValidationException::class);
});

it('resumes from probe_required when probe is healthy and fresh', function (): void {
    $admin = circuitAdmin();

    app(ObserveAutomationSafetySignal::class)->handle([
        'failure_code' => 'unsupported_ui',
        'source_type' => 'automation_run',
        'source_key' => 'run-ui',
    ]);
    app(ObserveAutomationSafetySignal::class)->handleHealthyProbe('wasim', [
        'checked_at' => now()->toIso8601String(),
        'state' => 'healthy',
        'failure_codes' => [],
        'detected_ui_version' => 'wasim-ui-v1',
        'purchase_contract_state' => 'healthy',
        'reconcile_contract_state' => 'healthy',
    ]);

    app(WasimHealthProbeStore::class)->record([
        'checked_at' => now()->toIso8601String(),
        'worker_build' => 'build',
        'worker_instance_id' => 'inst',
        'driver_version' => 'wasim-1.1.0',
        'detected_ui_version' => 'wasim-ui-v1',
        'purchase_contract_version' => 'wasim-purchase-v1',
        'orders_contract_version' => 'wasim-orders-v1',
        'session_state' => 'authenticated',
        'purchase_contract_state' => 'healthy',
        'reconcile_contract_state' => 'healthy',
        'test_product_state' => 'price_readable',
        'state' => 'healthy',
        'failure_codes' => [],
        'duration_ms' => 10,
        'operational_classification' => 'healthy',
    ]);

    $circuit = app(ResumeAutomationSupplierCircuit::class)->handle(
        $admin,
        'wasim',
        AutomationCircuitCapability::Purchase,
        confirmed: true,
    );

    expect($circuit->state)->toBe(AutomationCircuitState::Enabled);
});

it('manual pause does not mutate automation_enabled or fulfillment status', function (): void {
    $admin = circuitAdmin();
    $fulfillment = queuedWasimFulfillment();

    app(PauseAutomationSupplierCircuit::class)->handle(
        $admin,
        'wasim',
        AutomationCircuitCapability::Purchase,
        AutomationCircuitPauseReason::Investigation,
    );

    expect(WebsiteSetting::getAutomationEnabled())->toBeTrue()
        ->and($fulfillment->fresh()->status)->toBe(FulfillmentStatus::Queued)
        ->and(app(FulfillmentAutomationService::class)->isEligible($fulfillment))->toBeFalse();
});

it('admin can pause via Livewire and non-admin cannot', function (): void {
    $admin = circuitAdmin();
    $user = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AutomationMonitor::class)
        ->call('pauseWasimCircuit', 'purchase', 'investigation')
        ->assertHasNoErrors();

    expect(purchaseCircuit()->state)->toBe(AutomationCircuitState::PausedManual);

    Livewire::actingAs($user)
        ->test(AutomationMonitor::class)
        ->assertForbidden();
});
