<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fulfillments\FailFulfillment;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SweepStaleFulfillmentAutomationRunsCommand extends Command
{
    protected $signature = 'fulfillment:sweep-stale-automation-runs {--minutes= : Stale threshold in minutes}';

    protected $description = 'Fail fulfillments with automation runs stuck past the timeout';

    public function handle(FulfillmentAutomationService $automationService): int
    {
        if (! $automationService->isEnabled()) {
            $this->line('Fulfillment automation is disabled.');

            return self::SUCCESS;
        }

        $minutes = $this->option('minutes');
        $minutes = $minutes !== null && ctype_digit((string) $minutes)
            ? (int) $minutes
            : (int) config('fulfillment_automation.timeouts.stale_sweep_minutes', 30);

        $cutoff = Carbon::now()->subMinutes($minutes);

        $staleRuns = FulfillmentAutomationRun::query()
            ->whereIn('status', [
                FulfillmentAutomationRunStatus::Dispatched,
                FulfillmentAutomationRunStatus::Running,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where('started_at', '<=', $cutoff)
                    ->orWhere(function ($sub) use ($cutoff): void {
                        $sub->whereNull('started_at')
                            ->where('dispatched_at', '<=', $cutoff);
                    });
            })
            ->get();

        $swept = 0;

        foreach ($staleRuns as $run) {
            $fulfillment = Fulfillment::query()->find($run->fulfillment_id);

            if ($fulfillment === null) {
                continue;
            }

            $run->fill([
                'status' => FulfillmentAutomationRunStatus::NeedsReview,
                'finished_at' => now(),
                'error_code' => 'automation_timeout',
                'error_message' => 'Automation run exceeded timeout.',
            ])->save();

            $meta = $fulfillment->meta ?? [];
            $meta['automation'] = array_merge($meta['automation'] ?? [], [
                'requires_review' => true,
                'last_run_uuid' => $run->uuid,
            ]);
            $fulfillment->update(['meta' => $meta]);

            app(FailFulfillment::class)->handle(
                $fulfillment,
                'Automation timed out after '.$minutes.' minutes.',
                'automation',
                null,
            );

            $swept++;
        }

        $this->info(sprintf('Swept %d stale automation run(s).', $swept));

        return self::SUCCESS;
    }
}
