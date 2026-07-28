<?php

declare(strict_types=1);

use App\Livewire\CustomerNotificationCoordinator;
use App\Livewire\NotificationBellDropdown;
use App\Models\User;
use App\Notifications\TopupApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('recounts unread notifications once per invalidation and shares the count', function (): void {
    $user = User::factory()->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $component = Livewire::actingAs($user)->test(CustomerNotificationCoordinator::class);

    DB::flushQueryLog();

    $component
        ->dispatch('customer-activity-invalidate')
        ->assertSet('unreadCount', 0)
        ->assertDispatched('customer-unread-count-updated', count: 0);

    $countQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'notifications'))
        ->count();

    expect($countQueries)->toBeLessThanOrEqual(1);
});

it('updates bell unread count from coordinator payload without a second count query', function (): void {
    $user = User::factory()->create();
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => 'Approved',
            'message' => 'Funds added',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);

    $bell = Livewire::actingAs($user)->test(NotificationBellDropdown::class);
    expect($bell->get('unreadCount'))->toBe(1);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $bell->dispatch('customer-unread-count-updated', count: 0)
        ->assertSet('unreadCount', 0);

    $countQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*)')
            && str_contains(strtolower($query['query']), 'notifications'))
        ->count();

    expect($countQueries)->toBe(0);
});

it('leaves unread count unchanged for domain-only invalidation payloads', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CustomerNotificationCoordinator::class)
        ->dispatch('customer-activity-invalidate', source: 'domain', isReconcile: false)
        ->assertSet('unreadCount', 0)
        ->assertDispatched('customer-unread-count-updated', count: 0);
});

it('does not mark bell notifications read when coordinator refreshes', function (): void {
    $user = User::factory()->create();
    $notificationId = (string) Str::uuid();

    $user->notifications()->create([
        'id' => $notificationId,
        'type' => TopupApprovedNotification::class,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => 'Unread',
            'message' => 'Still unread',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationBellDropdown::class)
        ->dispatch('customer-activity-invalidate')
        ->assertSet('unreadCount', 1);

    expect($user->fresh()->unreadNotifications()->count())->toBe(1);
});
