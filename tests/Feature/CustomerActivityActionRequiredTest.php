<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\FulfillmentFailedNotification;
use App\Notifications\TopupRejectedNotification;
use App\Support\Activity\OrderActionRequiredReader;
use App\Support\Activity\RefundActionRequiredReader;
use App\Support\Activity\TopupActionRequiredReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeRejectedTopup(User $user, float $amount = 25, ?string $note = 'Incomplete proof'): \App\Models\TopupRequest
{
    $wallet = Wallet::forUser($user);
    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => $amount,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $request->update([
        'status' => TopupRequestStatus::Rejected,
        'note' => $note,
    ]);

    return $request->fresh();
}

function makeActivityPackage(): Package
{
    static $sequence = 50_000;
    $sequence++;

    return Package::factory()->create([
        'is_active' => true,
        'order' => $sequence,
        'category_id' => \App\Models\Category::factory()->create(['order' => $sequence])->id,
    ]);
}

/**
 * @return array{order: Order, fulfillment: Fulfillment, item: OrderItem}
 */
function makeFailedOrderForActivity(User $user): array
{
    $package = makeActivityPackage();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 10,
        'is_active' => true,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
        'meta' => ['last_error' => 'INTERNAL_SUPPLIER_TRACE'],
    ]);

    return compact('order', 'fulfillment', 'item');
}

function makeRejectedRefundForFailedFulfillment(User $user, Fulfillment $fulfillment, Order $order): WalletTransaction
{
    $wallet = Wallet::forUser($user);

    return WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_REJECTED,
        'reference_type' => Fulfillment::class,
        'reference_id' => $fulfillment->id,
        'idempotency_key' => 'refund-test-'.Str::uuid(),
        'meta' => [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'fulfillment_id' => $fulfillment->id,
            'currency' => 'USD',
            'rejected_by' => 999,
        ],
        'created_at' => now(),
    ]);
}

it('returns only the current users rejected topups with safe payload', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $mine = makeRejectedTopup($owner, 40, 'Need clearer receipt');
    makeRejectedTopup($other, 99, 'Secret other');

    $items = app(TopupActionRequiredReader::class)->forUser($owner);

    expect($items)->toHaveCount(1)
        ->and($items[0]->requiresAction)->toBeTrue()
        ->and($items[0]->isUnread)->toBeFalse()
        ->and($items[0]->dedupeKey)->toBe('topup:'.$mine->id)
        ->and($items[0]->description)->toContain('Need clearer receipt')
        ->and(json_encode($items[0]->toArray()))->not->toContain('approved_by')
        ->and($items[0]->money?->amount)->toBe('40.00');
});

it('excludes approved and pending topups from action required reader', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 10,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $approved = makeRejectedTopup($user);
    $approved->update(['status' => TopupRequestStatus::Approved, 'note' => null]);

    expect(app(TopupActionRequiredReader::class)->forUser($user))->toHaveCount(0);
});

it('returns rejected refunds only while fulfillment remains failed and scoped to owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $owned = makeFailedOrderForActivity($owner);
    $foreign = makeFailedOrderForActivity($other);

    $tx = makeRejectedRefundForFailedFulfillment($owner, $owned['fulfillment'], $owned['order']);
    makeRejectedRefundForFailedFulfillment($other, $foreign['fulfillment'], $foreign['order']);

    $owned['fulfillment']->update([
        'meta' => array_merge($owned['fulfillment']->meta ?? [], [
            'refund' => ['status' => WalletTransaction::STATUS_REJECTED],
        ]),
    ]);

    $items = app(RefundActionRequiredReader::class)->forUser($owner);

    expect($items)->toHaveCount(1)
        ->and($items[0]->dedupeKey)->toBe('refund:'.$tx->id)
        ->and($items[0]->requiresAction)->toBeTrue()
        ->and(json_encode($items[0]->toArray()))->not->toContain('rejected_by')
        ->and(json_encode($items[0]->toArray()))->not->toContain('999');
});

it('includes needs-attention orders and excludes queued processing', function (): void {
    $user = User::factory()->create();

    $failed = makeFailedOrderForActivity($user);
    $package = makeActivityPackage();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 5, 'is_active' => true]);
    $processing = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 5,
        'fee' => 0,
        'total' => 5,
        'status' => OrderStatus::Paid,
    ]);
    $item = OrderItem::create([
        'order_id' => $processing->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 5,
        'quantity' => 1,
        'line_total' => 5,
        'status' => OrderItemStatus::Pending,
    ]);
    Fulfillment::create([
        'order_id' => $processing->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    $items = app(OrderActionRequiredReader::class)->forUser($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->sourceId)->toBe((string) $failed['fulfillment']->id)
        ->and($items[0]->requiresAction)->toBeTrue()
        ->and(json_encode($items[0]->toArray()))->not->toContain('INTERNAL_SUPPLIER_TRACE');
});

it('collapses multiple actionable fulfillments into one order-level activity item', function (): void {
    $user = User::factory()->create();
    $base = makeFailedOrderForActivity($user);
    $package = makeActivityPackage();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 8, 'is_active' => true]);
    $item2 = OrderItem::create([
        'order_id' => $base['order']->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 8,
        'quantity' => 1,
        'line_total' => 8,
        'status' => OrderItemStatus::Failed,
    ]);
    $second = Fulfillment::create([
        'order_id' => $base['order']->id,
        'order_item_id' => $item2->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
    ]);

    $items = app(OrderActionRequiredReader::class)->forUser($user);

    expect($items)->toHaveCount(1)
        ->and($items[0]->groupKey)->toBe('order:'.$base['order']->id)
        ->and($items[0]->secondaryMeta['related_dedupe_keys'] ?? '')
        ->toContain('fulfillment:'.$base['fulfillment']->id)
        ->toContain('fulfillment:'.$second->id);
});

it('dedupes matching topup notification against action item and keeps unread count truthful', function (): void {
    $user = User::factory()->create();
    $topup = makeRejectedTopup($user);

    $notification = DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupRejectedNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => $topup->id,
            'title' => 'Rejected notice',
            'message' => 'Historical',
            'url' => 'https://evil.example/wallet',
        ],
        'read_at' => null,
    ]);

    $result = app(GetCustomerActivity::class)->handle($user, filter: 'all');

    expect($result->actionRequiredSummary)->toHaveCount(1)
        ->and($result->actionRequiredSummary[0]->dedupeKey)->toBe('topup:'.$topup->id)
        ->and($result->actionRequiredSummary[0]->isUnread)->toBeFalse()
        ->and($result->actionRequiredSummary[0]->secondaryMeta['twin_notification_id'] ?? null)->toBe($notification->id)
        ->and(collect($result->items)->pluck('title'))->not->toContain('Rejected notice')
        ->and($result->unreadCount)->toBe(1);

    $notification->markAsRead();
    $afterRead = app(GetCustomerActivity::class)->handle($user, filter: 'all');

    expect($afterRead->actionRequiredSummary)->toHaveCount(1)
        ->and($afterRead->unreadCount)->toBe(0);
});

it('dedupes fulfillment failed notification with order action item', function (): void {
    $user = User::factory()->create();
    $failed = makeFailedOrderForActivity($user);

    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => FulfillmentFailedNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => [
            'source_type' => 'App\\Models\\Fulfillment',
            'source_id' => $failed['fulfillment']->id,
            'title' => 'Delivery failed notice',
            'message' => 'See order',
            'url' => route('orders.show', $failed['order']->order_number),
        ],
        'read_at' => null,
    ]);

    $result = app(GetCustomerActivity::class)->handle($user);

    expect($result->actionRequiredSummary)->toHaveCount(1)
        ->and(collect($result->items)->pluck('title'))->not->toContain('Delivery failed notice');
});

it('filters action required and excludes action-only from unread', function (): void {
    $user = User::factory()->create();
    makeRejectedTopup($user);

    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\LegacyCustomerNotice',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['title' => 'Unread legacy', 'message' => 'Hi'],
        'read_at' => null,
    ]);

    $action = app(GetCustomerActivity::class)->handle($user, filter: 'action_required');
    $unread = app(GetCustomerActivity::class)->handle($user, filter: 'unread');

    expect($action->items)->toHaveCount(1)
        ->and($action->items[0]->requiresAction)->toBeTrue()
        ->and(collect($unread->items)->pluck('title')->all())->toBe(['Unread legacy'])
        ->and($unread->actionRequiredSummary)->toBe([]);
});

it('shows action required summary on the activity page without duplicating feed cards', function (): void {
    $user = User::factory()->create();
    makeRejectedTopup($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->assertSee(__('messages.activity_action_required_section_title'))
        ->assertSee(__('messages.activity_action_topup_rejected_title'))
        ->assertSee('data-test="activity-action-required-section"', false)
        ->call('setFilter', 'action_required')
        ->assertDontSee('data-test="activity-action-required-section"', false)
        ->assertSee(__('messages.activity_action_topup_rejected_title'));
});

it('caps topup reader results', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 12) as $i) {
        makeRejectedTopup($user, 10 + $i, "Reason {$i}");
    }

    expect(app(TopupActionRequiredReader::class)->forUser($user))
        ->toHaveCount(TopupActionRequiredReader::MAX_ITEMS);
});

it('keeps action required query growth bounded', function (): void {
    $user = User::factory()->create();
    makeRejectedTopup($user);
    makeFailedOrderForActivity($user);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(GetCustomerActivity::class)->handle($user);

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(25);
});
