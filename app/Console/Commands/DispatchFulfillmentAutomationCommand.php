<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FulfillmentStatus;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\Fulfillment;
use App\Services\FulfillmentAutomationService;
use Illuminate\Console\Command;

class DispatchFulfillmentAutomationCommand extends Command
{
    protected $signature = 'fulfillment:dispatch-automation {--limit= : Maximum fulfillments to dispatch}';

    protected $description = 'Dispatch browser automation jobs for queued fulfillments';

    public function handle(FulfillmentAutomationService $automationService): int
    {
        if (! $automationService->isEnabled()) {
            $this->line('Fulfillment automation is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $limit = $limit !== null && ctype_digit((string) $limit)
            ? (int) $limit
            : (int) config('fulfillment_automation.dispatch.batch_size', 10);

        $fulfillments = Fulfillment::query()
            ->where('provider', 'like', 'browser:%')
            ->where('status', FulfillmentStatus::Queued)
            ->whereNull('claimed_by')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($fulfillments as $fulfillment) {
            if (! $automationService->isEligible($fulfillment)) {
                continue;
            }

            DispatchFulfillmentAutomationJob::dispatch($fulfillment->id);
            $dispatched++;
        }

        $this->info(sprintf('Dispatched %d automation job(s).', $dispatched));

        return self::SUCCESS;
    }
}
