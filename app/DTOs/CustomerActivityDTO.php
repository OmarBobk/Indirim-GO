<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Immutable customer Activity projection row. No Eloquent models.
 */
final readonly class CustomerActivityDTO
{
    /**
     * @param  array<string, scalar|null>  $secondaryMeta
     */
    public function __construct(
        public string $id,
        public string $stableKey,
        public string $sourceType,
        public string $sourceId,
        public string $dedupeKey,
        public ?string $groupKey,
        public CustomerActivityCategory $category,
        public CustomerActivityImportance $importance,
        public CustomerActivityStatusToken $statusToken,
        public string $title,
        public string $description,
        public CarbonInterface $occurredAt,
        public ?CarbonInterface $readAt,
        public bool $isUnread,
        public bool $requiresAction,
        public ?string $actionLabel,
        public CustomerActivityDestination $destination,
        public array $secondaryMeta,
        public ?CustomerActivityMoney $money,
        public string $iconKey,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'stableKey' => $this->stableKey,
            'sourceType' => $this->sourceType,
            'sourceId' => $this->sourceId,
            'dedupeKey' => $this->dedupeKey,
            'groupKey' => $this->groupKey,
            'category' => $this->category->value,
            'importance' => $this->importance->value,
            'statusToken' => $this->statusToken->value,
            'title' => $this->title,
            'description' => $this->description,
            'occurredAt' => $this->occurredAt->toIso8601String(),
            'readAt' => $this->readAt?->toIso8601String(),
            'isUnread' => $this->isUnread,
            'requiresAction' => $this->requiresAction,
            'actionLabel' => $this->actionLabel,
            'destination' => $this->destination->toArray(),
            'secondaryMeta' => $this->secondaryMeta,
            'money' => $this->money?->toArray(),
            'iconKey' => $this->iconKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $occurredAt = $payload['occurredAt'] ?? now();
        $readAt = $payload['readAt'] ?? null;

        return new self(
            id: (string) ($payload['id'] ?? ''),
            stableKey: (string) ($payload['stableKey'] ?? ''),
            sourceType: (string) ($payload['sourceType'] ?? 'notification'),
            sourceId: (string) ($payload['sourceId'] ?? ''),
            dedupeKey: (string) ($payload['dedupeKey'] ?? ''),
            groupKey: isset($payload['groupKey']) ? (string) $payload['groupKey'] : null,
            category: CustomerActivityCategory::from((string) ($payload['category'] ?? 'account')),
            importance: CustomerActivityImportance::from((string) ($payload['importance'] ?? 'informational')),
            statusToken: CustomerActivityStatusToken::from((string) ($payload['statusToken'] ?? 'neutral')),
            title: (string) ($payload['title'] ?? ''),
            description: (string) ($payload['description'] ?? ''),
            occurredAt: self::asCarbon($occurredAt),
            readAt: $readAt !== null ? self::asCarbon($readAt) : null,
            isUnread: (bool) ($payload['isUnread'] ?? false),
            requiresAction: (bool) ($payload['requiresAction'] ?? false),
            actionLabel: isset($payload['actionLabel']) ? (string) $payload['actionLabel'] : null,
            destination: CustomerActivityDestination::fromArray(
                is_array($payload['destination'] ?? null) ? $payload['destination'] : ['type' => 'activity']
            ),
            secondaryMeta: is_array($payload['secondaryMeta'] ?? null) ? $payload['secondaryMeta'] : [],
            money: is_array($payload['money'] ?? null) ? CustomerActivityMoney::fromArray($payload['money']) : null,
            iconKey: (string) ($payload['iconKey'] ?? 'bell'),
        );
    }

    private static function asCarbon(mixed $value): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        return \Illuminate\Support\Carbon::parse((string) $value);
    }
}
