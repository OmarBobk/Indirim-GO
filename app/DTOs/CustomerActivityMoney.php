<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Semantic money payload for Activity. Format in the presenter via FrontendMoney.
 */
final readonly class CustomerActivityMoney
{
    public function __construct(
        public string $amount,
        public string $currency,
        public string $direction,
        public bool $visible,
    ) {}

    /**
     * @return array{amount: string, currency: string, direction: string, visible: bool}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'direction' => $this->direction,
            'visible' => $this->visible,
        ];
    }

    /**
     * @param  array{amount?: mixed, currency?: mixed, direction?: mixed, visible?: mixed}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            amount: (string) ($payload['amount'] ?? '0'),
            currency: strtoupper((string) ($payload['currency'] ?? 'USD')),
            direction: (string) ($payload['direction'] ?? 'credit'),
            visible: (bool) ($payload['visible'] ?? true),
        );
    }
}
