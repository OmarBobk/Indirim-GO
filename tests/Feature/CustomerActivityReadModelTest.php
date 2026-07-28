<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Livewire\NotificationBellDropdown;
use App\Models\User;
use App\Notifications\FulfillmentCompletedNotification;
use App\Notifications\TopupApprovedNotification;
use App\Notifications\TopupRequestedNotification;
use App\Support\Activity\NotificationActivityReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedCustomerNotification(
    User $user,
    string $type,
    array $data = [],
    ?string $createdAt = null,
    ?string $id = null,
    ?string $readAt = null,
): DatabaseNotification {
    $notification = DatabaseNotification::query()->create([
        'id' => $id ?? (string) Str::uuid(),
        'type' => $type,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => array_merge([
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => 'Sample title',
            'message' => 'Sample message',
            'url' => 'https://evil.example/admin/secret?token=abc',
            'trace_id' => (string) Str::uuid(),
            'supplier_id' => 'SUP-HIDDEN',
            'automation_uuid' => 'auto-hidden',
        ], $data),
        'read_at' => $readAt,
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);

    return $notification;
}

it('returns only the supplied users notifications scoped through the notifiable', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    seedCustomerNotification($owner, TopupApprovedNotification::class, [
        'title' => 'Owner topup',
        'url' => route('wallet'),
    ]);
    seedCustomerNotification($other, TopupApprovedNotification::class, [
        'title' => 'Other topup',
        'url' => route('wallet'),
    ]);

    $result = app(GetCustomerActivity::class)->handle($owner);

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->title)->toBe('Owner topup');
});

it('maps supported notifications and preserves unread state', function (): void {
    $user = User::factory()->create();
    seedCustomerNotification($user, TopupApprovedNotification::class, [
        'title' => 'Top-up approved',
        'message' => 'Your wallet was credited',
        'url' => route('wallet'),
    ], readAt: null);

    $item = app(GetCustomerActivity::class)->handle($user)->items[0];

    expect($item->category)->toBe(CustomerActivityCategory::Money)
        ->and($item->importance)->toBe(CustomerActivityImportance::Success)
        ->and($item->statusToken)->toBe(CustomerActivityStatusToken::Success)
        ->and($item->isUnread)->toBeTrue()
        ->and($item->destination->type)->toBe(CustomerActivityDestinationType::Wallet)
        ->and($item->destination->params)->toBe([])
        ->and($item->title)->toBe('Top-up approved')
        ->and($item->description)->toBe('Your wallet was credited');
});

it('orders deterministically by created_at then id and paginates in sql', function (): void {
    $user = User::factory()->create();
    $stamp = '2026-07-01 10:00:00';

    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'A'], $stamp, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');
    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'B'], $stamp, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');
    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'C'], '2026-07-01 11:00:00', 'cccccccc-cccc-cccc-cccc-cccccccccccc');

    $page1 = app(GetCustomerActivity::class)->handle($user, perPage: 2, page: 1);
    $page2 = app(GetCustomerActivity::class)->handle($user, perPage: 2, page: 2);

    expect($page1->items)->toHaveCount(2)
        ->and($page1->items[0]->title)->toBe('C')
        ->and($page1->items[1]->title)->toBe('B')
        ->and($page1->total)->toBe(3)
        ->and($page1->lastPage)->toBe(2)
        ->and($page2->items)->toHaveCount(1)
        ->and($page2->items[0]->title)->toBe('A');
});

it('applies unread and category filters before pagination', function (): void {
    $user = User::factory()->create();

    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'Money unread'], readAt: null);
    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'Money read'], readAt: now()->toDateTimeString());
    seedCustomerNotification($user, FulfillmentCompletedNotification::class, [
        'title' => 'Order done',
        'url' => route('orders.show', ['order' => 'ORD-55']),
        'source_type' => 'App\\Models\\Fulfillment',
    ], readAt: null);

    $unread = app(GetCustomerActivity::class)->handle($user, filter: 'unread', perPage: 10);
    $money = app(GetCustomerActivity::class)->handle($user, category: 'money', perPage: 10);

    expect(collect($unread->items)->pluck('title')->all())
        ->toEqualCanonicalizing(['Money unread', 'Order done'])
        ->and(collect($money->items)->pluck('title')->all())
        ->toEqualCanonicalizing(['Money unread', 'Money read'])
        ->and($money->total)->toBe(2);
});

it('excludes admin notifications and degrades unknown legacy safely without trusting raw urls', function (): void {
    $user = User::factory()->create();

    seedCustomerNotification($user, TopupRequestedNotification::class, [
        'title' => 'Admin only',
        'url' => 'https://evil.example/admin',
    ]);
    seedCustomerNotification($user, 'App\\Notifications\\LegacyCustomerNotice', [
        'title' => '',
        'message' => 'Legacy body',
        'url' => 'https://evil.example/hack',
        'supplier_id' => 'SUP-1',
        'automation_uuid' => 'AUTO-1',
    ]);

    $result = app(GetCustomerActivity::class)->handle($user);

    expect($result->items)->toHaveCount(1);

    $item = $result->items[0];

    expect($item->importance)->toBe(CustomerActivityImportance::Informational)
        ->and($item->statusToken)->toBe(CustomerActivityStatusToken::Neutral)
        ->and($item->title)->toBe(__('messages.activity_fallback_title'))
        ->and($item->description)->toBe('Legacy body')
        ->and($item->destination->type)->toBe(CustomerActivityDestinationType::Activity)
        ->and($item->secondaryMeta)->toBe([])
        ->and(json_encode($item->toArray()))->not->toContain('evil.example')
        ->and(json_encode($item->toArray()))->not->toContain('SUP-1')
        ->and(json_encode($item->toArray()))->not->toContain('AUTO-1');
});

it('builds order destinations from path segment only and ignores authorization from url host', function (): void {
    $user = User::factory()->create();

    seedCustomerNotification($user, FulfillmentCompletedNotification::class, [
        'title' => 'Delivered',
        'message' => 'Ready',
        'url' => 'https://evil.example/orders/ORD-SAFE-1?token=nope',
        'source_type' => 'App\\Models\\Fulfillment',
        'source_id' => 9,
    ]);

    $item = app(GetCustomerActivity::class)->handle($user)->items[0];

    expect($item->destination->type)->toBe(CustomerActivityDestinationType::OrderDetail)
        ->and($item->destination->params)->toBe(['order_number' => 'ORD-SAFE-1']);
});

it('includes unknown legacy notifications when filtering by account category', function (): void {
    $user = User::factory()->create();

    seedCustomerNotification($user, TopupApprovedNotification::class, ['title' => 'Money']);
    seedCustomerNotification($user, 'App\\Notifications\\LegacyCustomerNotice', [
        'title' => 'Legacy account note',
        'message' => 'Hello',
    ]);

    $account = app(GetCustomerActivity::class)->handle($user, category: 'account');

    expect(collect($account->items)->pluck('title')->all())
        ->toBe(['Legacy account note']);
});

it('exposes unread count from notification truth and keeps query growth flat', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        seedCustomerNotification($user, TopupApprovedNotification::class, [
            'title' => "N{$i}",
            'url' => route('wallet'),
        ], readAt: null);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = app(GetCustomerActivity::class)->handle($user, perPage: 15);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($result->unreadCount)->toBe(5)
        ->and($result->items)->toHaveCount(5)
        ->and(count($queries))->toBeLessThanOrEqual(8);
});

it('keeps notifications page and bell mark behaviour unchanged', function (): void {
    $user = User::factory()->create();
    $notification = seedCustomerNotification($user, TopupApprovedNotification::class, [
        'title' => 'Bell item',
        'message' => 'Hello',
        'url' => route('wallet'),
    ]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Bell item')
        ->assertSee('data-test="activity-page"', false);

    Livewire::actingAs($user)
        ->test(NotificationBellDropdown::class)
        ->assertSet('unreadCount', 1)
        ->call('markAsRead', $notification->id)
        ->assertSet('unreadCount', 0);

    $second = seedCustomerNotification($user, TopupApprovedNotification::class, [
        'title' => 'Second',
        'url' => route('wallet'),
    ]);

    expect($user->fresh()->unreadNotifications()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(NotificationBellDropdown::class)
        ->assertSet('unreadCount', 1);

    expect($user->fresh()->unreadNotifications()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(NotificationBellDropdown::class)
        ->call('markAsReadOnOpen')
        ->assertSet('unreadCount', 0);

    expect(DatabaseNotification::query()->whereKey($second->id)->value('read_at'))->not->toBeNull();
});

it('reader does not issue per-item source model lookups', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 8) as $i) {
        seedCustomerNotification($user, TopupApprovedNotification::class, [
            'title' => "Row {$i}",
            'url' => route('wallet'),
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(NotificationActivityReader::class)->paginate($user, perPage: 8);

    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries->count())->toBeLessThanOrEqual(3)
        ->and($queries->contains(fn (array $q): bool => str_contains(strtolower($q['query']), 'topup_requests')))
        ->toBeFalse();
});
