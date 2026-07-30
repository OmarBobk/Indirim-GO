<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\CustomerFinancialInvalidationReason;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Invalidation-only signal for customer financial surfaces.
 * Payload must never include balances, amounts, or source IDs.
 */
class CustomerFinancialStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    /** @var list<CustomerFinancialInvalidationReason> */
    public readonly array $reasons;

    /**
     * @param  CustomerFinancialInvalidationReason|list<CustomerFinancialInvalidationReason>  $reasons
     */
    public function __construct(
        public readonly int $userId,
        CustomerFinancialInvalidationReason|array $reasons,
        ?string $eventId = null,
    ) {
        $reasons = $reasons instanceof CustomerFinancialInvalidationReason ? [$reasons] : $reasons;
        $uniqueReasons = [];

        foreach ($reasons as $reason) {
            if ($reason instanceof CustomerFinancialInvalidationReason) {
                $uniqueReasons[$reason->value] = $reason;
            }
        }

        if ($uniqueReasons === []) {
            throw new \InvalidArgumentException('At least one financial invalidation reason is required.');
        }

        $this->reasons = array_values($uniqueReasons);
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CustomerFinancialStateChanged';
    }

    /**
     * @return array{reasons: list<string>, schema_version: int, event_id: string}
     */
    public function broadcastWith(): array
    {
        return [
            'reasons' => array_map(
                static fn (CustomerFinancialInvalidationReason $reason): string => $reason->value,
                $this->reasons,
            ),
            'schema_version' => 1,
            'event_id' => $this->eventId,
        ];
    }
}
