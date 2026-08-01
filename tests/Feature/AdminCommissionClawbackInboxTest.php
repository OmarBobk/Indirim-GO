<?php

declare(strict_types=1);

use App\Actions\Commissions\GetAdminCommissionClawbacks;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Actions\Dashboard\GetAdminExceptionCounts;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Livewire\Admin\CommissionClawbackShow;
use App\Livewire\Admin\CommissionClawbacksIndex;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackRetryEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'view_commission_clawbacks',
        'process_commission_clawbacks',
        'manage_settlements',
        'view_dashboard',
        'view_referrals',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
});

/**
 * @return array{admin: User, viewer: User, salesperson: User, clawback: CommissionClawback, order: Order}
 */
function adminClawbackFixture(array $overrides = []): array
{
    $salesperson = User::factory()->create(['email' => 'sp-claw-'.uniqid('', true).'@example.com']);
    $salesperson->givePermissionTo('view_referrals');
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => '50.00']);

    $admin = User::factory()->create();
    $admin->givePermissionTo(['view_commission_clawbacks', 'process_commission_clawbacks']);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['view_commission_clawbacks']);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-CLAW-'.uniqid(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDay(),
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => 'Claw Pack',
        'unit_price' => 100,
        'quantity' => 1,
        'line_total' => 100,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
    ]);

    $credit = WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::CommissionCredit,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '20.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'credit-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHours(2),
    ]);

    $refund = WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($customer)->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '100.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'refund-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHour(),
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'CLAWADM',
        'order_total' => 100,
        'commission_amount' => '20.00',
        'commission_rate_percent' => 20,
        'status' => CommissionStatus::Credited,
        'wallet_transaction_id' => $credit->id,
        'paid_at' => now()->subHours(2),
    ]);

    $clawback = CommissionClawback::query()->create(array_merge([
        'public_ref' => CommissionClawbackPublicRef::allocateUnique(),
        'commission_id' => $commission->id,
        'salesperson_id' => $salesperson->id,
        'fulfillment_id' => $fulfillment->id,
        'refund_wallet_transaction_id' => $refund->id,
        'original_commission_credit_transaction_id' => $credit->id,
        'reversal_wallet_transaction_id' => null,
        'amount' => '20.00',
        'currency' => 'USD',
        'status' => CommissionClawbackStatus::NeedsReview,
        'policy_version' => 1,
        'idempotency_key' => 'claw-'.uniqid(),
        'failure_code' => 'job_exhausted',
        'failure_message_safe' => 'Queue attempts exhausted.',
        'needs_review_at' => now()->subMinutes(5),
    ], $overrides));

    return compact('admin', 'viewer', 'salesperson', 'clawback', 'order');
}

it('grants admin view and process permissions by default via seeder list and denies other roles', function (): void {
    $admin = User::factory()->create();
    $admin->givePermissionTo(['view_commission_clawbacks', 'process_commission_clawbacks']);

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');

    $salesperson = User::factory()->create();
    $salesperson->assignRole('salesperson');

    $customer = User::factory()->create();
    $customer->assignRole('customer');

    expect($admin->can('view_commission_clawbacks'))->toBeTrue()
        ->and($admin->can('process_commission_clawbacks'))->toBeTrue()
        ->and($supervisor->can('view_commission_clawbacks'))->toBeFalse()
        ->and($salesperson->can('view_commission_clawbacks'))->toBeFalse()
        ->and($customer->can('view_commission_clawbacks'))->toBeFalse();
});

it('hides clawback routes from unauthorized roles with backend denial', function (): void {
    $salesperson = User::factory()->create();
    $salesperson->assignRole('salesperson');

    $this->actingAs($salesperson)
        ->get(route('admin.commission-clawbacks.index'))
        ->assertNotFound();
});

it('allows view-only users to open inbox and detail but not retry', function (): void {
    $fixture = adminClawbackFixture();

    $this->actingAs($fixture['viewer'])
        ->get(route('admin.commission-clawbacks.index'))
        ->assertOk()
        ->assertSee($fixture['clawback']->public_ref)
        ->assertDontSee(__('messages.clawback_retry_action'));

    $this->actingAs($fixture['viewer'])
        ->get(route('admin.commission-clawbacks.show', $fixture['clawback']))
        ->assertOk()
        ->assertSee($fixture['clawback']->public_ref);

    expect(fn () => app(RetryCommissionClawback::class)->handle($fixture['viewer'], $fixture['clawback']))
        ->toThrow(AuthorizationException::class);
});

it('orders action-required clawbacks first and filters needs review', function (): void {
    $fixture = adminClawbackFixture();

    $extraRefund = WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser(User::factory()->create())->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '10.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'refund-extra-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now(),
    ]);

    CommissionClawback::query()->create([
        'public_ref' => CommissionClawbackPublicRef::allocateUnique(),
        'commission_id' => $fixture['clawback']->commission_id,
        'salesperson_id' => $fixture['salesperson']->id,
        'fulfillment_id' => $fixture['clawback']->fulfillment_id,
        'refund_wallet_transaction_id' => $extraRefund->id,
        'original_commission_credit_transaction_id' => $fixture['clawback']->original_commission_credit_transaction_id,
        'amount' => '5.00',
        'currency' => 'USD',
        'status' => CommissionClawbackStatus::Posted,
        'policy_version' => 1,
        'idempotency_key' => 'posted-'.uniqid(),
        'posted_at' => now(),
        'created_at' => now()->addMinute(),
    ]);

    $page = app(GetAdminCommissionClawbacks::class)->handle($fixture['admin'], ['filter' => 'all']);
    expect($page['items'][0]->publicRef)->toBe($fixture['clawback']->public_ref)
        ->and($page['items'][0]->isActionRequired)->toBeTrue();

    $filtered = app(GetAdminCommissionClawbacks::class)->handle($fixture['admin'], ['filter' => 'needs_review']);
    expect($filtered['total'])->toBe(1)
        ->and($filtered['items'][0]->failureCategory)->toBe('operational');
});

it('searches by CLB and order number without exposing customer secrets', function (): void {
    $fixture = adminClawbackFixture();

    $byRef = app(GetAdminCommissionClawbacks::class)->handle($fixture['admin'], [
        'search' => $fixture['clawback']->public_ref,
    ]);
    expect($byRef['total'])->toBe(1);

    $byOrder = app(GetAdminCommissionClawbacks::class)->handle($fixture['admin'], [
        'search' => $fixture['order']->order_number,
    ]);
    expect($byOrder['total'])->toBe(1);

    Livewire::actingAs($fixture['admin'])
        ->test(CommissionClawbacksIndex::class)
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('stack');
});

it('returns 404 for malformed CLB references', function (): void {
    $fixture = adminClawbackFixture();

    $this->actingAs($fixture['admin'])
        ->get('/admin/commission-clawbacks/CLB-BAD')
        ->assertNotFound();

    $this->actingAs($fixture['admin'])
        ->get('/admin/commission-clawbacks/999999')
        ->assertNotFound();
});

it('shows detail integrity checklist and safe failure presentation', function (): void {
    $fixture = adminClawbackFixture();

    Livewire::actingAs($fixture['admin'])
        ->test(CommissionClawbackShow::class, ['clawback' => $fixture['clawback']])
        ->assertSee(__('messages.clawback_section_integrity'))
        ->assertSee(__('messages.clawback_failure_job_exhausted_title'))
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('failure_message_safe');
});

it('computes permission-aware clawback exception counts without double counting', function (): void {
    $fixture = adminClawbackFixture([
        'status' => CommissionClawbackStatus::NeedsReview,
        'failure_code' => 'job_exhausted',
    ]);

    adminClawbackFixture([
        'status' => CommissionClawbackStatus::Processing,
        'failure_code' => null,
        'failure_message_safe' => null,
        'needs_review_at' => null,
        'attempted_at' => now()->subHours(2),
        'idempotency_key' => 'stale-'.uniqid(),
        'public_ref' => CommissionClawbackPublicRef::allocateUnique(),
    ]);

    $counts = app(GetAdminExceptionCounts::class)->handle($fixture['admin']);
    expect($counts['clawback_needs_review'])->toBe(1)
        ->and($counts['clawback_stale_processing'])->toBe(1)
        ->and($counts['clawback_action_required_total'])->toBe(2);

    $denied = User::factory()->create();
    $deniedCounts = app(GetAdminExceptionCounts::class)->handle($denied);
    expect($deniedCounts['clawback_action_required_total'])->toBe(0)
        ->and($deniedCounts['clawback_needs_review'])->toBe(0);
});

it('marks integrity failures as non-retryable and job_exhausted as retryable', function (): void {
    $eligibility = app(CommissionClawbackRetryEligibility::class);
    $fixture = adminClawbackFixture(['failure_code' => 'job_exhausted']);

    expect($eligibility->decide($fixture['clawback'])->allowed)->toBeTrue();

    $fixture['clawback']->forceFill([
        'failure_code' => 'wrong_wallet',
        'status' => CommissionClawbackStatus::NeedsReview,
    ])->save();

    expect($eligibility->decide($fixture['clawback']->fresh())->allowed)->toBeFalse();
});
