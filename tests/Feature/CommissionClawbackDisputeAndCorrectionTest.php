<?php

declare(strict_types=1);

use App\Actions\Commissions\CorrectCommissionClawback;
use App\Actions\Commissions\OpenCommissionClawbackDispute;
use App\Actions\Commissions\ProcessCommissionClawback;
use App\Actions\Commissions\RequestSalespersonPayout;
use App\Actions\Commissions\ResolveCommissionClawbackDispute;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Actions\Commissions\WaiveCommissionClawback;
use App\Enums\CommissionClawbackCorrectionReason;
use App\Enums\CommissionClawbackDisputeReason;
use App\Enums\CommissionClawbackDisputeResolution;
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
use App\Support\Commissions\CommissionClawbackDisputeState;
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
        'manage_commission_clawback_disputes',
        'correct_commission_clawbacks',
        'view_referrals',
        'adjust_wallets',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
});

/**
 * @return array{actor: User, salesperson: User, clawback: CommissionClawback, wallet: Wallet}
 */
function disputeFixture(array $overrides = [], string $walletBalance = '40.00'): array
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
        'manage_commission_clawback_disputes',
        'correct_commission_clawbacks',
    ]);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-DISP-'.uniqid(),
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
        'name' => 'Dispute Pack',
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
        'idempotency_key' => 'credit-disp-'.uniqid(),
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
        'idempotency_key' => 'refund-disp-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHour(),
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'DISPREF',
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
        'status' => CommissionClawbackStatus::Pending,
        'policy_version' => 1,
        'idempotency_key' => 'disp-claw-'.uniqid(),
    ], $overrides));

    return compact('actor', 'salesperson', 'clawback', 'wallet');
}

function postReversalForDispute(array $fixture): CommissionClawback
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

it('separates dispute and correction permissions from view process and waive', function (): void {
    $fixture = disputeFixture();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo([
        'view_commission_clawbacks',
        'process_commission_clawbacks',
        'waive_commission_clawbacks',
    ]);

    expect(fn () => app(OpenCommissionClawbackDispute::class)->handle(
        $viewer,
        $fixture['clawback'],
        CommissionClawbackDisputeReason::OperationalReview->value,
    ))->toThrow(AuthorizationException::class);

    $disputer = User::factory()->create();
    $disputer->givePermissionTo('manage_commission_clawback_disputes');
    $posted = postReversalForDispute($fixture);

    expect(fn () => app(CorrectCommissionClawback::class)->handle(
        $disputer,
        $posted,
        CommissionClawbackCorrectionReason::ExcessiveReversal->value,
        '5.00',
    ))->toThrow(AuthorizationException::class);

    $adjuster = User::factory()->create();
    $adjuster->givePermissionTo('adjust_wallets');
    expect(fn () => app(CorrectCommissionClawback::class)->handle(
        $adjuster,
        $posted,
        CommissionClawbackCorrectionReason::SoftwareErrorConfirmed->value,
    ))->toThrow(AuthorizationException::class);
});

it('opens an unposted dispute that pauses processor retry and sweeper', function (): void {
    $fixture = disputeFixture();

    $opened = app(OpenCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback'],
        CommissionClawbackDisputeReason::OperationalReview->value,
        'Internal only',
        'token-dispute-open-1',
    );

    expect($opened['outcome'])->toBe('opened')
        ->and($opened['decision']->public_ref)->toStartWith('CLD-')
        ->and((new CommissionClawbackDisputeState)->hasActiveDispute($fixture['clawback']->fresh()))->toBeTrue();

    expect(app(ProcessCommissionClawback::class)->handle((int) $fixture['clawback']->id)->status)
        ->toBe(CommissionClawbackStatus::Pending);

    Queue::fake();
    $retry = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']->fresh());
    expect($retry['outcome'])->toBe('denied');
    Queue::assertNothingPushed();

    $fixture['clawback']->forceFill([
        'status' => CommissionClawbackStatus::Processing,
        'attempted_at' => now()->subHours(3),
    ])->save();

    Artisan::call('commission-clawbacks:sweep-stale');
    Queue::assertNothingPushed();

    $dup = app(OpenCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback']->fresh(),
        CommissionClawbackDisputeReason::OtherReviewed->value,
        null,
        'token-dispute-open-2',
    );
    expect($dup['outcome'])->toBe('denied');
});

it('rejects a dispute and redispatches unposted pending processing', function (): void {
    $fixture = disputeFixture();
    app(OpenCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback'],
        CommissionClawbackDisputeReason::CommissionAmountQuestioned->value,
        null,
        'token-reject-open',
    );

    Queue::fake();
    $resolved = app(ResolveCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback']->fresh(),
        CommissionClawbackDisputeResolution::Rejected->value,
        null,
        'Reviewed — no change',
        null,
        null,
        'token-reject-resolve',
    );

    expect($resolved['outcome'])->toBe('resolved')
        ->and((new CommissionClawbackDisputeState)->hasActiveDispute($fixture['clawback']->fresh()))->toBeFalse()
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Pending);

    Queue::assertPushed(\App\Jobs\ProcessCommissionClawbackJob::class);
});

it('posts a typed correction credit and shares the cumulative cap with waivers', function (): void {
    $fixture = disputeFixture([], '5.00');
    $posted = postReversalForDispute($fixture);
    expect($posted->status)->toBe(CommissionClawbackStatus::Posted);

    $before = LedgerMoney::normalize((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance);

    $partial = app(CorrectCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackCorrectionReason::ExcessiveReversal->value,
        '8.00',
        null,
        'token-correct-partial',
    );

    expect($partial['outcome'])->toBe('corrected')
        ->and($partial['clawback']->status)->toBe(CommissionClawbackStatus::Posted);

    $credit = WalletTransaction::query()->findOrFail($partial['decision']->related_wallet_transaction_id);
    expect($credit->type)->toBe(WalletTransactionType::CommissionReversalCorrection)
        ->and($credit->direction)->toBe(WalletTransactionDirection::Credit)
        ->and(LedgerMoney::equals((string) $credit->amount, '8.00'))->toBeTrue();

    expect(LedgerMoney::equals(
        (string) Wallet::forUser($fixture['salesperson'])->fresh()->balance,
        LedgerMoney::add($before, '8.00'),
    ))->toBeTrue();

    $waiver = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '15.00',
        null,
        'token-waiver-after-correct',
    );
    expect($waiver['outcome'])->toBe('denied');

    $waiverOk = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackWaiverReason::CommercialGoodwill->value,
        '12.00',
        null,
        'token-waiver-cap-ok',
    );
    expect($waiverOk['outcome'])->toBe('waived');

    $over = app(CorrectCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackCorrectionReason::SoftwareErrorConfirmed->value,
        '1.00',
        null,
        'token-correct-over',
    );
    expect($over['outcome'])->toBe('denied');

    expect($posted->fresh()->reversal_wallet_transaction_id)->toBe($posted->reversal_wallet_transaction_id)
        ->and($posted->fresh()->status)->not->toBe(CommissionClawbackStatus::Waived);
});

it('accepts a dispute as correction and never moves money on open', function (): void {
    $fixture = disputeFixture([], '5.00');
    $posted = postReversalForDispute($fixture);
    $balanceBefore = (string) Wallet::forUser($fixture['salesperson'])->fresh()->balance;

    app(OpenCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackDisputeReason::ReversalAmountQuestioned->value,
        null,
        'token-posted-dispute',
    );

    expect((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance)->toBe($balanceBefore);

    $resolved = app(ResolveCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackDisputeResolution::AcceptedAsCorrection->value,
        null,
        'Correction applied',
        CommissionClawbackCorrectionReason::ExcessiveReversal->value,
        '20.00',
        'token-accept-correction',
    );

    expect($resolved['outcome'])->toBe('resolved')
        ->and($resolved['financial_decision'])->not->toBeNull()
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionReversalCorrection)->count())->toBe(1)
        ->and((new CommissionClawbackDisputeState)->hasActiveDispute($posted->fresh()))->toBeFalse()
        ->and((new SalespersonClawbackDebt)->hasOutstandingDebt(Wallet::forUser($fixture['salesperson'])->fresh()))->toBeFalse();

    expect(app(RequestSalespersonPayout::class)->handle($fixture['salesperson']))->toBe('below_min');
});

it('accepts a dispute as waiver and blocks direct waive while disputed', function (): void {
    $fixture = disputeFixture([
        'status' => CommissionClawbackStatus::NeedsReview,
        'failure_code' => 'job_exhausted',
        'failure_message_safe' => 'exhausted',
        'needs_review_at' => now(),
    ]);

    app(OpenCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback'],
        CommissionClawbackDisputeReason::SalespersonResponsibilityReview->value,
        null,
        'token-unposted-dispute-waive',
    );

    $direct = app(WaiveCommissionClawback::class)->handle(
        $fixture['actor'],
        $fixture['clawback']->fresh(),
        CommissionClawbackWaiverReason::ManagementDecision->value,
        null,
        null,
        'token-direct-waive-blocked',
    );
    expect($direct['outcome'])->toBe('denied');

    $resolved = app(ResolveCommissionClawbackDispute::class)->handle(
        $fixture['actor'],
        $fixture['clawback']->fresh(),
        CommissionClawbackDisputeResolution::AcceptedAsWaiver->value,
        null,
        null,
        CommissionClawbackWaiverReason::ManagementDecision->value,
        null,
        'token-accept-waiver',
    );

    expect($resolved['outcome'])->toBe('resolved')
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Waived)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionClawbackWaiver)->count())->toBe(0)
        ->and(CommissionClawbackDecision::query()->where('type', \App\Enums\CommissionClawbackDecisionType::DisputeResolved)->count())->toBe(1);
});

it('replays dispute open and correction actions idempotently', function (): void {
    $fixture = disputeFixture([], '5.00');
    $posted = postReversalForDispute($fixture);

    $first = app(CorrectCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted,
        CommissionClawbackCorrectionReason::WrongCommission->value,
        '5.00',
        null,
        'token-correct-idem',
    );
    $second = app(CorrectCommissionClawback::class)->handle(
        $fixture['actor'],
        $posted->fresh(),
        CommissionClawbackCorrectionReason::WrongCommission->value,
        '5.00',
        null,
        'token-correct-idem',
    );

    expect($first['outcome'])->toBe('corrected')
        ->and($second['outcome'])->toBe('replayed')
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::CommissionReversalCorrection)->count())->toBe(1);
});
