<?php

use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\TopupRequestStatus;
use App\Events\TopupRequestsChanged;
use App\Models\PaymentMethod;
use App\Models\TopupProof;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function shamCashPaymentMethodId(): int
{
    return (int) PaymentMethod::query()->where('name', 'Sham Cash')->value('id');
}

function eftPaymentMethodId(): int
{
    return (int) PaymentMethod::query()->where('name', 'EFT Transfer')->value('id');
}

test('user can create a topup request with proof from wallet page', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', UploadedFile::fake()->image('proof.jpg'))
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_created'));

    $topupRequest = TopupRequest::query()->first();

    expect($topupRequest)->not->toBeNull();
    expect($topupRequest->status)->toBe(TopupRequestStatus::Pending);

    $proof = TopupProof::query()->where('topup_request_id', $topupRequest->id)->first();
    expect($proof)->not->toBeNull();
    expect(Storage::disk('local')->exists($proof->file_path))->toBeTrue();

    $transaction = WalletTransaction::query()
        ->where('reference_type', TopupRequest::class)
        ->where('reference_id', $topupRequest->id)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->status)->toBe(WalletTransaction::STATUS_PENDING);
});

test('user can create a topup request without proof when attach proof is off', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', false)
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_created'))
        ->assertHasNoErrors();

    $topupRequest = TopupRequest::query()->first();
    expect($topupRequest)->not->toBeNull();
    expect(TopupProof::query()->where('topup_request_id', $topupRequest->id)->count())->toBe(0);
});

test('creating a topup request broadcasts change event', function () {
    Storage::fake('local');
    Event::fake([TopupRequestsChanged::class]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '40')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', UploadedFile::fake()->image('proof.jpg'))
        ->call('submitTopup');

    $request = TopupRequest::query()->first();

    expect($request)->not->toBeNull();

    Event::assertDispatched(TopupRequestsChanged::class, function (TopupRequestsChanged $event) use ($request): bool {
        return $event->reason === 'created'
            && $event->topupRequestId === $request->id;
    });
});

test('user cannot create a second pending topup request', function () {
    $user = User::factory()->create();
    $wallet = \App\Models\Wallet::forUser($user);

    app(CreateTopupRequestAction::class)->handle([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => shamCashPaymentMethodId(),
        'amount' => 20,
        'currency' => $wallet->currency,
        'status' => TopupRequestStatus::Pending,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '30')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', UploadedFile::fake()->image('proof.jpg'))
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_pending'));

    expect(TopupRequest::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('submit topup without proof fails when attach proof is on', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->call('submitTopup')
        ->assertHasErrors('proofFile');

    expect(TopupRequest::query()->count())->toBe(0);
});

test('submit topup with invalid file type fails validation when attach proof is on', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', UploadedFile::fake()->create('proof.txt', 100, 'text/plain'))
        ->call('submitTopup')
        ->assertHasErrors('proofFile');

    expect(TopupRequest::query()->count())->toBe(0);
});

test('submit topup with file exceeding max size fails validation when attach proof is on', function () {
    $user = User::factory()->create();

    $oversizedFile = UploadedFile::fake()->create('proof.pdf', 6 * 1024 * 1024, 'application/pdf');

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', $oversizedFile)
        ->call('submitTopup')
        ->assertHasErrors('proofFile');

    expect(TopupRequest::query()->count())->toBe(0);
});

test('valid proof creates request and single proof record', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '10')
        ->set('paymentMethodId', eftPaymentMethodId())
        ->set('attachProof', true)
        ->set('proofFile', UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'))
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_created'));

    $topupRequest = TopupRequest::query()->first();
    expect($topupRequest)->not->toBeNull();
    expect($topupRequest->proofs()->count())->toBe(1);
});

test('try-preferred customer topup input is converted to usd wallet amount', function () {
    WebsiteSetting::instance()->update([
        'prices_visible' => true,
        'usd_try_rate' => 40.0,
        'usd_try_rate_updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'preferred_currency' => 'TRY',
    ]);
    expect($user->preferred_currency)->toBe('TRY');
    expect(WebsiteSetting::getUsdTryRate())->toBe(40.0);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '1000')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', false)
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_created'));

    $topupRequest = TopupRequest::query()->first();
    expect($topupRequest)->not->toBeNull();
    expect((float) $topupRequest->amount)->toBe(25.0);
    expect($topupRequest->currency)->toBe('USD');
});

test('try-preferred topup conversion rounds up to avoid under-crediting', function () {
    WebsiteSetting::instance()->update([
        'prices_visible' => true,
        'usd_try_rate' => 39.9995,
        'usd_try_rate_updated_at' => now(),
    ]);

    $user = User::factory()->create([
        'preferred_currency' => 'TRY',
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->set('topupAmount', '10000')
        ->set('paymentMethodId', shamCashPaymentMethodId())
        ->set('attachProof', false)
        ->call('submitTopup')
        ->assertSet('noticeMessage', __('messages.topup_request_created'));

    $topupRequest = TopupRequest::query()->first();
    expect($topupRequest)->not->toBeNull();
    expect((float) $topupRequest->amount)->toBe(250.01);
    expect($topupRequest->currency)->toBe('USD');
});
