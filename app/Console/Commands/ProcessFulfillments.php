<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fulfillments\CompleteFulfillment;
use App\Actions\Fulfillments\FailFulfillment;
use App\Actions\Fulfillments\StartFulfillment;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessFulfillments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fulfillment:process
                            {--limit= : Only process a limited number of fulfillments}
                            {--only-pending : Only process queued fulfillments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process queued fulfillments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = $this->option('limit');
        $onlyPending = (bool) $this->option('only-pending');

        if ($limit !== null && ! ctype_digit((string) $limit)) {
            $this->error('Limit must be a positive integer.');

            return self::FAILURE;
        }

        $limit = $limit !== null ? (int) $limit : null;

        $statuses = $onlyPending
            ? [FulfillmentStatus::Queued->value]
            : [FulfillmentStatus::Queued->value, FulfillmentStatus::Processing->value];

        $fulfillments = Fulfillment::query()
            ->whereIn('status', $statuses)
            ->orderBy('created_at')
            ->when($limit !== null && $limit > 0, fn ($query) => $query->limit($limit))
            ->with('orderItem:id,requirements_payload')
            ->get();

        if ($fulfillments->isEmpty()) {
            $this->line('No fulfillments to process.');

            return self::SUCCESS;
        }

        $startFulfillment = app(StartFulfillment::class);
        $completeFulfillment = app(CompleteFulfillment::class);
        $failFulfillment = app(FailFulfillment::class);

        $processed = 0;
        $failed = 0;

        foreach ($fulfillments as $fulfillment) {
            if (str_starts_with($fulfillment->provider, 'browser:')) {
                continue;
            }

            try {
                if ($fulfillment->status === FulfillmentStatus::Queued) {
                    $startFulfillment->handle($fulfillment, 'system', null, ['source' => 'command']);
                }

                if ($fulfillment->provider !== 'manual') {
                    throw new \RuntimeException('Unsupported fulfillment provider.');
                }

                $completeFulfillment->handle(
                    $fulfillment->refresh(),
                    $this->manualPayload($fulfillment),
                    'system'
                );
                $processed++;
            } catch (\Throwable $exception) {
                $failed++;
                $failFulfillment->handle(
                    $fulfillment->refresh(),
                    $exception->getMessage(),
                    'system'
                );

                Log::error('Fulfillment processing failed', [
                    'fulfillment_id' => $fulfillment->id,
                    'order_id' => $fulfillment->order_id,
                    'order_item_id' => $fulfillment->order_item_id,
                    'provider' => $fulfillment->provider,
                    'exception' => $exception->getMessage(),
                ]);

                activity()
                    ->inLog('system')
                    ->event('fulfillment.process_failed')
                    ->performedOn($fulfillment)
                    ->withProperties([
                        'fulfillment_id' => $fulfillment->id,
                        'order_id' => $fulfillment->order_id,
                        'order_item_id' => $fulfillment->order_item_id,
                        'provider' => $fulfillment->provider,
                        'error' => $exception->getMessage(),
                    ])
                    ->log('Fulfillment processing failed');
            }
        }

        $this->info(sprintf('Processed %d fulfillment(s).', $processed));

        if ($failed > 0) {
            $this->warn(sprintf('Failed %d fulfillment(s).', $failed));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function manualPayload(Fulfillment $fulfillment): array
    {
        $requirementsPayload = data_get($fulfillment->meta, 'requirements_payload')
            ?? $fulfillment->orderItem?->requirements_payload;

        return array_filter([
            'code' => 'MANUAL-'.$fulfillment->id,
            'delivered_at' => now()->toIso8601String(),
            'requirements_payload' => $requirementsPayload,
        ], fn ($value) => $value !== null);
    }
}
