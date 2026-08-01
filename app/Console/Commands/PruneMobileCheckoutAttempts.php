<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MobileCheckoutAttemptStatus;
use App\Models\MobileCheckoutAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prunes terminal mobile checkout attempts older than the configured retention.
 *
 * Retries after retention are no longer guaranteed to replay — clients must use a
 * fresh Idempotency-Key for new purchases once the retention window has elapsed.
 */
class PruneMobileCheckoutAttempts extends Command
{
    protected $signature = 'mobile-checkout:prune-attempts
                            {--hours= : Delete terminal attempts older than this many hours}
                            {--dry-run : Count rows that would be deleted without deleting}';

    protected $description = 'Prune completed/failed mobile checkout attempts older than retention';

    public function handle(): int
    {
        $hoursOption = $this->option('hours');
        $hours = $hoursOption !== null && ctype_digit((string) $hoursOption)
            ? (int) $hoursOption
            : (int) config('mobile_api.checkout.idempotency_retention_hours', 72);

        if ($hours < 1) {
            $this->error('Hours must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');

        $query = MobileCheckoutAttempt::query()
            ->whereIn('status', [
                MobileCheckoutAttemptStatus::Completed->value,
                MobileCheckoutAttemptStatus::Failed->value,
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
                'Would prune %d terminal mobile checkout attempt(s) older than %d hour(s).',
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

            $deleted += MobileCheckoutAttempt::query()->whereIn('id', $ids->all())->delete();
        }

        $this->info(sprintf(
            'Pruned %d terminal mobile checkout attempt(s) older than %d hour(s).',
            $deleted,
            $hours,
        ));

        return self::SUCCESS;
    }
}
