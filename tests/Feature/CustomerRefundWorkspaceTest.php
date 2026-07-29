<?php

declare(strict_types=1);

use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\Actions\Refunds\GetCustomerRefundDetail;
use App\Actions\Refunds\GetCustomerRefunds;
use App\Actions\Refunds\RejectRefundRequest;
use App\DTOs\Refunds\CustomerRefundFilters;
use App\Enums\CustomerRefundStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

/**
 * @return array{order: Order, item: OrderItem, fulfillment: Fulfillment, tx: WalletTransaction}
 */
function makeCustomerRefundFixture(User $user, FulfillmentStatus $status = FulfillmentStatus::Failed): array
{
    static $sequence = 80_000;
    $sequence++;

    $package = Package::factory()->create([
        'order' => $sequence,
        'category_id' => \App\Models\Category::factory()->create(['order' => $sequence])->id,
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 20,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 20,
        'fee' => 0,
        'total' => 20,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 20,
        'quantity' => 1,
        'line_total' => 20,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => $status,
        'attempts' => 0,
        'last_error' => 'Provider error',
    ]);

    Wallet::forUser($user);
    $tx = app(RefundOrderItem::class)->handle($fulfillment, $user->id);

    return compact('order', 'item', 'fulfillment', 'tx');
}

it('lists only the authenticated users refunds with wtx public refs', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerRefundFixture($owner);
    makeCustomerRefundFixture($other);

    $page = app(GetCustomerRefunds::class)->handle($owner, CustomerRefundFilters::fromInput([]));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->publicReference)->toBe($owned['tx']->public_ref)
        ->and(WalletTransactionPublicRef::isValidFormat($page->items[0]->publicReference))->toBeTrue()
        ->and($page->items[0]->status)->toBe(CustomerRefundStatus::UnderReview)
        ->and($page->items[0]->moneyMoved)->toBeFalse();
});

it('filters under review refunded and needs action', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'process_refunds', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $user = User::factory()->create();
    $rejected = makeCustomerRefundFixture($user);
    app(RejectRefundRequest::class)->handle($rejected['tx']->id, $admin->id);

    $postedFixture = makeCustomerRefundFixture($user);
    app(ApproveRefundRequest::class)->handle($admin, $postedFixture['tx']->id);

    $underReview = app(GetCustomerRefunds::class)->handle(
        $user,
        CustomerRefundFilters::fromInput(['filter' => 'under_review'])
    );
    $refunded = app(GetCustomerRefunds::class)->handle(
        $user,
        CustomerRefundFilters::fromInput(['filter' => 'refunded'])
    );
    $needsAction = app(GetCustomerRefunds::class)->handle(
        $user,
        CustomerRefundFilters::fromInput(['filter' => 'needs_action'])
    );

    expect($underReview->items)->toHaveCount(0)
        ->and($refunded->items)->toHaveCount(1)
        ->and($refunded->items[0]->moneyMoved)->toBeTrue()
        ->and($needsAction->items)->toHaveCount(1)
        ->and($needsAction->items[0]->canRecover)->toBeTrue();
});

it('searches by public ref and order number for owned refunds only', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerRefundFixture($owner);
    $foreign = makeCustomerRefundFixture($other);

    $byRef = app(GetCustomerRefunds::class)->handle(
        $owner,
        CustomerRefundFilters::fromInput(['search' => substr((string) $owned['tx']->public_ref, 0, 8)])
    );
    $byOrder = app(GetCustomerRefunds::class)->handle(
        $owner,
        CustomerRefundFilters::fromInput(['search' => $owned['order']->order_number])
    );
    $foreignSearch = app(GetCustomerRefunds::class)->handle(
        $owner,
        CustomerRefundFilters::fromInput(['search' => (string) $foreign['tx']->public_ref])
    );

    expect($byRef->items)->toHaveCount(1)
        ->and($byOrder->items)->toHaveCount(1)
        ->and($foreignSearch->items)->toHaveCount(0);
});

it('loads owned detail and 404s foreign refs', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerRefundFixture($owner);
    $foreign = makeCustomerRefundFixture($other);

    $detail = app(GetCustomerRefundDetail::class)->handle($owner, (string) $owned['tx']->public_ref);
    expect($detail->publicReference)->toBe($owned['tx']->public_ref)
        ->and($detail->moneyMoved)->toBeFalse()
        ->and($detail->orderNumber)->toBe($owned['order']->order_number);

    expect(fn () => app(GetCustomerRefundDetail::class)->handle($owner, (string) $foreign['tx']->public_ref))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('renders refunds index and detail pages', function (): void {
    $user = User::factory()->create();
    $fixture = makeCustomerRefundFixture($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-refunds')
        ->assertSeeHtml('data-test="wallet-refunds-page"')
        ->assertSeeHtml('data-test="financial-nav-refunds"')
        ->assertSee($fixture['tx']->public_ref);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-refund-detail', ['refund' => $fixture['tx']->public_ref])
        ->assertSeeHtml('data-test="wallet-refund-detail-page"')
        ->assertSee(__('messages.refund_status_under_review'))
        ->assertSee(__('messages.refund_actor_waiting_staff'));
});

it('denies cross-user list search and detail', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $fixture = makeCustomerRefundFixture($owner);

    $this->actingAs($intruder)
        ->get(route('wallet.refunds.show', ['refund' => $fixture['tx']->public_ref]))
        ->assertNotFound();

    Livewire::actingAs($intruder)
        ->test('pages::frontend.wallet-refunds')
        ->set('search', (string) $fixture['tx']->public_ref)
        ->assertSeeHtml('data-test="refunds-empty-filtered"')
        ->assertDontSeeHtml('data-test="refund-row"');
});

it('paginates at twenty per page', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'process_refunds', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $user = User::factory()->create();
    Wallet::forUser($user);
    $package = Package::factory()->create(['order' => 90_000]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 10,
    ]);

    foreach (range(1, 21) as $i) {
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
            'attempts' => 0,
            'last_error' => 'fail',
        ]);
        $tx = app(RefundOrderItem::class)->handle($fulfillment, $user->id);
        app(RejectRefundRequest::class)->handle($tx->id, $admin->id);
    }

    $page1 = app(GetCustomerRefunds::class)->handle(
        $user,
        CustomerRefundFilters::fromInput(['page' => 1])
    );

    expect($page1->items)->toHaveCount(20)
        ->and($page1->total)->toBe(21)
        ->and($page1->lastPage)->toBe(2);
});
