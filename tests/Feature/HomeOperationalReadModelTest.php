<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\CustomerActivityImportance;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Models\Category;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\TopupApprovedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function homeOpPackage(): Package
{
    static $sequence = 70_000;
    $sequence++;

    return Package::factory()->create([
        'is_active' => true,
        'order' => $sequence,
        'category_id' => Category::factory()->create(['order' => $sequence])->id,
    ]);
}

function homeOpRejectedTopup(User $user, float $amount = 25, ?string $note = 'Incomplete proof'): \App\Models\TopupRequest
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

/**
 * @return array{order: Order, fulfillment: Fulfillment}
 */
function homeOpFailedOrder(User $user): array
{
    $package = homeOpPackage();
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

    return ['order' => $order, 'fulfillment' => $fulfillment];
}

it('returns only requiresAction urgent or attention items for Home Operational', function (): void {
    $user = User::factory()->create();
    homeOpRejectedTopup($user);
    homeOpFailedOrder($user);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'title' => 'Funds added',
            'message' => 'Success only',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);

    $result = app(GetCustomerActivity::class)->forHomeOperational($user);

    expect($result->items)->not->toBeEmpty()
        ->and($result->unreadCount)->toBe(0)
        ->and($result->filter)->toBe('action_required');

    foreach ($result->items as $item) {
        expect($item->requiresAction)->toBeTrue()
            ->and(in_array($item->importance, [
                CustomerActivityImportance::Urgent,
                CustomerActivityImportance::Attention,
            ], true))->toBeTrue();
    }

    $titles = collect($result->items)->pluck('title')->implode(' ');
    expect($titles)->not->toContain('Funds added');
});

it('orders urgent before attention and caps at three with hasMore', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 4) as $i) {
        homeOpRejectedTopup($user, 10 + $i, "Reason {$i}");
    }
    homeOpFailedOrder($user);

    $result = app(GetCustomerActivity::class)->forHomeOperational($user);

    expect($result->items)->toHaveCount(3)
        ->and($result->actionRequiredTotal)->toBeGreaterThanOrEqual(5)
        ->and($result->hasMoreActionRequired)->toBeTrue()
        ->and($result->items[0]->importance)->toBe(CustomerActivityImportance::Urgent);
});

it('scopes Home Operational to the authenticated owner only', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    homeOpRejectedTopup($owner, 40, 'Mine');
    homeOpRejectedTopup($other, 99, 'Secret other');
    homeOpFailedOrder($other);

    $result = app(GetCustomerActivity::class)->forHomeOperational($owner);

    expect($result->actionRequiredTotal)->toBe(1);
    expect(json_encode($result->toArray()))->not->toContain('Secret other')
        ->and(json_encode($result->toArray()))->not->toContain('INTERNAL_SUPPLIER_TRACE');
});

it('does not run notification feed pagination for Home Operational', function (): void {
    $user = User::factory()->create();
    homeOpRejectedTopup($user);

    foreach (range(1, 5) as $i) {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => TopupApprovedNotification::class,
            'data' => [
                'title' => "History {$i}",
                'message' => 'Should not be queried',
                'url' => route('wallet'),
                'trace_id' => (string) Str::uuid(),
            ],
        ]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    app(GetCustomerActivity::class)->forHomeOperational($user);

    $notificationQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'notifications'))
        ->count();
    DB::disableQueryLog();

    expect($notificationQueries)->toBe(0);
});
