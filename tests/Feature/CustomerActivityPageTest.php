<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\User;
use App\Notifications\TopupApprovedNotification;
use App\Notifications\TopupRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedActivityNotification(
    User $user,
    string $type,
    array $data = [],
    ?string $readAt = null,
    ?string $id = null,
): DatabaseNotification {
    return DatabaseNotification::query()->create([
        'id' => $id ?? (string) Str::uuid(),
        'type' => $type,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => array_merge([
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => 'Sample title',
            'message' => 'Sample message',
            'url' => 'https://evil.example/admin/secret',
            'trace_id' => (string) Str::uuid(),
            'supplier_id' => 'SUP-HIDDEN',
            'automation_uuid' => 'auto-hidden',
        ], $data),
        'read_at' => $readAt,
    ]);
}

it('shows the activity page for authenticated users with storefront work contract', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-test="activity-page"', false)
        ->assertSee('data-storefront-page="work"', false)
        ->assertSee(__('messages.activity_page_title'))
        ->assertSee(__('messages.activity_page_intro'))
        ->assertSee('data-test="activity-filters"', false)
        ->assertSee('data-test="back-button"', false);
});

it('keeps notifications route as a compatibility alias', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('data-test="activity-page"', false);
});

it('renders supported notifications from presenter data and excludes admin rows', function (): void {
    $user = User::factory()->create();

    seedActivityNotification($user, TopupApprovedNotification::class, [
        'title' => 'Top-up approved',
        'message' => 'Funds added',
        'url' => route('wallet'),
    ]);
    seedActivityNotification($user, TopupRequestedNotification::class, [
        'title' => 'Admin secret',
        'message' => 'Should not render',
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->assertSee('Top-up approved')
        ->assertSee('Funds added')
        ->assertSee(__('messages.activity_action_view_wallet'))
        ->assertSeeHtml('href="'.e(route('wallet')).'"')
        ->assertDontSee('Admin secret')
        ->assertDontSee('SUP-HIDDEN')
        ->assertDontSee('auto-hidden');
});

it('renders unknown historical notifications safely', function (): void {
    $user = User::factory()->create();

    seedActivityNotification($user, 'App\\Notifications\\LegacyCustomerNotice', [
        'title' => '',
        'message' => 'Legacy body only',
        'url' => 'https://evil.example/hack',
    ]);

    $html = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->assertSee(__('messages.activity_fallback_title'))
        ->assertSee('Legacy body only')
        ->html();

    expect($html)->toContain('data-test="activity-item"')
        ->and($html)->toContain(route('activity.index'))
        ->and(preg_match('/data-test="activity-item"[\s\S]*?evil\.example/', $html))->toBe(0);
});

it('shows empty states for all unread and filtered categories', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee('data-empty="all"', false)
        ->assertSee(__('messages.activity_empty_title'));

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->call('setFilter', 'unread')
        ->assertSee(__('messages.activity_empty_unread_title'))
        ->call('setFilter', 'all')
        ->call('setCategory', 'orders')
        ->assertSee(__('messages.activity_empty_category_orders'));
});

it('filters all unread and categories and resets page on change', function (): void {
    $user = User::factory()->create();

    seedActivityNotification($user, TopupApprovedNotification::class, [
        'title' => 'Money row',
        'url' => route('wallet'),
    ], readAt: null);

    Livewire::actingAs($user)
        ->withQueryParams(['page' => 2])
        ->test('pages::frontend.activity')
        ->set('paginators.page', 2)
        ->call('setFilter', 'unread')
        ->assertSet('filter', 'unread')
        ->assertSet('paginators.page', 1)
        ->assertSee('Money row')
        ->call('setCategory', 'money')
        ->assertSet('category', 'money')
        ->assertSet('paginators.page', 1)
        ->assertSee('Money row')
        ->assertSee('aria-pressed="true"', false);
});

it('does not mark notifications read on page open or bell open', function (): void {
    $user = User::factory()->create();
    $notification = seedActivityNotification($user, TopupApprovedNotification::class, [
        'title' => 'Still unread',
        'url' => route('wallet'),
    ]);

    $this->actingAs($user)->get(route('activity.index'))->assertOk();
    expect($notification->fresh()->read_at)->toBeNull();

    Livewire::actingAs($user)
        ->test(\App\Livewire\NotificationBellDropdown::class)
        ->assertSet('unreadCount', 1);

    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks owned notification read and ignores foreign or crafted ids', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $owned = seedActivityNotification($owner, TopupApprovedNotification::class, [
        'title' => 'Owned',
        'url' => route('wallet'),
    ]);
    $foreign = seedActivityNotification($other, TopupApprovedNotification::class, [
        'title' => 'Foreign',
        'url' => route('wallet'),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::frontend.activity')
        ->call('markAsRead', $owned->id)
        ->call('markAsRead', $foreign->id)
        ->call('markAsRead', 'not-a-real-id');

    expect($owned->fresh()->read_at)->not->toBeNull()
        ->and($foreign->fresh()->read_at)->toBeNull()
        ->and($owner->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks all read for the current user only with explicit action', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    seedActivityNotification($owner, TopupApprovedNotification::class, ['title' => 'A', 'url' => route('wallet')]);
    seedActivityNotification($owner, TopupApprovedNotification::class, ['title' => 'B', 'url' => route('wallet')]);
    $foreign = seedActivityNotification($other, TopupApprovedNotification::class, ['title' => 'C', 'url' => route('wallet')]);

    Livewire::actingAs($owner)
        ->test('pages::frontend.activity')
        ->assertSee('data-test="activity-mark-all-read"', false)
        ->call('markAllAsRead')
        ->assertDontSee('data-test="activity-mark-all-read"', false);

    expect($owner->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($foreign->fresh()->read_at)->toBeNull();
});

it('does not allow another users order destination to bypass ownership', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $order = Order::create([
        'user_id' => $owner->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => \App\Enums\OrderStatus::Paid,
    ]);

    $this->actingAs($intruder)
        ->get(route('orders.show', $order->order_number))
        ->assertForbidden();
});

it('keeps activity feed query count from growing per item', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 8) as $i) {
        seedActivityNotification($user, TopupApprovedNotification::class, [
            'title' => "Row {$i}",
            'url' => route('wallet'),
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)->get(route('activity.index'))->assertOk();

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries->count())->toBeLessThan(40);
});

it('exposes unread textual meaning and translated arabic labels', function (): void {
    $user = User::factory()->create(['locale' => 'ar']);

    seedActivityNotification($user, TopupApprovedNotification::class, [
        'title' => 'Unread item',
        'url' => route('wallet'),
    ]);

    app()->setLocale('ar');

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertSee(__('messages.activity_page_title'))
        ->assertSee(__('messages.unread'))
        ->assertSee('data-test="activity-item-unread"', false)
        ->assertSee('data-unread="true"', false);
});
