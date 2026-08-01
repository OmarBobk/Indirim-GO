<?php

declare(strict_types=1);

use App\Actions\Commissions\CreatePayoutBatch;
use App\Actions\Commissions\ProcessCommissionClawback;
use App\Actions\Commissions\RequestSalespersonPayout;
use App\Actions\Earnings\GetSalespersonEarnings;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\DTOs\WalletPosting;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\CustomerFinancialStateChanged;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Jobs\ProcessCommissionClawbackJob;
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
use App\Models\WebsiteSetting;
use App\Services\WalletLedger;
use App\Services\WalletSpendPolicy;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\LedgerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'view_referrals',
        'manage_settlements',
        'process_refunds',
        'view_refunds',
        'manage_fulfillments',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
});

/**
 * @return array{salesperson: User, admin: User, commission: Commission, fulfillment: Fulfillment, customer: User, order: Order, item: OrderItem}
 */
function clawbackFixture(string $commissionAmount = '20.00', string $walletBalance = '100.00'): array
{
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => $walletBalance]);

    $admin = User::factory()->create();
    $admin->givePermissionTo(['manage_settlements', 'process_refunds', 'view_refunds']);

    $customer = User::factory()->create();
    Wallet::forUser($customer)->update(['balance' => '0.00']);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(30),
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
        'status' => FulfillmentStatus::Completed,
        'attempts' => 1,
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'CLAWREF',
        'order_total' => 100,
        'commission_amount' => $commissionAmount,
        'commission_rate_percent' => 20,
        'status' => CommissionStatus::Pending,
    ]);

    WebsiteSetting::instance()->update([
        'commission_payout_wait_days' => 0,
        'commission_payout_min_amount' => 0,
    ]);

    app(CreatePayoutBatch::class)->handle($admin, [$commission->id], null, false);
    $commission->refresh();

    // Refund path requires a failed fulfillment after commission was already credited.
    $fulfillment->update([
        'status' => FulfillmentStatus::Failed,
        'last_error' => 'Provider error',
    ]);
    $item->update(['status' => OrderItemStatus::Failed]);

    Wallet::forUser($salesperson)->update(['balance' => $walletBalance]);

    return compact('salesperson', 'admin', 'commission', 'fulfillment', 'customer', 'order', 'item');
}

function approveRefundForItem(User $admin, OrderItem $item, Fulfillment $fulfillment, User $customer): WalletTransaction
{
    Queue::fake();

    $pending = app(RefundOrderItem::class)->handle($fulfillment, (int) $customer->id);
    expect($pending->status)->toBe(WalletTransaction::STATUS_PENDING);

    return app(ApproveRefundRequest::class)->handle($admin, $pending->id);
}

it('fails pending commission without creating a clawback obligation', function (): void {
    $fixture = clawbackFixture();
    $fixture['commission']->update([
        'status' => CommissionStatus::Pending,
        'wallet_transaction_id' => null,
        'paid_at' => null,
    ]);

    approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);

    expect($fixture['commission']->fresh()->status)->toBe(CommissionStatus::Failed)
        ->and(CommissionClawback::query()->count())->toBe(0);
});

it('creates clawback obligation for credited commission on refund and posts reversal', function (): void {
    $fixture = clawbackFixture();
    $before = LedgerMoney::normalize((string) Wallet::forUser($fixture['salesperson'])->balance);

    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);

    $clawback = CommissionClawback::query()->first();
    expect($clawback)->not->toBeNull()
        ->and($clawback->status)->toBe(CommissionClawbackStatus::Pending)
        ->and($clawback->amount)->toBe('20.00')
        ->and($clawback->public_ref)->toStartWith('CLB-')
        ->and($clawback->refund_wallet_transaction_id)->toBe($refund->id);

    Queue::assertPushed(ProcessCommissionClawbackJob::class);

    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);
    $clawback->refresh();

    expect($clawback->status)->toBe(CommissionClawbackStatus::Posted)
        ->and($clawback->reversal_wallet_transaction_id)->not->toBeNull();

    $reversal = WalletTransaction::query()->findOrFail($clawback->reversal_wallet_transaction_id);
    expect($reversal->type)->toBe(WalletTransactionType::CommissionReversal)
        ->and($reversal->direction)->toBe(WalletTransactionDirection::Debit)
        ->and($reversal->status)->toBe(WalletTransaction::STATUS_POSTED)
        ->and(LedgerMoney::equals((string) $reversal->amount, '20.00'))->toBeTrue()
        ->and($reversal->public_ref)->toStartWith('WTX-');

    $credit = WalletTransaction::query()->findOrFail($fixture['commission']->wallet_transaction_id);
    expect($credit->type)->toBe(WalletTransactionType::CommissionCredit)
        ->and(LedgerMoney::equals((string) $credit->amount, '20.00'))->toBeTrue();

    $after = LedgerMoney::normalize((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance);
    expect(LedgerMoney::equals($after, LedgerMoney::sub($before, '20.00')))->toBeTrue();
});

it('allows controlled negative salesperson balance and blocks purchase and payout', function (): void {
    $fixture = clawbackFixture(walletBalance: '5.00');

    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);
    $clawback = CommissionClawback::query()->where('refund_wallet_transaction_id', $refund->id)->firstOrFail();
    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);

    $wallet = Wallet::forUser($fixture['salesperson'])->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '-15.00'))->toBeTrue()
        ->and($wallet->availableToSpend())->toBe('0.00')
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt($wallet))->toBeTrue();

    $spend = app(WalletSpendPolicy::class)->evaluate($wallet, '1.00');
    expect($spend->allowed)->toBeFalse();

    expect(app(RequestSalespersonPayout::class)->handle($fixture['salesperson']))->toBe('clawback_debt');

    $credit = app(WalletLedger::class)->post(new WalletPosting(
        wallet: $wallet,
        type: WalletTransactionType::Topup,
        direction: WalletTransactionDirection::Credit,
        amount: '20.00',
        idempotencyKey: 'test-topup-recover-debt',
    ));
    $wallet = $credit->wallet->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '5.00'))->toBeTrue()
        ->and($wallet->availableToSpend())->toBe('5.00')
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt($wallet))->toBeFalse();
});

it('rejects forged clawback debt override on generic purchase debit', function (): void {
    $fixture = clawbackFixture(walletBalance: '5.00');
    $wallet = Wallet::forUser($fixture['salesperson']);

    expect(fn () => app(WalletLedger::class)->post(new WalletPosting(
        wallet: $wallet,
        type: WalletTransactionType::Purchase,
        direction: WalletTransactionDirection::Debit,
        amount: '10.00',
        idempotencyKey: 'purchase-debt-forge',
        minimumAllowedBalance: '-99999999.99',
        allowClawbackDebt: false,
    )))->toThrow(InsufficientWalletBalanceException::class);

    expect(fn () => app(WalletLedger::class)->post(new WalletPosting(
        wallet: $wallet,
        type: WalletTransactionType::Purchase,
        direction: WalletTransactionDirection::Debit,
        amount: '10.00',
        idempotencyKey: 'purchase-debt-forge-flag',
        minimumAllowedBalance: '-99999999.99',
        allowClawbackDebt: true,
    )))->toThrow(\App\Exceptions\InvalidWalletPostingAmountException::class);
});

it('is idempotent for duplicate obligation creation and processing', function (): void {
    $fixture = clawbackFixture();
    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);

    expect(CommissionClawback::query()->count())->toBe(1);

    app(ApproveRefundRequest::class)->handle($fixture['admin'], $refund->id);
    expect(CommissionClawback::query()->count())->toBe(1);

    $clawback = CommissionClawback::query()->firstOrFail();
    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);
    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);

    expect(WalletTransaction::query()->where('type', WalletTransactionType::CommissionReversal)->count())->toBe(1)
        ->and($clawback->fresh()->status)->toBe(CommissionClawbackStatus::Posted);
});

it('quarantines anomalous original credit without blocking customer refund', function (): void {
    $fixture = clawbackFixture();
    $fixture['commission']->update(['wallet_transaction_id' => null]);

    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);
    expect($refund->status)->toBe(WalletTransaction::STATUS_POSTED);

    $clawback = CommissionClawback::query()->firstOrFail();
    expect($clawback->status)->toBe(CommissionClawbackStatus::NeedsReview);

    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);
    expect($clawback->fresh()->status)->toBe(CommissionClawbackStatus::NeedsReview)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionReversal)->count())->toBe(0);
});

it('updates earnings net and fully reversed presentation', function (): void {
    $fixture = clawbackFixture();
    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);
    $clawback = CommissionClawback::query()->where('refund_wallet_transaction_id', $refund->id)->firstOrFail();
    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);

    $page = app(GetSalespersonEarnings::class)->handle($fixture['salesperson']);

    expect($page->creditedTotal)->toBe('20.00')
        ->and($page->reversedTotal)->toBe('20.00')
        ->and($page->netCreditedTotal)->toBe('0.00')
        ->and($page->items[0]->isFullyReversed)->toBeTrue()
        ->and($page->items[0]->clawbackPublicRef)->toStartWith('CLB-')
        ->and($page->canRequestPayout)->toBeFalse();
});

it('emits financial invalidation after reversal posting', function (): void {
    Event::fake([CustomerFinancialStateChanged::class]);
    $fixture = clawbackFixture();
    $refund = approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);
    Event::fake([CustomerFinancialStateChanged::class]);

    $clawback = CommissionClawback::query()->where('refund_wallet_transaction_id', $refund->id)->firstOrFail();
    app(ProcessCommissionClawback::class)->handle((int) $clawback->id);

    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $fixture['salesperson']->id
            && in_array(CustomerFinancialInvalidationReason::TransactionPosted, $event->reasons, true)
            && in_array(CustomerFinancialInvalidationReason::CommissionStateChanged, $event->reasons, true),
    );
});

it('does not create obligations when policy effective_at is in the future', function (): void {
    config(['billing.commission_clawback.effective_at' => now()->addDay()->toIso8601String()]);
    $fixture = clawbackFixture();

    approveRefundForItem($fixture['admin'], $fixture['item'], $fixture['fulfillment'], $fixture['customer']);

    expect(CommissionClawback::query()->count())->toBe(0);
});
