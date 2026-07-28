<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CustomerActivityDestinationType;
use InvalidArgumentException;

/**
 * Typed customer navigation target. Never treat stored notification URLs as authority.
 */
final readonly class CustomerActivityDestination
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(
        public CustomerActivityDestinationType $type,
        public array $params = [],
    ) {
        $this->assertValidParams();
    }

    /**
     * @return array{type: string, params: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'params' => $this->params,
        ];
    }

    /**
     * @param  array{type?: string, params?: array<string, scalar|null>}  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = CustomerActivityDestinationType::from((string) ($payload['type'] ?? ''));

        /** @var array<string, scalar|null> $params */
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        return new self($type, $params);
    }

    private function assertValidParams(): void
    {
        match ($this->type) {
            CustomerActivityDestinationType::OrderDetail => $this->requireStringParam('order_number'),
            CustomerActivityDestinationType::Orders,
            CustomerActivityDestinationType::Wallet,
            CustomerActivityDestinationType::WalletTopup,
            CustomerActivityDestinationType::Cart,
            CustomerActivityDestinationType::Loyalty,
            CustomerActivityDestinationType::Referral,
            CustomerActivityDestinationType::Account,
            CustomerActivityDestinationType::Profile,
            CustomerActivityDestinationType::Activity => null,
        };
    }

    private function requireStringParam(string $key): void
    {
        $value = $this->params[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Destination {$this->type->value} requires a non-empty {$key} parameter.");
        }
    }
}
