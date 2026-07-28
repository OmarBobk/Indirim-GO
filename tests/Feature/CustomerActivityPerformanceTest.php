<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Livewire\NotificationBellDropdown;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Notifications\TopupApprovedNotification;
use App\Support\Activity\OrderActionRequiredReader;
use App\Support\Activity\RefundActionRequiredReader;
use App\Support\Activity\TopupActionRequiredReader;
use App\Support\CustomerActivityPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

function seedPerfNotification(User $user, string $title = 'Item'): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => $title,
            'message' => 'Message',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);
}

function bindGetCustomerActivityCounter(int &$calls): void
{
    app()->bind(GetCustomerActivity::class, function () use (&$calls) {
        $calls++;

        return new GetCustomerActivity(
            app(\App\Support\Activity\NotificationActivityReader::class),
            app(\App\Support\Activity\TopupActionRequiredReader::class),
            app(\App\Support\Activity\OrderActionRequiredReader::class),
            app(\App\Support\Activity\RefundActionRequiredReader::class),
            app(\App\Support\Activity\CustomerActivityMerger::class),
        );
    });
}

it('invokes GetCustomerActivity once on a valid Activity mount', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user);
    $calls = 0;
    bindGetCustomerActivityCounter($calls);

    Livewire::actingAs($user)->test('pages::frontend.activity');

    expect($calls)->toBe(1);
});

it('invokes GetCustomerActivity once on page-1 invalidation', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user, 'Before');
    $calls = 0;
    bindGetCustomerActivityCounter($calls);

    $component = Livewire::actingAs($user)->test('pages::frontend.activity');
    $calls = 0;

    seedPerfNotification($user, 'After');
    $component->dispatch('customer-activity-invalidate', isReconcile: false, source: 'notification');

    expect($calls)->toBe(1);
});

it('invokes GetCustomerActivity zero times on page-2 invalidation', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 6) as $i) {
        seedPerfNotification($user, "N{$i}");
    }

    $calls = 0;
    bindGetCustomerActivityCounter($calls);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2);

    $calls = 0;
    $topup = 0;
    $refund = 0;
    $order = 0;

    app()->bind(TopupActionRequiredReader::class, function () use (&$topup) {
        $topup++;

        return new TopupActionRequiredReader;
    });
    app()->bind(RefundActionRequiredReader::class, function () use (&$refund) {
        $refund++;

        return new RefundActionRequiredReader;
    });
    app()->bind(OrderActionRequiredReader::class, function () use (&$order) {
        $order++;

        return new OrderActionRequiredReader(app(\App\Support\CustomerOrderFulfillmentClassifier::class));
    });

    $component
        ->dispatch('customer-activity-invalidate', isReconcile: false, source: 'notification')
        ->assertSet('hasPendingRefresh', true);

    expect($calls)->toBe(0)
        ->and($topup)->toBe(0)
        ->and($refund)->toBe(0)
        ->and($order)->toBe(0);
});

it('clears pending banner when navigating to page one', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 6) as $i) {
        seedPerfNotification($user, "N{$i}");
    }

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2)
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', true)
        ->call('gotoPage', 1)
        ->assertSet('hasPendingRefresh', false);
});

it('clears pending banner when filters change', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 6) as $i) {
        seedPerfNotification($user, "N{$i}");
    }

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2)
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', true)
        ->call('setFilter', 'unread')
        ->assertSet('hasPendingRefresh', false);
});

it('applies pending refresh with a single fresh GetCustomerActivity', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 6) as $i) {
        seedPerfNotification($user, "N{$i}");
    }

    $calls = 0;
    bindGetCustomerActivityCounter($calls);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2)
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', true);

    $calls = 0;
    seedPerfNotification($user, 'Brand new');
    $component->call('applyPendingRefresh')
        ->assertSet('hasPendingRefresh', false)
        ->assertSee('Brand new');

    expect($calls)->toBe(1);
});

it('queries website_settings at most once while presenting many activity items', function (): void {
    $user = User::factory()->create();
    WebsiteSetting::forgetRequestInstance();
    WebsiteSetting::instance()->forceFill(['prices_visible' => true])->save();
    WebsiteSetting::forgetRequestInstance();

    $result = app(GetCustomerActivity::class)->handle($user);
    // Build DTOs with money via action-required path is optional; call presenter repeatedly.
    $items = [];
    for ($i = 0; $i < 15; $i++) {
        seedPerfNotification($user, "Row {$i}");
    }
    $result = app(GetCustomerActivity::class)->handle($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    WebsiteSetting::forgetRequestInstance();

    app(CustomerActivityPresenter::class)->presentMany($result->items, $user);

    $settingsQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'website_settings'))
        ->count();
    DB::disableQueryLog();

    expect($settingsQueries)->toBeLessThanOrEqual(1);
});

it('reuses memoized website settings within a request and refreshes on the next request', function (): void {
    WebsiteSetting::forgetRequestInstance();
    $first = WebsiteSetting::instance();
    $first->forceFill(['prices_visible' => true])->save();
    WebsiteSetting::forgetRequestInstance();

    $a = WebsiteSetting::instance();
    $b = WebsiteSetting::instance();
    expect($a)->toBe($b);

    DB::enableQueryLog();
    DB::flushQueryLog();
    WebsiteSetting::instance();
    WebsiteSetting::instance();
    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'website_settings'))
        ->count();
    DB::disableQueryLog();
    expect($queries)->toBe(0);

    WebsiteSetting::forgetRequestInstance();
    $a->forceFill(['prices_visible' => false])->save();
    WebsiteSetting::forgetRequestInstance();

    expect(WebsiteSetting::instance()->prices_visible)->toBeFalse();
});

it('recounts unread once on mark-one via nested coordinator ownership', function (): void {
    $user = User::factory()->create();
    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => 'Owned',
            'message' => 'Message',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);

    // Activity page layout embeds the coordinator; markAsRead dispatches once to it.
    $activity = Livewire::actingAs($user)->test('pages::frontend.activity');
    $bell = Livewire::actingAs($user)->test(NotificationBellDropdown::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $activity->call('markAsRead', $notification->id);

    $unreadCountQueries = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'read_at');
        })
        ->count();
    DB::disableQueryLog();

    expect($unreadCountQueries)->toBe(1);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $bell->dispatch('customer-notifications-changed');
    $bell->dispatch('customer-unread-count-updated', count: 0)
        ->assertSet('unreadCount', 0);

    $bellUnreadCounts = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'read_at');
        })
        ->count();
    DB::disableQueryLog();

    expect($bellUnreadCounts)->toBe(0);
});

it('applies coordinator unread count on Activity without a second recount', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user);

    $activity = Livewire::actingAs($user)->test('pages::frontend.activity');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $activity->dispatch('customer-unread-count-updated', count: 0)
        ->assertSet('unreadCount', 0);

    $unreadCountQueries = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'read_at');
        })
        ->count();
    DB::disableQueryLog();

    expect($unreadCountQueries)->toBe(0);
});

it('recounts unread once on mark-all via nested coordinator ownership', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user, 'A');
    seedPerfNotification($user, 'B');

    $activity = Livewire::actingAs($user)->test('pages::frontend.activity');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $activity->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    $unreadCountQueries = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'read_at');
        })
        ->count();
    DB::disableQueryLog();

    // Nested layout coordinator recounts once after the mark-all dispatch.
    expect($unreadCountQueries)->toBe(1);
});

it('does not query latest-five when closed bell receives invalidation', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user);

    $bell = Livewire::actingAs($user)->test(NotificationBellDropdown::class);
    expect($bell->get('listLoaded'))->toBeFalse();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $bell->dispatch('customer-activity-invalidate', source: 'notification', isReconcile: false);

    $latestQueries = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && ! str_contains($sql, 'count(*)')
                && (str_contains($sql, 'limit 5') || str_contains($sql, 'limit ?'));
        })
        ->count();
    DB::disableQueryLog();

    expect($latestQueries)->toBe(0);
});

it('loads latest-five once when the bell list is opened', function (): void {
    $user = User::factory()->create();
    seedPerfNotification($user, 'Bell row');

    $bell = Livewire::actingAs($user)->test(NotificationBellDropdown::class);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $bell->call('ensureListLoaded')
        ->assertSet('listLoaded', true)
        ->assertSee('Bell row');

    $latestQueries = collect(DB::getQueryLog())
        ->filter(function (array $q): bool {
            $sql = strtolower($q['query']);

            return str_contains($sql, 'notifications')
                && ! str_contains($sql, 'count(*)')
                && (str_contains($sql, 'limit 5') || str_contains($sql, 'limit ?'));
        })
        ->count();
    DB::disableQueryLog();

    expect($latestQueries)->toBe(1);
});

it('ensures fulfillments order indexes exist', function (): void {
    expect(Schema::hasTable('fulfillments'))->toBeTrue();

    $driver = DB::getDriverName();

    if ($driver === 'mysql') {
        $orderId = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1 LIMIT 1',
            ['fulfillments', 'order_id']
        );
        $orderItemId = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? AND seq_in_index = 1 LIMIT 1',
            ['fulfillments', 'order_item_id']
        );

        expect($orderId)->not->toBeNull()
            ->and($orderItemId)->not->toBeNull();

        return;
    }

    expect(Schema::hasColumn('fulfillments', 'order_id'))->toBeTrue()
        ->and(Schema::hasColumn('fulfillments', 'order_item_id'))->toBeTrue();
});
