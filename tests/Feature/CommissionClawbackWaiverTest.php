<?php

declare(strict_types=1);

use App\Actions\Commissions\ProcessCommissionClawback;
use App\Actions\Commissions\RequestSalespersonPayout;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Actions\Commissions\WaiveCommissionClawback;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionClawbackWaiverReason;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\LedgerMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach ([
        'view_commission_clawbacks',
        'process_commission_clawbacks',
        'waive_commission_clawbacks',
        'view_referrals',
        'adjust_wallets',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
});

/**
 * @return array{actor: User, salesperson: User, clawback: CommissionClawback, wallet: Wallet}
 */
function waiverFixture(array $overrides = [], string $walletBalance = '40.00'): array
{
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => $walletBalance]);

    $actor = User::factory()->create();
    $actor->givePermissionTo([
        'view_commission_clawbacks',
        'process_commission_clawbacks',
        'waive_commission_clawbacks',
    ]);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-WAIVE-'.uniqid(),
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
        'name' => 'Waive Pack',
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
        'idempotency_key' => 'credit-waive-'.uniqid(),
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
        'idempotency_key' => 'refund-waive-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHour(),
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'WAIVEREF',
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
        'idempotency_key' => 'waive-claw-'.uniqid(),
        'failure_code' => 'job_exhausted',
        'failure_message_safe' => 'Queue attempts exhausted.',
        'needs_review_at' => now()->subMinutes(5),
    ], $overrides));

    return compact('actor', 'salesperson', 'clawback', 'wallet');
}

function postReversalForWaiver(array $fixture): CommissionClawback
{
    $clawback = $fixture['clawback'];
    $clawback->forceFill([
        'status' => CommissionClawbackStatus::Pending,
        'failure_code' => null,
        'failure_message_safe' => null,
        'needs_review_at' => null,
    ])->save();

    return app(ProcessCommissionClawback::class)->handle((int) $clawback->id);
}

it('grants waive separately from view and process', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['view_commission_clawbacks', 'process_commission_clawbacks']);
    $fixture = waiverFixture();

    expect(fn () => app(WaiveCommissionClawback::class)->handle(
        $viewer,
        $fixture['clawback'],
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
    ))->toThrow(AuthorizationException::class);
});

it('does not grant waive through adjust_wallets alone', function (): void {
    $adjuster = User::factory()->create();
    $adjuster->givePermissionTo('adjust_wallets');
    $fixture = waiverFixture();

    expect(fn () => app(WaiveCommissionClawback::class)->handle(
        $adjuster,
        $fixture['clawback'],
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
    ))->toThrow(AuthorizationException::class);
});

it('fully waives unposted needs_review without creating a wallet transaction', function (): void {
    $fixture = waiverFixture();
    $before = (string) $fixture['wallet']->fresh()->balance;

    $result = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $fixture['clawback'],
        CommissionClawbackWaiverReason::ManagementDecision->value,
        null,
        'Internal note',
        'token-unposted-full-1',
    );

    expect($result['outcome'])->toBe('waived')
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Waived)
        ->and($result['decision'])->not->toBeNull()
        ->and($result['decision']->related_wallet_transaction_id)->toBeNull()
        ->and((string) $fixture['wallet']->fresh()->balance)->toBe($before)
        ->and($result['decision']->public_ref)->toStartWith('CLD-');

    expect(WalletTransaction::query()
        ->where('type', WalletTransactionType::CommissionClawbackWaiver)
        ->count())->toBe(0);

    Queue::fake();
    $retry = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']->fresh());
    expect($retry['outcome'])->toBe('denied');
    Queue::assertNothingPushed();

    expect(app(ProcessCommissionClawback::class)->handle((int) $fixture['clawback']->id)->status)
        ->toBe(CommissionClawbackStatus::Waived);

    $replay = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $fixture['clawback']->fresh(),
        CommissionClawbackWaiverReason::ManagementDecision->value,
        null,
        null,
        'token-unposted-full-1',
    );
    expect($replay['outcome'])->toBe('replayed')
        ->and(CommissionClawbackDecision::query()->count())->toBe(1);
});

it('posts a typed waiver credit for posted clawbacks and caps cumulative waivers', function (): void {
    $fixture = waiverFixture([], '5.00');
    $posted = postReversalForWaiver($fixture);
    expect($posted->status)->toBe(CommissionClawbackStatus::Posted);

    $before = LedgerMoney::normalize((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance);

    $partial = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackWaiverReason::SalespersonRelief->value,
        '8.00',
        null,
        'token-partial-1',
    );

    expect($partial['outcome'])->toBe('waived')
        ->and($partial['clawback']->status)->toBe(CommissionClawbackStatus::Posted);

    $credit = WalletTransaction::query()->findOrFail($partial['decision']->related_wallet_transaction_id);
    expect($credit->type)->toBe(WalletTransactionType::CommissionClawbackWaiver)
        ->and($credit->direction)->toBe(WalletTransactionDirection::Credit)
        ->and(LedgerMoney::equals((string) $credit->amount, '8.00'))->toBeTrue();

    $afterPartial = LedgerMoney::normalize((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance);
    expect(LedgerMoney::equals($afterPartial, LedgerMoney::add($before, '8.00')))->toBeTrue();

    $over = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '20.00',
        null,
        'token-over-1',
    );
    expect($over['outcome'])->toBe('denied');

    $remainder = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '12.00',
        null,
        'token-remainder-1',
    );

    expect($remainder['outcome'])->toBe('waived')
        ->and($remainder['clawback']->status)->toBe(CommissionClawbackStatus::Waived);

    expect(WalletTransaction::query()
        ->where('type', WalletTransactionType::CommissionClawbackWaiver)
        ->count())->toBe(2);

    $replay = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '12.00',
        null,
        'token-remainder-1',
    );
    expect($replay['outcome'])->toBe('replayed')
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionClawbackWaiver)->count())->toBe(2);

    $originalReversalId = $posted->reversal_wallet_transaction_id;
    expect($posted->fresh()->reversal_wallet_transaction_id)->toBe($originalReversalId);
});

it('keeps payout blocked after partial waiver and unlocks after full waiver clears debt', function (): void {
    $fixture = waiverFixture([], '5.00');
    $posted = postReversalForWaiver($fixture);

    $wallet = Wallet::forUser($fixture['salesperson'])->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '-15.00'))->toBeTrue()
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt($wallet))->toBeTrue();

    expect(app(RequestSalespersonPayout::class)->handle($fixture['salesperson']))->toBe('clawback_debt');

    app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackWaiverReason::OperationalException->value,
        '5.00',
        null,
        'token-debt-partial',
    );

    $wallet = $wallet->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '-10.00'))->toBeTrue()
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt($wallet))->toBeTrue();
    expect(app(RequestSalespersonPayout::class)->handle($fixture['salesperson']))->toBe('clawback_debt');

    app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '15.00',
        null,
        'token-debt-full',
    );

    $wallet = $wallet->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '5.00'))->toBeTrue()
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt($wallet))->toBeFalse();

    // No eligible pending commissions in fixture → below_min, not clawback_debt.
    expect(app(RequestSalespersonPayout::class)->handle($fixture['salesperson']))->toBe('below_min');
});

it('returns money even when wallet recovered positive before waiver', function (): void {
    $fixture = waiverFixture([], '5.00');
    $posted = postReversalForWaiver($fixture);

    $wallet = Wallet::forUser($fixture['salesperson'])->fresh();
    expect(LedgerMoney::equals((string) $wallet->balance, '-15.00'))->toBeTrue();

    $wallet->update(['balance' => '3.00']);

    app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '5.00',
        null,
        'token-recovered-positive',
    );

    expect(LedgerMoney::equals((string) $wallet->fresh()->balance, '8.00'))->toBeTrue();
});

it('unposted waiver ignores requested amount and applies full obligation only', function (): void {
    $fixture = waiverFixture([
        'status' => CommissionClawbackStatus::Pending,
        'failure_code' => null,
        'failure_message_safe' => null,
        'needs_review_at' => null,
    ]);

    $result = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $fixture['clawback'],
        CommissionClawbackWaiverReason::OtherApproved->value,
        '5.00',
        null,
        'token-unposted-amount-ignored',
    );

    expect($result['outcome'])->toBe('waived')
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Waived)
        ->and(LedgerMoney::equals((string) $result['decision']->amount, '20.00'))->toBeTrue()
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionClawbackWaiver)->count())->toBe(0);

    $fixture['clawback']->forceFill([
        'status' => CommissionClawbackStatus::Waived,
        'attempted_at' => now()->subHours(3),
    ])->save();

    Queue::fake();
    Artisan::call('commission-clawbacks:sweep-stale');
    Queue::assertNothingPushed();
});

it('rejects invalid reason and forged over-max amount', function (): void {
    $fixture = waiverFixture([], '5.00');
    $posted = postReversalForWaiver($fixture);

    $badReason = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        'platform_error_not_correction',
        '1.00',
        null,
        'token-bad-reason',
    );
    expect($badReason['outcome'])->toBe('denied');

    $over = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '999.00',
        null,
        'token-forged-max',
    );
    expect($over['outcome'])->toBe('denied')
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionClawbackWaiver)->count())->toBe(0);
});
