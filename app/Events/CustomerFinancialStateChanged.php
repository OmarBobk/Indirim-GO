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

    public readonly string $occurredAt;

    public function __construct(
        public readonly int $userId,
        public readonly CustomerFinancialInvalidationReason $reason,
        ?string $eventId = null,
        ?string $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
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
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason->value,
            'occurred_at' => $this->occurredAt,
            'event_id' => $this->eventId,
        ];
    }
}
