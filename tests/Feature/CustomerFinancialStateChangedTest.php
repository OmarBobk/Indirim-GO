<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Events\CustomerFinancialStateChanged;
use App\Models\User;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('financial state changed broadcasts allowlisted payload without balance or amount', function () {
    $user = User::factory()->create();
    $event = new CustomerFinancialStateChanged($user->id, [
        CustomerFinancialInvalidationReason::TransactionPosted,
        CustomerFinancialInvalidationReason::TopupStateChanged,
        CustomerFinancialInvalidationReason::TransactionPosted,
    ]);

    expect($event->broadcastAs())->toBe('CustomerFinancialStateChanged')
        ->and($event->broadcastWith())->toHaveKeys(['reasons', 'event_id', 'schema_version'])
        ->and(array_keys($event->broadcastWith()))->toBe([
            'reasons',
            'schema_version',
            'event_id',
        ])
        ->and($event->broadcastWith())->not->toHaveKey('balance')
        ->and($event->broadcastWith())->not->toHaveKey('amount')
        ->and($event->broadcastWith())->not->toHaveKey('user_id')
        ->and($event->broadcastWith()['reasons'])->toBe([
            'transaction_posted',
            'topup_state_changed',
        ]);
});

test('financial broadcaster dispatches after commit only', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();

    DB::transaction(function () use ($user): void {
        CustomerFinancialBroadcaster::dispatch($user->id, [
            CustomerFinancialInvalidationReason::TransactionPosted,
            CustomerFinancialInvalidationReason::TopupStateChanged,
        ]);
        Event::assertNotDispatched(CustomerFinancialStateChanged::class);
    });

    Event::assertDispatched(CustomerFinancialStateChanged::class, function (CustomerFinancialStateChanged $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->reasons === [
                CustomerFinancialInvalidationReason::TransactionPosted,
                CustomerFinancialInvalidationReason::TopupStateChanged,
            ];
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

test('financial delivery failure is isolated from committed domain work', function () {
    $user = User::factory()->create();
    Event::listen(
        CustomerFinancialStateChanged::class,
        static fn () => throw new RuntimeException('reverb unavailable'),
    );
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Customer financial invalidation broadcast failed'
            && $context['user_id'] === $user->id);

    CustomerFinancialBroadcaster::dispatch(
        $user->id,
        CustomerFinancialInvalidationReason::BalanceChanged,
    );

    expect(true)->toBeTrue();
});
