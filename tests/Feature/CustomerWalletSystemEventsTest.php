<?php

declare(strict_types=1);

use App\Enums\CreditFacilityStatus;
use App\Models\SystemEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CustomerSystemEventPresenter;
use App\Support\FrontendMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('presenter humanizes credit facility update without raw technical keys', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $money = FrontendMoney::for($user);

    $event = new SystemEvent([
        'event_type' => 'wallet.credit_facility.updated',
        'meta' => [
            'wallet_id' => 99,
            'target_user_id' => $user->id,
            'currency' => 'USD',
            'outstanding_debt' => 10.00,
            'available_credit_after' => 40.50,
            'previous_limit' => 0,
            'previous_terms' => null,
            'previous_enabled' => false,
            'previous_status' => null,
            'new_limit' => 50.50,
            'new_terms' => 30,
            'new_enabled' => true,
            'new_status' => CreditFacilityStatus::Active->value,
            'ip_address' => '127.0.0.1',
        ],
        'is_financial' => true,
    ]);

    $presented = app(CustomerSystemEventPresenter::class)->present($event, $user);

    expect($presented['title'])->toBe(__('messages.wallet_event_credit_facility_title'))
        ->and($presented['description'])->toBe(__('messages.wallet_event_credit_facility_granted'))
        ->and($presented['facts'])->not->toBeEmpty();

    $labels = collect($presented['facts'])->pluck('label')->all();
    $values = collect($presented['facts'])->pluck('value')->all();

    expect($labels)->toContain(__('messages.wallet_credit_limit_label'))
        ->and($labels)->toContain(__('messages.wallet_you_owe'))
        ->and($labels)->toContain(__('messages.wallet_available_credit_label'))
        ->and($labels)->toContain(__('messages.wallet_credit_terms_label'))
        ->and($labels)->toContain(__('messages.status'))
        ->and($values)->toContain($money->format(50.50, 'USD', 2))
        ->and($values)->toContain($money->format(10.00, 'USD', 2))
        ->and($values)->toContain($money->format(40.50, 'USD', 2));

    $serialized = json_encode($presented);
    expect($serialized)->not->toContain('previous_limit')
        ->and($serialized)->not->toContain('available_credit_after')
        ->and($serialized)->not->toContain('wallet_id')
        ->and($serialized)->not->toContain('ip_address')
        ->and($serialized)->not->toContain('wallet.credit_facility.updated');
});

test('presenter describes credit limit change for already-granted facility', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $money = FrontendMoney::for($user);

    $event = new SystemEvent([
        'event_type' => 'wallet.credit_facility.updated',
        'meta' => [
            'currency' => 'USD',
            'outstanding_debt' => 0,
            'available_credit_after' => 75,
            'previous_limit' => 50,
            'previous_terms' => 30,
            'previous_enabled' => true,
            'previous_status' => CreditFacilityStatus::Active->value,
            'new_limit' => 75,
            'new_terms' => 30,
            'new_enabled' => true,
            'new_status' => CreditFacilityStatus::Active->value,
        ],
        'is_financial' => true,
    ]);

    $presented = app(CustomerSystemEventPresenter::class)->present($event, $user);

    expect($presented['description'])->toBe(__('messages.wallet_event_credit_facility_limit_changed', [
        'from' => $money->format(50.00, 'USD', 2),
        'to' => $money->format(75.00, 'USD', 2),
    ]));
});

test('presenter maps purchase and topup events to friendly amount rows', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $money = FrontendMoney::for($user);

    $purchase = app(CustomerSystemEventPresenter::class)->present(new SystemEvent([
        'event_type' => 'wallet.purchase.debited',
        'meta' => ['amount' => 25.5, 'currency' => 'USD', 'wallet_id' => 1],
    ]), $user);

    $topup = app(CustomerSystemEventPresenter::class)->present(new SystemEvent([
        'event_type' => 'wallet.topup.posted',
        'meta' => ['amount' => 100, 'currency' => 'USD', 'wallet_id' => 1],
    ]), $user);

    expect($purchase['title'])->toBe(__('messages.wallet_event_purchase_title'))
        ->and($purchase['facts'][0]['value'])->toBe($money->format(25.50, 'USD', 2))
        ->and($purchase['facts'][0]['tone'])->toBe('debt')
        ->and($topup['title'])->toBe(__('messages.wallet_event_topup_title'))
        ->and($topup['facts'][0]['tone'])->toBe('positive');
});

test('wallet page financial overview does not render raw credit facility meta', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $money = FrontendMoney::for($user);

    SystemEvent::query()->create([
        'event_type' => 'wallet.credit_facility.updated',
        'entity_type' => $wallet->getMorphClass(),
        'entity_id' => $wallet->id,
        'meta' => [
            'wallet_id' => $wallet->id,
            'target_user_id' => $user->id,
            'currency' => 'USD',
            'outstanding_debt' => 0,
            'available_credit_after' => 50.50,
            'previous_limit' => 0,
            'previous_terms' => null,
            'previous_enabled' => false,
            'previous_status' => null,
            'new_limit' => 50.50,
            'new_terms' => 30,
            'new_enabled' => true,
            'new_status' => CreditFacilityStatus::Active->value,
            'ip_address' => '203.0.113.10',
        ],
        'severity' => 'info',
        'is_financial' => true,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="financial-overview"')
        ->assertDontSee(__('messages.wallet_account_activity'))
        ->assertDontSeeHtml('data-timeline-audience="customer"')
        ->assertDontSee('wallet.credit_facility.updated')
        ->assertDontSee('previous_limit')
        ->assertDontSee('available_credit_after')
        ->assertDontSee('View meta')
        ->assertDontSee(__('messages.view_meta'))
        ->assertDontSee('203.0.113.10')
        ->assertDontSee('"wallet_id"')
        ->assertDontSee($money->format(50.50, 'USD', 2));
});
