<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fulfillments\FailFulfillment;
use App\Enums\AutomationRunLiveness;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use App\Support\Automation\AutomationRunLivenessClassifier;
use Illuminate\Console\Command;

class SweepStaleFulfillmentAutomationRunsCommand extends Command
{
    protected $signature = 'fulfillment:sweep-stale-automation-runs {--minutes= : Legacy fallback stale threshold override in minutes}';

    protected $description = 'Fail fulfillments with automation runs stuck past the timeout';

    public function handle(
        FulfillmentAutomationService $automationService,
        AutomationRunLivenessClassifier $livenessClassifier,
    ): int {
        if (! $automationService->isEnabled()) {
            $this->line('Fulfillment automation is disabled.');

            return self::SUCCESS;
        }

        $minutesOption = $this->option('minutes');

        if ($minutesOption !== null && ctype_digit((string) $minutesOption)) {
            config(['fulfillment_automation.liveness.legacy_fallback_stale_minutes' => (int) $minutesOption]);
        }

        // Reserved runs are never dispatched yet, so they are excluded here by design.
        $activeRuns = FulfillmentAutomationRun::query()
            ->whereIn('status', [
                FulfillmentAutomationRunStatus::Dispatched,
                FulfillmentAutomationRunStatus::Running,
            ])
            ->get();

        $swept = 0;

        foreach ($activeRuns as $run) {
            if ($livenessClassifier->classifyActiveWorkerRun($run) !== AutomationRunLiveness::Stale) {
                continue;
            }

            $fulfillment = Fulfillment::query()->find($run->fulfillment_id);

            if ($fulfillment === null) {
                continue;
            }

            // A fulfillment already waiting on a scheduled Wasim reconcile is not
            // "stuck" — it is intentionally idle between reconcile attempts.
            if ($livenessClassifier->isWaitingSupplier($fulfillment)) {
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
                'Automation timed out.',
                'automation',
                null,
            );

            $swept++;
        }

        $this->info(sprintf('Swept %d stale automation run(s).', $swept));

        return self::SUCCESS;
    }
}
