<?php

declare(strict_types=1);

use App\Actions\Commissions\CreatePayoutBatch;
use App\Actions\Commissions\RequestSalespersonPayout;
use App\Actions\Earnings\GetSalespersonEarnings;
use App\DTOs\Earnings\SalespersonEarningsFilters;
use App\Enums\CommissionStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Events\CustomerFinancialStateChanged;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use App\Support\LedgerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage_settlements', 'guard_name' => 'web']);
});

function makeSalesperson(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('view_referrals');
    Wallet::forUser($user);

    return $user->fresh();
}

function makeCommissionFor(
    User $salesperson,
    array $overrides = [],
    ?Package $sharedPackage = null,
): Commission {
    $customer = User::factory()->create();
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

    $package = $sharedPackage ?? Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);
    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => 'Earn Pack',
        'unit_price' => 100,
        'quantity' => 1,
        'line_total' => 100,
        'status' => 'pending',
    ]);
    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);

    return Commission::query()->create(array_merge([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'REFTEST',
        'order_total' => 100,
        'commission_amount' => 20,
        'commission_rate_percent' => 20,
        'status' => CommissionStatus::Pending,
    ], $overrides));
}

it('denies normal customers and allows salesperson earnings page', function (): void {
    $customer = User::factory()->create();
    Wallet::forUser($customer);

    $this->actingAs($customer)
        ->get(route('wallet.earnings.index'))
        ->assertForbidden();

    $salesperson = makeSalesperson();

    $this->actingAs($salesperson)
        ->get(route('wallet.earnings.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="wallet-earnings-page"')
        ->assertSeeHtml('data-test="financial-nav-earnings"');
});

it('refreshes relevant earnings changes on page one and ignores unrelated reasons', function (): void {
    $salesperson = makeSalesperson();
    $component = Livewire::actingAs($salesperson)
        ->test('pages::frontend.wallet-earnings');

    $commission = makeCommissionFor($salesperson);
    $orderNumber = (string) $commission->order()->value('order_number');

    $component
        ->dispatch(
            'customer-financial-invalidate',
            reasons: [CustomerFinancialInvalidationReason::TransactionPosted->value],
        )
        ->assertDontSee($orderNumber)
        ->dispatch(
            'customer-financial-invalidate',
            reasons: [CustomerFinancialInvalidationReason::CommissionStateChanged->value],
        )
        ->assertSee($orderNumber);
});

it('keeps earnings page two stable with zero commission reads until return to latest', function (): void {
    $salesperson = makeSalesperson();
    $sharedPackage = Package::factory()->create([
        'order' => ((int) Package::query()->max('order')) + 1,
    ]);

    foreach (range(1, 21) as $index) {
        makeCommissionFor($salesperson, ['commission_amount' => $index], $sharedPackage);
    }

    $component = Livewire::actingAs($salesperson)
        ->test('pages::frontend.wallet-earnings')
        ->call('gotoPage', 2);

    $commissionReads = 0;
    DB::listen(function ($query) use (&$commissionReads): void {
        if (str_contains(strtolower($query->sql), 'commissions')) {
            $commissionReads++;
        }
    });

    $component
        ->dispatch(
            'customer-financial-invalidate',
            reasons: [CustomerFinancialInvalidationReason::PayoutRequestStateChanged->value],
        )
        ->assertSet('hasPendingRefresh', true);

    expect($commissionReads)->toBe(0);

    $component
        ->call('applyPendingRefresh')
        ->assertSet('hasPendingRefresh', false)
        ->assertSet('paginators.page', 1);

    expect($commissionReads)->toBeGreaterThan(0);
});

it('keeps pending out of credited totals and wallet available separate', function (): void {
    $salesperson = makeSalesperson();
    $wallet = Wallet::forUser($salesperson);
    $wallet->update(['balance' => '50.00']);

    makeCommissionFor($salesperson, [
        'commission_amount' => 20,
        'status' => CommissionStatus::Pending,
    ]);
    makeCommissionFor($salesperson, [
        'commission_amount' => 15,
        'status' => CommissionStatus::Failed,
    ]);

    $page = app(GetSalespersonEarnings::class)->handle($salesperson);

    expect($page->pendingTotal)->toBe('20.00')
        ->and($page->creditedTotal)->toBe('0.00')
        ->and($page->failedTotal)->toBe('15.00')
        ->and($page->generatedTotal)->toBe('35.00')
        ->and($page->walletAvailableToSpend)->toBe(LedgerMoney::normalize((string) $wallet->fresh()->availableToSpend()))
        ->and(LedgerMoney::compare($page->walletAvailableToSpend, $page->pendingTotal))->not->toBe(0);
});

it('scopes commissions to salesperson and hides foreign rows', function (): void {
    $a = makeSalesperson();
    $b = makeSalesperson();
    makeCommissionFor($a, ['commission_amount' => 11]);
    makeCommissionFor($b, ['commission_amount' => 22]);

    $page = app(GetSalespersonEarnings::class)->handle($a);

    expect($page->total)->toBe(1)
        ->and($page->items[0]->amount)->toBe('11.00');
});

it('filters credited and links posted wallet transaction', function (): void {
    $salesperson = makeSalesperson();
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $commission = makeCommissionFor($salesperson);
    WebsiteSetting::instance()->update([
        'commission_payout_wait_days' => 0,
        'commission_payout_min_amount' => 0,
    ]);

    app(CreatePayoutBatch::class)->handle($admin, [$commission->id], null, false);

    $commission->refresh();
    expect($commission->status)->toBe(CommissionStatus::Credited)
        ->and($commission->wallet_transaction_id)->not->toBeNull();

    $page = app(GetSalespersonEarnings::class)->handle(
        $salesperson,
        SalespersonEarningsFilters::fromInput(['status' => 'credited'])
    );

    expect($page->total)->toBe(1)
        ->and($page->creditedTotal)->toBe('20.00')
        ->and($page->items[0]->walletTransactionPublicRef)->not->toBeNull()
        ->and($page->items[0]->transactionDestination)->not->toBeNull()
        ->and($page->items[0]->isIntegrityAnomaly)->toBeFalse();

    $tx = WalletTransaction::query()->findOrFail($commission->wallet_transaction_id);
    expect($tx->type)->toBe(WalletTransactionType::CommissionCredit)
        ->and(LedgerMoney::equals((string) $tx->amount, '20.00'))->toBeTrue();
});

it('emits one coalesced financial event per salesperson for a multi-commission batch', function (): void {
    Event::fake([CustomerFinancialStateChanged::class]);
    $salesperson = makeSalesperson();
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');
    $commissions = [
        makeCommissionFor($salesperson),
        makeCommissionFor($salesperson),
    ];
    WebsiteSetting::instance()->update([
        'commission_payout_wait_days' => 0,
        'commission_payout_min_amount' => 0,
    ]);

    app(CreatePayoutBatch::class)->handle(
        $admin,
        array_map(static fn (Commission $commission): int => (int) $commission->id, $commissions),
        null,
        false,
    );

    Event::assertDispatchedTimes(CustomerFinancialStateChanged::class, 1);
    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $salesperson->id
            && $event->reasons === [
                CustomerFinancialInvalidationReason::TransactionPosted,
                CustomerFinancialInvalidationReason::CommissionStateChanged,
            ],
    );
});

it('marks credited without transaction as anomaly', function (): void {
    $salesperson = makeSalesperson();
    makeCommissionFor($salesperson, [
        'status' => CommissionStatus::Credited,
        'paid_at' => now(),
        'wallet_transaction_id' => null,
        'commission_amount' => 9,
    ]);

    $page = app(GetSalespersonEarnings::class)->handle($salesperson);

    expect($page->items[0]->isIntegrityAnomaly)->toBeTrue()
        ->and($page->items[0]->transactionDestination)->toBeNull();
});

it('requests payout with server-derived amount and denies duplicate', function (): void {
    Event::fake([CustomerFinancialStateChanged::class]);
    $salesperson = makeSalesperson();
    WebsiteSetting::instance()->update([
        'commission_payout_wait_days' => 0,
        'commission_payout_min_amount' => 10,
    ]);
    makeCommissionFor($salesperson, ['commission_amount' => 25]);

    $first = app(RequestSalespersonPayout::class)->handle($salesperson);
    $second = app(RequestSalespersonPayout::class)->handle($salesperson);

    expect($first)->toBe('created')
        ->and($second)->toBe('already_pending');
    Event::assertDispatchedTimes(CustomerFinancialStateChanged::class, 1);
    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $salesperson->id
            && $event->reasons === [CustomerFinancialInvalidationReason::PayoutRequestStateChanged],
    );
});

it('rejects payout request below threshold without moving money', function (): void {
    $salesperson = makeSalesperson();
    $before = (string) Wallet::forUser($salesperson)->balance;
    WebsiteSetting::instance()->update([
        'commission_payout_wait_days' => 0,
    ]);
    makeCommissionFor($salesperson, ['commission_amount' => 5]);

    expect(app(RequestSalespersonPayout::class)->handle($salesperson))->toBe('below_min')
        ->and((string) Wallet::forUser($salesperson)->fresh()->balance)->toBe($before);
});

it('hides earnings nav for customers and shows for salespeople on overview', function (): void {
    $customer = User::factory()->create();
    Wallet::forUser($customer);

    Livewire::actingAs($customer)
        ->test('pages::frontend.wallet')
        ->assertDontSeeHtml('data-test="financial-nav-earnings"')
        ->assertDontSeeHtml('data-test="financial-salesperson-link"');

    $salesperson = makeSalesperson();

    Livewire::actingAs($salesperson)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="financial-salesperson-link"')
        ->assertSeeHtml(route('wallet.earnings.index', absolute: false));
});

it('searches by owned order number only', function (): void {
    $salesperson = makeSalesperson();
    $other = makeSalesperson();
    $mine = makeCommissionFor($salesperson);
    $theirs = makeCommissionFor($other);

    $page = app(GetSalespersonEarnings::class)->handle(
        $salesperson,
        SalespersonEarningsFilters::fromInput(['search' => $mine->order->order_number])
    );

    expect($page->total)->toBe(1);

    $foreign = app(GetSalespersonEarnings::class)->handle(
        $salesperson,
        SalespersonEarningsFilters::fromInput(['search' => $theirs->order->order_number])
    );

    expect($foreign->total)->toBe(0);
});

it('does not expose raw metadata or internal ids in presenter payload', function (): void {
    $salesperson = makeSalesperson();
    makeCommissionFor($salesperson);

    $view = app(\App\Support\SalespersonEarningsPresenter::class)->present(
        app(GetSalespersonEarnings::class)->handle($salesperson),
        $salesperson
    );

    $json = json_encode($view);
    expect($json)->not->toContain('App\\Models')
        ->and($json)->not->toContain('salesperson_id')
        ->and($json)->not->toContain('wallet_transaction_id')
        ->and($view['summary']['pending_not_spendable'])->toBe(__('messages.earnings_pending_not_spendable'));
});
