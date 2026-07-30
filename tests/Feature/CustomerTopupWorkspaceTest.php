<?php

declare(strict_types=1);

use App\Actions\Topups\CreateTopupRequestAction;
use App\Actions\Topups\GetCustomerTopupDetail;
use App\Actions\Topups\GetCustomerTopupRequests;
use App\DTOs\Topups\CustomerTopupFilters;
use App\Enums\TopupRequestStatus;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\TopupRequestPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makeCustomerTopup(User $user, array $overrides = []): TopupRequest
{
    $wallet = Wallet::forUser($user);
    $paymentMethodId = PaymentMethod::query()->where('name', 'Sham Cash')->value('id');

    return app(CreateTopupRequestAction::class)->handle(array_merge([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => $paymentMethodId,
        'amount' => 50,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ], $overrides));
}

it('lists only the authenticated users top-up requests', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerTopup($owner);
    makeCustomerTopup($other, ['amount' => 99]);

    $page = app(GetCustomerTopupRequests::class)->handle($owner, CustomerTopupFilters::fromInput([]));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->publicReference)->toBe($owned->public_ref)
        ->and($page->items[0]->publicReference)->toStartWith('TUP-');
});

it('filters under review and needs action correctly', function (): void {
    $user = User::factory()->create();
    $pending = makeCustomerTopup($user);
    $rejected = makeCustomerTopup($user, ['amount' => 20]);
    $rejected->update(['status' => TopupRequestStatus::Rejected, 'note' => 'Blurry proof']);

    $underReview = app(GetCustomerTopupRequests::class)->handle(
        $user,
        CustomerTopupFilters::fromInput(['filter' => 'under_review'])
    );
    $needsAction = app(GetCustomerTopupRequests::class)->handle(
        $user,
        CustomerTopupFilters::fromInput(['filter' => 'needs_action'])
    );

    expect($underReview->items)->toHaveCount(1)
        ->and($underReview->items[0]->publicReference)->toBe($pending->public_ref)
        ->and($needsAction->items)->toHaveCount(1)
        ->and($needsAction->items[0]->canRetry)->toBeTrue();
});

it('searches by public reference prefix only for owned requests', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerTopup($owner);
    $foreign = makeCustomerTopup($other);

    $page = app(GetCustomerTopupRequests::class)->handle(
        $owner,
        CustomerTopupFilters::fromInput(['search' => substr((string) $owned->public_ref, 0, 8)])
    );
    $foreignSearch = app(GetCustomerTopupRequests::class)->handle(
        $owner,
        CustomerTopupFilters::fromInput(['search' => (string) $foreign->public_ref])
    );

    expect($page->items)->toHaveCount(1)
        ->and($foreignSearch->items)->toHaveCount(0);
});

it('paginates at twenty per page', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 21) as $i) {
        makeCustomerTopup($user, ['amount' => $i]);
        TopupRequest::query()->where('user_id', $user->id)->latest('id')->first()?->update([
            'status' => TopupRequestStatus::Rejected,
        ]);
    }

    $page1 = app(GetCustomerTopupRequests::class)->handle(
        $user,
        CustomerTopupFilters::fromInput(['page' => 1])
    );

    expect($page1->items)->toHaveCount(20)
        ->and($page1->total)->toBe(21)
        ->and($page1->lastPage)->toBe(2);
});

it('loads owned detail and 404s foreign public refs', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = makeCustomerTopup($owner);
    $foreign = makeCustomerTopup($other);

    $detail = app(GetCustomerTopupDetail::class)->handle($owner, (string) $owned->public_ref);
    expect($detail->publicReference)->toBe($owned->public_ref)
        ->and($detail->moneyMoved)->toBeFalse();

    expect(fn () => app(GetCustomerTopupDetail::class)->handle($owner, (string) $foreign->public_ref))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('renders top-ups index and detail pages', function (): void {
    $user = User::factory()->create();
    $request = makeCustomerTopup($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-topups')
        ->assertSeeHtml('data-test="wallet-topups-page"')
        ->assertSeeHtml('data-test="financial-nav-topups"')
        ->assertSee($request->public_ref);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-topup-detail', ['topup' => $request->public_ref])
        ->assertSeeHtml('data-test="wallet-topup-detail-page"')
        ->assertSee(__('messages.topup_status_under_review'))
        ->assertSee(__('messages.topup_actor_waiting_staff'));
});

it('redirects successful create to top-up detail', function (): void {
    $user = User::factory()->create();
    $methodId = (int) PaymentMethod::query()->where('name', 'Sham Cash')->value('id');

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-topup')
        ->set('topupAmount', '25')
        ->set('paymentMethodId', $methodId)
        ->call('submitTopup')
        ->assertRedirect();

    $created = TopupRequest::query()->where('user_id', $user->id)->latest('id')->first();
    expect($created)->not->toBeNull()
        ->and(TopupRequestPublicRef::isValidFormat((string) $created->public_ref))->toBeTrue();
});

it('marks approved without posted transaction as integrity anomaly', function (): void {
    $user = User::factory()->create();
    $request = makeCustomerTopup($user);
    $request->update([
        'status' => TopupRequestStatus::Approved,
        'approved_at' => now(),
    ]);
    WalletTransaction::query()
        ->where('reference_type', TopupRequest::class)
        ->where('reference_id', $request->id)
        ->update(['status' => WalletTransaction::STATUS_PENDING]);

    $detail = app(GetCustomerTopupDetail::class)->handle($user, (string) $request->public_ref);

    expect($detail->isIntegrityAnomaly)->toBeTrue()
        ->and($detail->moneyMoved)->toBeFalse();
});
