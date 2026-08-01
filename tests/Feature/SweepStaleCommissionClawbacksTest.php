<?php

declare(strict_types=1);

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
use App\Support\Commissions\CommissionClawbackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{clawback: CommissionClawback, salesperson: User, credit: WalletTransaction, refund: WalletTransaction, commission: Commission}
 */
function staleClawbackFixture(array $overrides = []): array
{
    $salesperson = User::factory()->create();
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => '30.00']);

    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $package = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => 'ORD-STALE-'.uniqid(),
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
        'name' => 'Stale Pack',
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
        'idempotency_key' => 'credit-stale-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHours(3),
    ]);

    $refund = WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($customer)->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '100.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'refund-stale-'.uniqid(),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHours(2),
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'STALEREF',
        'order_total' => 100,
        'commission_amount' => '20.00',
        'commission_rate_percent' => 20,
        'status' => CommissionStatus::Credited,
        'wallet_transaction_id' => $credit->id,
        'paid_at' => now()->subHours(3),
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
        'status' => CommissionClawbackStatus::Processing,
        'policy_version' => 1,
        'idempotency_key' => CommissionClawbackPolicy::idempotencyKey((int) $commission->id, (int) $refund->id),
        'attempted_at' => now()->subHours(2),
    ], $overrides));

    return compact('clawback', 'salesperson', 'credit', 'refund', 'commission');
}

it('recovers stale processing rows and dispatches the existing job', function (): void {
    Queue::fake();
    $fixture = staleClawbackFixture();
    $before = (string) Wallet::forUser($fixture['salesperson'])->fresh()->balance;

    $exit = Artisan::call('commission-clawbacks:sweep-stale', ['--limit' => 50]);

    expect($exit)->toBe(0)
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Pending)
        ->and((string) Wallet::forUser($fixture['salesperson'])->fresh()->balance)->toBe($before);

    Queue::assertPushed(ProcessCommissionClawbackJob::class);
});

it('ignores fresh processing rows', function (): void {
    Queue::fake();
    $fixture = staleClawbackFixture([
        'attempted_at' => now()->subMinutes(2),
    ]);

    Artisan::call('commission-clawbacks:sweep-stale');

    expect($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Processing);
    Queue::assertNothingPushed();
});

it('dry-run reports without mutating', function (): void {
    Queue::fake();
    $fixture = staleClawbackFixture();

    $exit = Artisan::call('commission-clawbacks:sweep-stale', ['--dry-run' => true]);

    expect($exit)->toBe(0)
        ->and($fixture['clawback']->fresh()->status)->toBe(CommissionClawbackStatus::Processing);

    Queue::assertNothingPushed();
});

it('quarantines when an orphaned matching reversal exists', function (): void {
    Queue::fake();
    $fixture = staleClawbackFixture();

    WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($fixture['salesperson'])->id,
        'type' => WalletTransactionType::CommissionReversal,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => '20.00',
        'currency' => 'USD',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => CommissionClawbackPolicy::reversalIdempotencyKey(
            (int) $fixture['commission']->id,
            (int) $fixture['refund']->id,
        ),
        'public_ref' => 'WTX-'.strtoupper(bin2hex(random_bytes(5))),
        'posted_at' => now()->subHour(),
    ]);

    Artisan::call('commission-clawbacks:sweep-stale');

    $fresh = $fixture['clawback']->fresh();
    expect($fresh->status)->toBe(CommissionClawbackStatus::NeedsReview)
        ->and($fresh->failure_code)->toBe('orphaned_reversal')
        ->and($fresh->reversal_wallet_transaction_id)->toBeNull();

    Queue::assertNothingPushed();
});

it('rejects malformed CLB option safely', function (): void {
    $exit = Artisan::call('commission-clawbacks:sweep-stale', ['--clawback' => 'CLB-BAD']);

    expect($exit)->toBe(1);
});

it('is registered on the scheduler', function (): void {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? $event->description ?? '')
        ->implode(' ');

    expect($events)->toContain('commission-clawbacks:sweep-stale');
});
