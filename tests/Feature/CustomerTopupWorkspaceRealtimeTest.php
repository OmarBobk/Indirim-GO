<?php

declare(strict_types=1);

use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('refreshes top-ups page one on financial invalidate', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-topups')
        ->assertDontSee('TUP-');

    app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 12,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $created = \App\Models\TopupRequest::query()->where('user_id', $user->id)->latest('id')->first();

    $component
        ->dispatch('customer-financial-invalidate')
        ->assertSee($created->public_ref);
});

it('shows pending banner on page two without replacing rows', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 21) as $i) {
        $request = app(CreateTopupRequestAction::class)->handle([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
            'amount' => $i,
            'currency' => 'USD',
        ]);
        $request->update(['status' => TopupRequestStatus::Rejected]);
    }

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-topups')
        ->call('gotoPage', 2)
        ->assertSet('hasPendingRefresh', false);

    $component
        ->dispatch('customer-financial-invalidate')
        ->assertSet('hasPendingRefresh', true);
});
