<?php

use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Actions\Topups\RejectTopupRequest;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\TopupRequestStatus;
use App\Events\CustomerFinancialStateChanged;
use App\Events\TopupRequestsChanged;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('approving a topup posts ledger and increments balance once', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();
    $approver = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage_topups']);
    $approver->givePermissionTo('manage_topups');
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 0,
        'currency' => 'USD',
    ]);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 100,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
        'note' => 'via WhatsApp',
    ]);

    $action = new ApproveTopupRequest;
    $action->handle($approver, $request);
    $action->handle($approver, $request);

    $wallet->refresh();
    $request->refresh();
    $transaction = WalletTransaction::query()
        ->where('reference_type', TopupRequest::class)
        ->where('reference_id', $request->id)
        ->firstOrFail();

    expect((float) $wallet->balance)->toBe(100.0);
    expect($transaction->status)->toBe(WalletTransaction::STATUS_POSTED);
    expect($request->status)->toBe(TopupRequestStatus::Approved);
    expect($request->approved_by)->toBe($approver->id);
    expect($request->approved_at)->not->toBeNull();
    expect(Activity::query()
        ->where('event', 'topup.approved')
        ->where('log_name', 'payments')
        ->where('subject_type', TopupRequest::class)
        ->where('subject_id', $request->id)
        ->exists()
    )->toBeTrue();
    Event::assertDispatchedTimes(CustomerFinancialStateChanged::class, 1);
    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $user->id
            && $event->reasons === [
                CustomerFinancialInvalidationReason::TransactionPosted,
                CustomerFinancialInvalidationReason::TopupStateChanged,
            ],
    );
});

test('approving a topup dispatches change event', function () {
    Event::fake([TopupRequestsChanged::class]);

    $user = User::factory()->create();
    $approver = User::factory()->create();
    Permission::firstOrCreate(['name' => 'manage_topups']);
    $approver->givePermissionTo('manage_topups');
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 0,
        'currency' => 'USD',
    ]);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 60,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    (new ApproveTopupRequest)->handle($approver, $request);

    Event::assertDispatched(TopupRequestsChanged::class, function (TopupRequestsChanged $event) use ($request): bool {
        return $event->reason === 'status-updated'
            && $event->topupRequestId === $request->id;
    });
});

test('rejecting a topup does not change balance', function () {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 0,
        'currency' => 'USD',
    ]);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'EFT Transfer')->value('id'),
        'amount' => 55,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $action = new RejectTopupRequest;
    $action->handle($request, $approver->id);

    $wallet->refresh();
    $request->refresh();
    $transaction = WalletTransaction::query()
        ->where('reference_type', TopupRequest::class)
        ->where('reference_id', $request->id)
        ->firstOrFail();

    expect((float) $wallet->balance)->toBe(0.0);
    expect($transaction->status)->toBe(WalletTransaction::STATUS_REJECTED);
    expect($request->status)->toBe(TopupRequestStatus::Rejected);
    expect(Activity::query()
        ->where('event', 'topup.rejected')
        ->where('log_name', 'payments')
        ->where('subject_type', TopupRequest::class)
        ->where('subject_id', $request->id)
        ->exists()
    )->toBeTrue();
});

test('rejecting a topup dispatches change event', function () {
    Event::fake([TopupRequestsChanged::class]);

    $user = User::factory()->create();
    $approver = User::factory()->create();
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 0,
        'currency' => 'USD',
    ]);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'EFT Transfer')->value('id'),
        'amount' => 45,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    (new RejectTopupRequest)->handle($request, $approver->id);

    Event::assertDispatched(TopupRequestsChanged::class, function (TopupRequestsChanged $event) use ($request): bool {
        return $event->reason === 'status-updated'
            && $event->topupRequestId === $request->id;
    });
});
