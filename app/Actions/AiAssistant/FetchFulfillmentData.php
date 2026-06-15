<?php

declare(strict_types=1);

namespace App\Actions\AiAssistant;

use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;

class FetchFulfillmentData
{
    /**
     * @return array{
     *     fulfillment_id: int,
     *     status: string,
     *     provider: string,
     *     attempts: int,
     *     last_error: string|null,
     *     claimed_by: int|null,
     *     claimed_at: string|null,
     *     processed_at: string|null,
     *     completed_at: string|null,
     *     order: array{
     *         id: int,
     *         order_number: string,
     *         status: string,
     *         customer_username: string|null,
     *     },
     *     order_item: array{id: int, name: string, line_total: string}|null,
     *     claimer: array{id: int, username: string, name: string}|null,
     *     latest_automation_run: array{
     *         uuid: string,
     *         status: string,
     *         supplier_key: string,
     *         attempt: int,
     *         error_code: string|null,
     *         error_message: string|null,
     *         started_at: string|null,
     *         finished_at: string|null,
     *     }|null,
     *     recent_logs: list<array{id: int, level: string, message: string, created_at: string}>,
     * }|null
     */
    public function handle(int|string $fulfillmentId): ?array
    {
        $fulfillment = Fulfillment::query()
            ->with([
                'order:id,order_number,status,user_id',
                'order.user:id,username',
                'orderItem:id,order_id,name,line_total',
                'claimer:id,username,name',
                'logs' => fn ($query) => $query->latest('id')->limit(5),
                'automationRuns' => fn ($query) => $query->latest('created_at')->limit(1),
            ])
            ->find((int) $fulfillmentId);

        if ($fulfillment === null) {
            return null;
        }

        /** @var FulfillmentAutomationRun|null $latestRun */
        $latestRun = $fulfillment->automationRuns->first();

        $orderItem = $fulfillment->orderItem;
        $claimer = $fulfillment->claimer;

        return [
            'fulfillment_id' => $fulfillment->id,
            'status' => $fulfillment->status->value,
            'provider' => $fulfillment->provider,
            'attempts' => (int) $fulfillment->attempts,
            'last_error' => $fulfillment->last_error,
            'claimed_by' => $fulfillment->claimed_by,
            'claimed_at' => $fulfillment->claimed_at?->toDateTimeString(),
            'processed_at' => $fulfillment->processed_at?->toDateTimeString(),
            'completed_at' => $fulfillment->completed_at?->toDateTimeString(),
            'order' => [
                'id' => $fulfillment->order?->id ?? 0,
                'order_number' => (string) ($fulfillment->order?->order_number ?? ''),
                'status' => $fulfillment->order?->status->value ?? '',
                'customer_username' => $fulfillment->order?->user?->username,
            ],
            'order_item' => $orderItem === null ? null : [
                'id' => $orderItem->id,
                'name' => $orderItem->name,
                'line_total' => (string) $orderItem->line_total,
            ],
            'claimer' => $claimer === null ? null : [
                'id' => $claimer->id,
                'username' => (string) $claimer->username,
                'name' => (string) $claimer->name,
            ],
            'latest_automation_run' => $latestRun === null ? null : [
                'uuid' => $latestRun->uuid,
                'status' => $latestRun->status->value,
                'supplier_key' => $latestRun->supplier_key,
                'attempt' => (int) $latestRun->attempt,
                'error_code' => $latestRun->error_code,
                'error_message' => $latestRun->error_message,
                'started_at' => $latestRun->started_at?->toDateTimeString(),
                'finished_at' => $latestRun->finished_at?->toDateTimeString(),
            ],
            'recent_logs' => $fulfillment->logs->map(fn ($log): array => [
                'id' => $log->id,
                'level' => $log->level->value,
                'message' => $log->message,
                'created_at' => $log->created_at?->toDateTimeString() ?? '',
            ])->values()->all(),
        ];
    }
}
