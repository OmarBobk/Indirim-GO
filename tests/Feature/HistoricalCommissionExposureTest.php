<?php

declare(strict_types=1);

use App\Actions\Commissions\GetHistoricalCommissionExposure;
use App\Actions\Commissions\ReviewHistoricalCommissionExposure;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\HistoricalCommissionExposureOutcome;
use App\Enums\HistoricalCommissionExposureReason;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Livewire\Admin\HistoricalCommissionExposureIndex;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\Fulfillment;
use App\Models\HistoricalCommissionExposureReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'view_historical_commission_exposure',
        'view_commission_clawbacks',
        'process_commission_clawbacks',
        'manage_settlements',
        'adjust_wallets',
        'view_dashboard',
        'view_referrals',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    config([
        'billing.commission_clawback.effective_at' => now()->subDay()->toIso8601String(),
        'billing.commission_clawback.policy_version' => 1,
    ]);
});

/**
 * @return array{
 *     admin: User,
 *     salesperson: User,
 *     commission: Commission,
 *     credit: WalletTransaction,
 *     refund: WalletTransaction,
 *     order: Order,
 *     fulfillment: Fulfillment,
 *     wallet: Wallet
 * }
 */
function historicalExposureFixture(array $overrides = []): array
{
    $refundPostedAt = $overrides['refund_posted_at'] ?? now()->subDays(3);
    $creditPostedAt = $overrides['credit_posted_at'] ?? now()->subDays(5);
    $withCredit = $overrides['with_credit'] ?? true;
    $commissionStatus = $overrides['commission_status'] ?? CommissionStatus::Credited;
    $linkRefund = $overrides['link_refund'] ?? true;
    $createClawback = $overrides['create_clawback'] ?? false;
    $createReversal = $overrides['create_reversal'] ?? false;
    $amount = (string) ($overrides['amount'] ?? '20.00');

    $salesperson = User::factory()->create(['email' => 'sp-hist-'.uniqid('', true).'@example.com']);
    $salesperson->givePermissionTo('view_referrals');
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => '50.00']);

    $admin = User::factory()->create();
    $admin->givePermissionTo(['view_historical_commission_exposure', 'view_commission_clawbacks']);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-HIST-'.uniqid(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(6),
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => 'Hist Pack',
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

    $credit = null;
    if ($withCredit) {
        $credit = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::CommissionCredit,
            'direction' => WalletTransactionDirection::Credit,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => WalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'hist-credit-'.uniqid(),
            'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
            'posted_at' => $creditPostedAt,
        ]);
    }

    $refund = WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($customer)->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '100.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'hist-refund-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => $refundPostedAt,
        'reference_type' => $linkRefund ? Fulfillment::class : null,
        'reference_id' => $linkRefund ? $fulfillment->id : null,
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'HIST'.substr(uniqid(), -4),
        'order_total' => 100,
        'commission_amount' => $amount,
        'commission_rate_percent' => 20,
        'status' => $commissionStatus,
        'wallet_transaction_id' => $credit?->id,
        'paid_at' => $withCredit ? $creditPostedAt : null,
    ]);

    if ($createReversal) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::CommissionReversal,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => WalletTransaction::STATUS_POSTED,
            'idempotency_key' => CommissionClawbackPolicy::reversalIdempotencyKey((int) $commission->id, (int) $refund->id),
            'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
            'posted_at' => now()->subDay(),
        ]);
    }

    if ($createClawback) {
        CommissionClawback::query()->create([
            'public_ref' => CommissionClawbackPublicRef::allocateUnique(),
            'commission_id' => $commission->id,
            'salesperson_id' => $salesperson->id,
            'fulfillment_id' => $fulfillment->id,
            'refund_wallet_transaction_id' => $refund->id,
            'original_commission_credit_transaction_id' => $credit?->id,
            'reversal_wallet_transaction_id' => null,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => CommissionClawbackStatus::NeedsReview,
            'policy_version' => 1,
            'idempotency_key' => 'hist-claw-'.uniqid(),
            'failure_code' => 'job_exhausted',
            'failure_message_safe' => 'Needs review.',
        ]);
    }

    return [
        'admin' => $admin,
        'salesperson' => $salesperson,
        'commission' => $commission->fresh(),
        'credit' => $credit,
        'refund' => $refund,
        'order' => $order,
        'fulfillment' => $fulfillment,
        'wallet' => $wallet->fresh(),
    ];
}

it('lists confirmed pre-policy exposure with exact amount', function (): void {
    $fixture = historicalExposureFixture();

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'confirmed',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(1)
        ->and($page['items'][0]->confidence)->toBe('confirmed')
        ->and($page['items'][0]->exposureAmount)->toBe('20.00')
        ->and($page['items'][0]->commissionId)->toBe($fixture['commission']->id)
        ->and($page['summary']['confirmed_unreviewed_count'])->toBe(1);
});

it('excludes credited commission without refund', function (): void {
    historicalExposureFixture(['link_refund' => false]);

    $admin = User::factory()->create();
    $admin->givePermissionTo('view_historical_commission_exposure');

    $page = app(GetHistoricalCommissionExposure::class)->handle($admin, [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(0);
});

it('excludes pending commissions', function (): void {
    historicalExposureFixture(['commission_status' => CommissionStatus::Pending]);

    $admin = User::factory()->create();
    $admin->givePermissionTo('view_historical_commission_exposure');

    $page = app(GetHistoricalCommissionExposure::class)->handle($admin, [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(0);
});

it('excludes reversed commissions', function (): void {
    $fixture = historicalExposureFixture(['create_reversal' => true]);

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(0);
});

it('excludes post-policy clawback needs-review cases', function (): void {
    $fixture = historicalExposureFixture([
        'refund_posted_at' => now()->subHours(2),
        'create_clawback' => true,
    ]);

    config(['billing.commission_clawback.effective_at' => now()->subDays(10)->toIso8601String()]);

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(0);
});

it('classifies missing credit link as incomplete', function (): void {
    $fixture = historicalExposureFixture(['with_credit' => false]);

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'incomplete',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(1)
        ->and($page['items'][0]->confidence)->toBe('incomplete')
        ->and($page['items'][0]->exposureAmount)->toBe('20.00');
});

it('uses one fulfillment commission grain and ignores float drift', function (): void {
    $fixture = historicalExposureFixture(['amount' => '12.34']);

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'confirmed',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['items'])->toHaveCount(1)
        ->and($page['items'][0]->exposureAmount)->toBe('12.34')
        ->and($page['summary']['confirmed_exposure_total'])->toBe('12.34');
});

it('denies unauthorized roles and hides route', function (): void {
    historicalExposureFixture();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    $supervisor->givePermissionTo('view_dashboard');

    $salesperson = User::factory()->create();
    $salesperson->assignRole('salesperson');
    $salesperson->givePermissionTo('view_referrals');

    $customer = User::factory()->create();

    expect($supervisor->can('view_historical_commission_exposure'))->toBeFalse()
        ->and($salesperson->can('view_historical_commission_exposure'))->toBeFalse()
        ->and($customer->can('view_historical_commission_exposure'))->toBeFalse();

    $this->actingAs($supervisor)
        ->get(route('admin.commission-clawbacks.historical-exposure'))
        ->assertForbidden();

    $this->actingAs($salesperson)
        ->get(route('admin.commission-clawbacks.historical-exposure'))
        ->assertForbidden();
});

it('authorizes the get action independently', function (): void {
    $fixture = historicalExposureFixture();
    $outsider = User::factory()->create();
    $outsider->givePermissionTo('adjust_wallets');

    expect(fn () => app(GetHistoricalCommissionExposure::class)->handle($outsider, [
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('reviews exposure without wallet commission refund or clawback mutation', function (): void {
    $fixture = historicalExposureFixture();
    $walletBefore = (string) $fixture['wallet']->balance;
    $commissionStatusBefore = $fixture['commission']->status;
    $commissionAmountBefore = (string) $fixture['commission']->commission_amount;
    $commissionWtxBefore = $fixture['commission']->wallet_transaction_id;
    $refundStatusBefore = $fixture['refund']->status;
    $refundAmountBefore = (string) $fixture['refund']->amount;
    $wtxCount = WalletTransaction::query()->count();
    $clawbackCount = CommissionClawback::query()->count();

    $result = app(ReviewHistoricalCommissionExposure::class)->handle(
        $fixture['admin'],
        (int) $fixture['commission']->id,
        (int) $fixture['refund']->id,
        HistoricalCommissionExposureOutcome::PlatformAbsorbed->value,
        HistoricalCommissionExposureReason::PrePolicyRefund->value,
        'Platform absorbs pre-policy gap',
    );

    expect($result['outcome'])->toBe('reviewed')
        ->and($result['review'])->not->toBeNull();

    $replay = app(ReviewHistoricalCommissionExposure::class)->handle(
        $fixture['admin'],
        (int) $fixture['commission']->id,
        (int) $fixture['refund']->id,
        HistoricalCommissionExposureOutcome::PlatformAbsorbed->value,
        HistoricalCommissionExposureReason::PrePolicyRefund->value,
        'Platform absorbs pre-policy gap',
    );

    expect($replay['outcome'])->toBe('replayed')
        ->and(HistoricalCommissionExposureReview::query()->count())->toBe(1)
        ->and((string) $fixture['wallet']->fresh()->balance)->toBe($walletBefore)
        ->and($fixture['commission']->fresh()->status)->toBe($commissionStatusBefore)
        ->and((string) $fixture['commission']->fresh()->commission_amount)->toBe($commissionAmountBefore)
        ->and($fixture['commission']->fresh()->wallet_transaction_id)->toBe($commissionWtxBefore)
        ->and($fixture['refund']->fresh()->status)->toBe($refundStatusBefore)
        ->and((string) $fixture['refund']->fresh()->amount)->toBe($refundAmountBefore)
        ->and(WalletTransaction::query()->count())->toBe($wtxCount)
        ->and(CommissionClawback::query()->count())->toBe($clawbackCount);
});

it('rejects invalid review outcomes', function (): void {
    $fixture = historicalExposureFixture();

    $result = app(ReviewHistoricalCommissionExposure::class)->handle(
        $fixture['admin'],
        (int) $fixture['commission']->id,
        (int) $fixture['refund']->id,
        'collect',
        HistoricalCommissionExposureReason::PrePolicyRefund->value,
    );

    expect($result['outcome'])->toBe('denied')
        ->and(HistoricalCommissionExposureReview::query()->count())->toBe(0);
});

it('denies review without permission', function (): void {
    $fixture = historicalExposureFixture();
    $outsider = User::factory()->create();
    $outsider->givePermissionTo('process_commission_clawbacks');

    expect(fn () => app(ReviewHistoricalCommissionExposure::class)->handle(
        $outsider,
        (int) $fixture['commission']->id,
        (int) $fixture['refund']->id,
        HistoricalCommissionExposureOutcome::NotActionable->value,
        HistoricalCommissionExposureReason::FeatureGap->value,
    ))->toThrow(AuthorizationException::class);
});

it('renders the historical exposure page with filters and strings', function (): void {
    $fixture = historicalExposureFixture();

    Livewire::actingAs($fixture['admin'])
        ->test(HistoricalCommissionExposureIndex::class)
        ->assertSuccessful()
        ->assertSee(__('messages.historical_exposure_title'))
        ->assertSee(__('messages.historical_exposure_no_money_warning'))
        ->assertSee(__('messages.historical_exposure_confidence_confirmed'))
        ->assertSee($fixture['order']->order_number)
        ->assertDontSee('customer_email')
        ->assertDontSee($fixture['salesperson']->email)
        ->set('filter', 'confirmed')
        ->assertSee($fixture['order']->order_number)
        ->call('openReview', $fixture['commission']->id, $fixture['refund']->id)
        ->set('reviewOutcome', HistoricalCommissionExposureOutcome::PlatformAbsorbed->value)
        ->set('reviewReason', HistoricalCommissionExposureReason::PrePolicyRefund->value)
        ->call('submitReview')
        ->assertHasNoErrors();

    expect(HistoricalCommissionExposureReview::query()->count())->toBe(1);

    app()->setLocale('ar');
    expect(__('messages.historical_exposure_title'))->toBe('التعرض التاريخي للعمولات')
        ->and(__('messages.historical_exposure_outcome_platform_absorbed'))->toBe('تحملته المنصة');
    app()->setLocale('en');
});

it('keeps historical route ahead of clawback model binding', function (): void {
    $fixture = historicalExposureFixture();

    $this->actingAs($fixture['admin'])
        ->get(route('admin.commission-clawbacks.historical-exposure'))
        ->assertSuccessful()
        ->assertSeeLivewire(HistoricalCommissionExposureIndex::class);
});

it('avoids n-plus-one when listing exposures', function (): void {
    $admin = User::factory()->create();
    $admin->givePermissionTo('view_historical_commission_exposure');

    foreach (range(1, 3) as $i) {
        historicalExposureFixture(['amount' => '10.0'.$i]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(GetHistoricalCommissionExposure::class)->handle($admin, [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCount)->toBeLessThan(25);
});

it('does not treat inside-policy refunds without clawback as historical when effective_at is set', function (): void {
    config(['billing.commission_clawback.effective_at' => now()->subDays(10)->toIso8601String()]);

    $fixture = historicalExposureFixture([
        'refund_posted_at' => now()->subHours(2),
    ]);

    $page = app(GetHistoricalCommissionExposure::class)->handle($fixture['admin'], [
        'filter' => 'all',
        'refund_from' => now()->subMonths(6)->toDateString(),
    ]);

    expect($page['total'])->toBe(0);
});
