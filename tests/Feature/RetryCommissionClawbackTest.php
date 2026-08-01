<?php

declare(strict_types=1);

use App\Actions\Commissions\ProcessCommissionClawback;
use App\Actions\Commissions\RetryCommissionClawback;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
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
use App\Support\CommissionClawbackPublicRef;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['view_commission_clawbacks', 'process_commission_clawbacks'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
});

/**
 * @return array{actor: User, clawback: CommissionClawback, salesperson: User}
 */
function retryClawbackFixture(array $overrides = []): array
{
    $salesperson = User::factory()->create();
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => '40.00']);

    $actor = User::factory()->create();
    $actor->givePermissionTo(['view_commission_clawbacks', 'process_commission_clawbacks']);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-RETRY-'.uniqid(),
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
        'name' => 'Retry Pack',
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
        'idempotency_key' => 'credit-retry-'.uniqid(),
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
        'idempotency_key' => 'refund-retry-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHour(),
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'RETRYREF',
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
        'idempotency_key' => 'retry-claw-'.uniqid(),
        'failure_code' => 'job_exhausted',
        'failure_message_safe' => 'Queue attempts exhausted.',
        'needs_review_at' => now()->subMinutes(10),
    ], $overrides));

    return compact('actor', 'clawback', 'salesperson');
}

it('queues ProcessCommissionClawbackJob after commit without mutating wallet balance', function (): void {
    Queue::fake();
    $fixture = retryClawbackFixture();
    $before = (string) Wallet::forUser($fixture['salesperson'])->fresh()->balance;

    $result = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']);

    expect($result['outcome'])->toBe('queued')
        ->and($result['clawback']->status)->toBe(CommissionClawbackStatus::Pending)
        ->and($result['clawback']->failure_code)->toBeNull()
        ->and($result['clawback']->retry_count)->toBe(1)
        ->and((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance)->toBe($before);

    Queue::assertPushed(ProcessCommissionClawbackJob::class, fn ($job) => $job->clawbackId === $fixture['clawback']->id);
});

it('denies unauthorized actors independently of view permission', function (): void {
    $fixture = retryClawbackFixture();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view_commission_clawbacks');

    expect(fn () => app(RetryCommissionClawback::class)->handle($viewer, $fixture['clawback']))
        ->toThrow(AuthorizationException::class);

    expect($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::NeedsReview);
});

it('returns already_posted without dispatching when reversal is linked', function (): void {
    Queue::fake();
    $fixture = retryClawbackFixture();

    $reversal = WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($fixture['salesperson'])->id,
        'type' => WalletTransactionType::CommissionReversal,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => '20.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'rev-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now(),
    ]);

    $fixture['clawback']->forceFill([
        'status' => CommissionClawbackStatus::Posted,
        'reversal_wallet_transaction_id' => $reversal->id,
        'posted_at' => now(),
        'failure_code' => null,
    ])->save();

    $result = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']->fresh());

    expect($result['outcome'])->toBe('already_posted');
    Queue::assertNothingPushed();
});

it('denies integrity anomalies without mutating row', function (): void {
    Queue::fake();
    $fixture = retryClawbackFixture([
        'failure_code' => 'missing_original_credit',
    ]);

    $result = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']);

    expect($result['outcome'])->toBe('denied')
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::NeedsReview)
        ->and($fixture['clawback']->fresh()->failure_code)->toBe('missing_original_credit');

    Queue::assertNothingPushed();
});

it('is safe under repeated retry clicks and posts a single reversal when processed', function (): void {
    Queue::fake();
    $fixture = retryClawbackFixture();

    $first = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']);
    $second = app(RetryCommissionClawback::class)->handle($fixture['actor'], $fixture['clawback']->fresh());

    expect($first['outcome'])->toBe('queued')
        ->and($second['outcome'])->toBe('queued')
        ->and($fixture['clawback']->fresh()->retry_count)->toBe(2);

    // Unique job identity collapses duplicate dispatches for the same clawback id.
    Queue::assertPushed(ProcessCommissionClawbackJob::class, 1);

    app(ProcessCommissionClawback::class)->handle((int) $fixture['clawback']->id);
    $posted = $fixture['clawback']->fresh();
    expect($posted->status)->toBe(CommissionClawbackStatus::Posted);

    $replay = app(ProcessCommissionClawback::class)->handle((int) $posted->id);
    expect($replay->reversal_wallet_transaction_id)->toBe($posted->reversal_wallet_transaction_id);

    expect(WalletTransaction::query()
        ->where('type', WalletTransactionType::CommissionReversal)
        ->where('wallet_id', Wallet::forUser($fixture['salesperson'])->id)
        ->count())->toBe(1);
});
