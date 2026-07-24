<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SupplierPrices\FailStaleSupplierPriceScans;
use App\Services\SupplierPriceScanService;
use Illuminate\Console\Command;

class SweepStaleSupplierPriceScansCommand extends Command
{
    protected $signature = 'wasim:sweep-stale-price-scans
                            {--seconds= : Stale threshold in seconds}';

    protected $description = 'Fail Wasim supplier price scans stuck past the run timeout';

    public function handle(
        FailStaleSupplierPriceScans $failStaleScans,
        SupplierPriceScanService $scanService,
    ): int {
        if (! $scanService->isEnabled()) {
            $this->line('Supplier price scan is disabled.');

            return self::SUCCESS;
        }

        $secondsOption = $this->option('seconds');
        $timeoutSeconds = $secondsOption !== null && ctype_digit((string) $secondsOption)
            ? (int) $secondsOption
            : (int) config('fulfillment_automation.price_scan.run_timeout_seconds', 3600);

        $failed = $failStaleScans->handle($timeoutSeconds);

        if ($failed->isEmpty()) {
            $this->info('No stale supplier price scans found.');

            return self::SUCCESS;
        }

        foreach ($failed as $scan) {
            $this->warn(sprintf(
                'Failed stale scan %s (status was non-terminal past %d seconds).',
                $scan->uuid,
                $timeoutSeconds,
            ));
        }

        $this->info(sprintf('Swept %d stale supplier price scan(s).', $failed->count()));

        return self::SUCCESS;
    }
}
