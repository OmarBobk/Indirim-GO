<?php

declare(strict_types=1);

use App\Actions\Topups\CreateTopupRequestAction;
use App\Actions\Topups\GetCustomerTopupDetail;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CustomerTopupPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('presents english and arabic top-up status labels', function (): void {
    $user = User::factory()->create(['locale' => 'en']);
    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => Wallet::forUser($user)->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 30,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    app()->setLocale('en');
    $en = app(CustomerTopupPresenter::class)->presentDetail(
        app(GetCustomerTopupDetail::class)->handle($user, (string) $request->public_ref),
        $user
    );

    expect($en['status_label'])->toBe(__('messages.topup_status_under_review'))
        ->and($en['amount']['dir'])->toBe('ltr');

    app()->setLocale('ar');
    $ar = app(CustomerTopupPresenter::class)->presentDetail(
        app(GetCustomerTopupDetail::class)->handle($user, (string) $request->public_ref),
        $user
    );

    expect($ar['status_label'])->toBe(__('messages.topup_status_under_review'))
        ->and($ar['actor_label'])->toBe(__('messages.topup_actor_waiting_staff'));
});
