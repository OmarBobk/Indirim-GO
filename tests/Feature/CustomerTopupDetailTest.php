<?php

declare(strict_types=1);

use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Actions\Topups\GetCustomerTopupDetail;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('links credited top-up detail to posted wallet transaction reference', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'manage_topups', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 40,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    app(ApproveTopupRequest::class)->handle($admin, $request->fresh());

    $detail = app(GetCustomerTopupDetail::class)->handle($customer, (string) $request->fresh()->public_ref);
    $posted = WalletTransaction::query()
        ->where('reference_type', TopupRequest::class)
        ->where('reference_id', $request->id)
        ->where('status', WalletTransaction::STATUS_POSTED)
        ->first();

    expect($detail->moneyMoved)->toBeTrue()
        ->and($detail->postedTransactionPublicRef)->toBe($posted?->public_ref)
        ->and($detail->creditedAt)->not->toBeNull();

    Livewire::actingAs($customer)
        ->test('pages::frontend.wallet-topup-detail', ['topup' => $request->public_ref])
        ->assertSee(__('messages.topup_status_credited'))
        ->assertSee($posted->public_ref);
});

it('exposes retry destination for rejected requests without mutating them', function (): void {
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 15,
        'currency' => 'USD',
    ]);
    $request->update([
        'status' => TopupRequestStatus::Rejected,
        'note' => 'Please resubmit clearer proof',
    ]);

    $detail = app(GetCustomerTopupDetail::class)->handle($customer, (string) $request->public_ref);

    expect($detail->canRetry)->toBeTrue()
        ->and($detail->customerSafeReason)->toBe('Please resubmit clearer proof')
        ->and($detail->retryDestination)->not->toBeNull();

    expect($request->fresh()->status)->toBe(TopupRequestStatus::Rejected);
});
