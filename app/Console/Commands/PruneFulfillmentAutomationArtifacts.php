<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneFulfillmentAutomationArtifacts extends Command
{
    protected $signature = 'fulfillment:prune-automation-artifacts
                            {--days= : Delete artifacts for terminal runs older than this many days}
                            {--dry-run : List what would be deleted without deleting}';

    protected $description = 'Delete stored automation screenshots for old terminal runs';

    public function handle(FulfillmentAutomationService $automationService): int
    {
        $days = $this->option('days');
        $days = $days !== null && ctype_digit((string) $days)
            ? (int) $days
            : (int) config('fulfillment_automation.artifacts.retention_days', 30);

        if ($days < 1) {
            $this->error('Days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $runs = FulfillmentAutomationRun::query()
            ->whereIn('status', FulfillmentAutomationRunStatus::terminalValues())
            ->where(function ($query) use ($cutoff): void {
                $query->where('finished_at', '<=', $cutoff)
                    ->orWhere(function ($sub) use ($cutoff): void {
                        $sub->whereNull('finished_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->get();

        $prunedRuns = 0;
        $deletedFiles = 0;

        foreach ($runs as $run) {
            $paths = $run->artifactPaths();

            if ($paths === []) {
                continue;
            }

            $directory = $automationService->artifactStorageDirectory($run->uuid);

            if ($dryRun) {
                $this->line("Would prune run #{$run->id} ({$run->uuid}): ".count($paths).' file(s)');

                $prunedRuns++;

                continue;
            }

            foreach ($paths as $path) {
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                    $deletedFiles++;
                }
            }

            if (Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->deleteDirectory($directory);
            }

            $meta = $run->meta ?? [];
            $meta['artifact_paths'] = [];
            $meta['artifacts_pruned_at'] = now()->toIso8601String();
            $run->update(['meta' => $meta]);

            $run->progressEvents()->delete();

            $prunedRuns++;
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->info("{$verb} {$prunedRuns} run(s)".($dryRun ? '' : ", deleted {$deletedFiles} file(s).")." Cutoff: {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
