<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\PayoutRequestStatus;
use App\Events\CustomerFinancialStateChanged;
use App\Livewire\Admin\PayoutRequestsTable;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('admin can mark payout request as processed', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    Permission::query()->firstOrCreate(['name' => 'manage_settlements', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $salesperson = User::factory()->create();

    $request = PayoutRequest::query()->create([
        'user_id' => $salesperson->id,
        'eligible_amount' => 99.5,
        'currency' => 'USD',
        'status' => PayoutRequestStatus::Pending,
    ]);

    Livewire::actingAs($admin)
        ->test(PayoutRequestsTable::class)
        ->call('markProcessed', $request->id);

    $request->refresh();
    expect($request->status)->toBe(PayoutRequestStatus::Processed);
    expect($request->processed_by)->toBe($admin->id);
    expect($request->processed_at)->not->toBeNull();
    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $salesperson->id
            && $event->reasons === [CustomerFinancialInvalidationReason::PayoutRequestStateChanged],
    );

    app(\App\Actions\Commissions\MarkPayoutRequestProcessed::class)->handle($request, $admin);
    Event::assertDispatchedTimes(CustomerFinancialStateChanged::class, 1);
});
