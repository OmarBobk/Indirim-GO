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
use Illuminate\Support\Carbon;

it('builds an immutable serializable activity dto without eloquent models', function (): void {
    $dto = new CustomerActivityDTO(
        id: 'notification:abc',
        stableKey: 'notification:abc',
        sourceType: 'TopupRequest',
        sourceId: '12',
        dedupeKey: 'topup:12',
        groupKey: null,
        category: CustomerActivityCategory::Money,
        importance: CustomerActivityImportance::Success,
        statusToken: CustomerActivityStatusToken::Success,
        title: 'Top-up approved',
        description: 'Funds added',
        occurredAt: Carbon::parse('2026-07-01T10:00:00Z'),
        readAt: null,
        isUnread: true,
        requiresAction: false,
        actionLabel: 'View wallet',
        destination: new CustomerActivityDestination(CustomerActivityDestinationType::Wallet),
        secondaryMeta: ['notification_type' => 'TopupApprovedNotification'],
        money: new CustomerActivityMoney('25.00', 'USD', 'credit', true),
        iconKey: 'banknotes',
    );

    expect($dto)->toBeInstanceOf(CustomerActivityDTO::class)
        ->and($dto->category)->toBe(CustomerActivityCategory::Money)
        ->and($dto->importance)->toBe(CustomerActivityImportance::Success)
        ->and($dto->statusToken)->toBe(CustomerActivityStatusToken::Success);

    $array = $dto->toArray();

    expect($array)->toHaveKeys([
        'id', 'stableKey', 'sourceType', 'sourceId', 'dedupeKey', 'groupKey',
        'category', 'importance', 'statusToken', 'title', 'description',
        'occurredAt', 'readAt', 'isUnread', 'requiresAction', 'actionLabel',
        'destination', 'secondaryMeta', 'money', 'iconKey',
    ])
        ->and($array['category'])->toBe('money')
        ->and($array['destination'])->toBe(['type' => 'wallet', 'params' => []])
        ->and($array['money'])->toBe([
            'amount' => '25.00',
            'currency' => 'USD',
            'direction' => 'credit',
            'visible' => true,
        ])
        ->and(json_encode($array))->not->toBeFalse();

    $roundTrip = CustomerActivityDTO::fromArray($array);

    expect($roundTrip->id)->toBe($dto->id)
        ->and($roundTrip->destination->type)->toBe(CustomerActivityDestinationType::Wallet)
        ->and($roundTrip->money?->amount)->toBe('25.00');

    foreach ($array as $value) {
        expect($value)->not->toBeInstanceOf(User::class);
    }
});

it('validates typed destinations and rejects empty order detail params', function (): void {
    expect(fn () => new CustomerActivityDestination(CustomerActivityDestinationType::OrderDetail))
        ->toThrow(InvalidArgumentException::class);

    $destination = new CustomerActivityDestination(
        CustomerActivityDestinationType::OrderDetail,
        ['order_number' => 'ORD-1']
    );

    expect($destination->toArray())->toBe([
        'type' => 'order_detail',
        'params' => ['order_number' => 'ORD-1'],
    ]);
});

it('uses only semantic vocabularies for category importance and status', function (): void {
    expect(array_column(CustomerActivityCategory::cases(), 'value'))
        ->toBe(['orders', 'money', 'rewards', 'account'])
        ->and(array_column(CustomerActivityImportance::cases(), 'value'))
        ->toBe(['urgent', 'attention', 'success', 'informational'])
        ->and(array_column(CustomerActivityStatusToken::cases(), 'value'))
        ->toBe(['neutral', 'progress', 'success', 'warning', 'danger']);
});
