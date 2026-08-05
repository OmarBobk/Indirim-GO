<?php

declare(strict_types=1);

namespace App\Console\Commands\Fulfillments;

use App\Actions\Fulfillments\RunWasimHealthProbe;
use App\Services\FulfillmentAutomationService;
use Illuminate\Console\Command;

class ProbeWasimHealthCommand extends Command
{
    protected $signature = 'fulfillment:probe-wasim-health {--force : Bypass the probe result cache window}';

    protected $description = 'Run the Wasim worker health probe (session, UI, contract versions) — never mutates fulfillments';

    public function handle(FulfillmentAutomationService $automationService, RunWasimHealthProbe $action): int
    {
        if (! $automationService->isEnabled()) {
            $this->line('Fulfillment automation is disabled.');

            return self::SUCCESS;
        }

        if (! (bool) config('fulfillment_automation.wasim_probe.enabled', true)) {
            $this->line('Wasim health probe is disabled.');

            return self::SUCCESS;
        }

        $snapshot = $action->handle(force: (bool) $this->option('force'));
        $state = $snapshot['last_result']['state'] ?? 'unknown';

        $this->info(sprintf('Wasim health probe result: %s', $state));

        return self::SUCCESS;
    }
}
