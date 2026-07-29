<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Events\CustomerFinancialStateChanged;
use App\Models\User;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('financial state changed broadcasts allowlisted payload without balance or amount', function () {
    $user = User::factory()->create();
    $event = new CustomerFinancialStateChanged($user->id, CustomerFinancialInvalidationReason::BalanceChanged);

    expect($event->broadcastAs())->toBe('CustomerFinancialStateChanged')
        ->and($event->broadcastWith())->toHaveKeys(['reason', 'occurred_at', 'event_id'])
        ->and($event->broadcastWith())->not->toHaveKey('balance')
        ->and($event->broadcastWith())->not->toHaveKey('amount')
        ->and($event->broadcastWith()['reason'])->toBe('balance_changed');
});

test('financial broadcaster dispatches after commit only', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        CustomerFinancialBroadcaster::dispatch($user->id, CustomerFinancialInvalidationReason::TopupStateChanged);
        Event::assertNotDispatched(CustomerFinancialStateChanged::class);
    });

    Event::assertDispatched(CustomerFinancialStateChanged::class, function (CustomerFinancialStateChanged $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->reason === CustomerFinancialInvalidationReason::TopupStateChanged;
    });
});

test('financial broadcaster does not dispatch when transaction rolls back', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): void {
            CustomerFinancialBroadcaster::dispatch($user->id, CustomerFinancialInvalidationReason::BalanceChanged);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(CustomerFinancialStateChanged::class);
});
