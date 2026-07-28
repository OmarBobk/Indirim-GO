<?php

declare(strict_types=1);

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\DTOs\CustomerActivityMoney;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Models\User;
use App\Support\CustomerActivityPresenter;
use App\Support\CustomerStatusPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

function makeActivityDto(array $overrides = []): CustomerActivityDTO
{
    $defaults = [
        'id' => 'notification:1',
        'stableKey' => 'notification:1',
        'sourceType' => 'TopupRequest',
        'sourceId' => '1',
        'dedupeKey' => 'topup:1',
        'groupKey' => null,
        'category' => CustomerActivityCategory::Money,
        'importance' => CustomerActivityImportance::Success,
        'statusToken' => CustomerActivityStatusToken::Success,
        'title' => 'Approved',
        'description' => 'Funds added',
        'occurredAt' => Carbon::parse('2026-07-01T12:00:00Z'),
        'readAt' => null,
        'isUnread' => true,
        'requiresAction' => false,
        'actionLabel' => __('messages.activity_action_view_wallet'),
        'destination' => new CustomerActivityDestination(CustomerActivityDestinationType::Wallet),
        'secondaryMeta' => [],
        'money' => new CustomerActivityMoney('10.00', 'USD', 'credit', true),
        'iconKey' => 'banknotes',
    ];

    $payload = array_merge($defaults, $overrides);

    return new CustomerActivityDTO(...$payload);
}

it('maps semantic status through CustomerStatusPresentation and formats money', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'USD']);
    $presenter = app(CustomerActivityPresenter::class);

    $view = $presenter->present(makeActivityDto([
        'statusToken' => CustomerActivityStatusToken::Danger,
    ]), $user);

    expect($view['badge_color'])->toBe(CustomerStatusPresentation::activityBadgeColor('danger'))
        ->and($view['badge_color'])->toBe('red')
        ->and($view['money'])->toBeArray()
        ->and($view['money']['dir'])->toBe('ltr')
        ->and($view['money']['amount'])->toBe('10.00')
        ->and($view['money']['formatted'])->not->toBe('')
        ->and($view['href'])->toBe(route('wallet'))
        ->and($view['icon'])->toBe('banknotes');
});

it('resolves typed destinations to safe routes and falls back when order number is missing via orders list', function (): void {
    $presenter = app(CustomerActivityPresenter::class);

    expect($presenter->resolveHref(new CustomerActivityDestination(CustomerActivityDestinationType::Cart)))
        ->toBe(route('cart'))
        ->and($presenter->resolveHref(new CustomerActivityDestination(
            CustomerActivityDestinationType::OrderDetail,
            ['order_number' => 'ORD-99']
        )))->toBe(route('orders.show', ['order' => 'ORD-99']))
        ->and($presenter->resolveHref(new CustomerActivityDestination(CustomerActivityDestinationType::Orders)))
        ->toBe(route('orders.index'))
        ->and($presenter->resolveHref(new CustomerActivityDestination(CustomerActivityDestinationType::Activity)))
        ->toBe(route('activity.index'));
});

it('localises category and importance labels in english and arabic', function (): void {
    $presenter = app(CustomerActivityPresenter::class);

    app()->setLocale('en');
    $en = $presenter->present(makeActivityDto());
    expect($en['category_label'])->toBe('Money')
        ->and($en['importance_label'])->toBe('Success');

    app()->setLocale('ar');
    $ar = $presenter->present(makeActivityDto());
    expect($ar['category_label'])->toBe('الأموال')
        ->and($ar['importance_label'])->toBe('نجاح');
});
