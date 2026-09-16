<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MobileTopupAttemptStatus;
use App\Models\MobileTopupAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneMobileTopupAttempts extends Command
{
    protected $signature = 'mobile-topup:prune-attempts
                            {--hours= : Delete terminal attempts older than this many hours}
                            {--dry-run : Count rows that would be deleted without deleting}';

    protected $description = 'Prune completed/failed mobile top-up attempts older than retention';

    public function handle(): int
    {
        $hoursOption = $this->option('hours');
        $hours = $hoursOption !== null && ctype_digit((string) $hoursOption)
            ? (int) $hoursOption
            : (int) config('mobile_api.topup.idempotency_retention_hours', 72);

        if ($hours < 1) {
            $this->error('Hours must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $query = MobileTopupAttempt::query()
            ->whereIn('status', [
                MobileTopupAttemptStatus::Completed->value,
                MobileTopupAttemptStatus::Failed->value,
            ])
            ->where(function ($builder) use ($cutoff): void {
                $builder->where(function ($inner) use ($cutoff): void {
                    $inner->whereNotNull('completed_at')
                        ->where('completed_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff): void {
                    $inner->whereNull('completed_at')
                        ->where('created_at', '<=', $cutoff);
                });
            });

        $count = (clone $query)->count();

        if ($dryRun) {
            $this->info(sprintf(
                'Would prune %d terminal mobile top-up attempt(s) older than %d hour(s).',
                $count,
                $hours,
            ));

            return self::SUCCESS;
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $query)
                ->orderBy('id')
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += MobileTopupAttempt::query()->whereIn('id', $ids->all())->delete();
        }

        $this->info(sprintf(
            'Pruned %d terminal mobile top-up attempt(s) older than %d hour(s).',
            $deleted,
            $hours,
        ));

        return self::SUCCESS;
    }
}
