<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SupplierPrices\StartSupplierPriceScan;
use App\Enums\SupplierPriceScanStatus;
use App\Models\SupplierPriceScan;
use Illuminate\Console\Command;

class ScanWasimPricesCommand extends Command
{
    protected $signature = 'wasim:scan-prices
                            {--package= : Limit scan to a package ID}
                            {--limit= : Maximum number of products to scan}
                            {--wait : Wait until the scan finishes}';

    protected $description = 'Dispatch a batched Wasim supplier price scan for automated products';

    public function handle(StartSupplierPriceScan $startScan): int
    {
        $packageId = $this->option('package') !== null ? (int) $this->option('package') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        try {
            $scan = $startScan->handle($packageId, $limit, 'command');
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Created scan {$scan->uuid} for {$scan->products_total} product(s).");
        $this->info('Scan dispatched to automation worker.');

        if (! $this->option('wait')) {
            $this->line('Use --wait to block until completion.');

            return self::SUCCESS;
        }

        $timeoutSeconds = (int) config('fulfillment_automation.price_scan.run_timeout_seconds', 3600);
        $deadline = now()->addSeconds($timeoutSeconds);

        while (now()->lt($deadline)) {
            $scan->refresh();

            if ($scan->status->isTerminal()) {
                $this->renderSummary($scan->fresh());

                return $scan->status === SupplierPriceScanStatus::Completed
                    ? self::SUCCESS
                    : self::FAILURE;
            }

            sleep(2);
        }

        $this->error('Timed out waiting for scan to finish.');

        return self::FAILURE;
    }

    private function renderSummary(SupplierPriceScan $scan): void
    {
        $this->newLine();
        $this->info("Scan {$scan->uuid} finished with status: {$scan->status->value}");
        $this->line("OK: {$scan->products_ok} · Failed: {$scan->products_failed} · Total: {$scan->products_total}");

        if ($scan->status === SupplierPriceScanStatus::Failed) {
            $message = data_get($scan->meta, 'batch_message') ?? data_get($scan->meta, 'dispatch_error');

            if (is_string($message) && $message !== '') {
                $this->warn($message);
            }
        }
    }
}
