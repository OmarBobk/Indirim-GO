<?php

declare(strict_types=1);

namespace App\Console\Commands\Commissions;

use App\Enums\CommissionClawbackStatus;
use App\Enums\WalletTransactionType;
use App\Jobs\ProcessCommissionClawbackJob;
use App\Models\CommissionClawback;
use App\Models\WalletTransaction;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackPolicy;
use App\Support\Commissions\CommissionClawbackRetryEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SweepStaleCommissionClawbacksCommand extends Command
{
    protected $signature = 'commission-clawbacks:sweep-stale
                            {--limit=50 : Maximum rows to inspect}
                            {--dry-run : Report without mutating}
                            {--clawback= : Optional CLB-* public reference}';

    protected $description = 'Recover stale commission clawback processing rows without mutating wallets directly';

    public function handle(CommissionClawbackRetryEligibility $eligibility): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $ref = $this->option('clawback');

        $minutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));
        $cutoff = now()->subMinutes($minutes);

        $query = CommissionClawback::query()
            ->where('status', CommissionClawbackStatus::Processing)
            ->whereNull('reversal_wallet_transaction_id')
            ->where(function ($builder) use ($cutoff): void {
                $builder->whereNull('attempted_at')
                    ->orWhere('attempted_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit);

        if (is_string($ref) && trim($ref) !== '') {
            $normalized = CommissionClawbackPublicRef::normalize($ref);
            if (! CommissionClawbackPublicRef::isValidFormat($normalized)) {
                $this->error('Malformed clawback public reference.');

                return self::FAILURE;
            }
            $query->where('public_ref', $normalized);
        }

        $ids = $query->pluck('id')->all();
        $inspected = count($ids);
        $recovered = 0;
        $quarantined = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $outcome = $this->processOne((int) $id, $dryRun, $eligibility);
            if ($outcome === 'recovered') {
                $recovered++;
            } elseif ($outcome === 'quarantined') {
                $quarantined++;
            } else {
                $skipped++;
            }
        }

        $this->info("Inspected: {$inspected}; recovered: {$recovered}; quarantined: {$quarantined}; skipped: {$skipped}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function processOne(int $id, bool $dryRun, CommissionClawbackRetryEligibility $eligibility): string
    {
        return DB::transaction(function () use ($id, $dryRun, $eligibility): string {
            /** @var CommissionClawback|null $clawback */
            $clawback = CommissionClawback::query()->whereKey($id)->lockForUpdate()->first();
            if ($clawback === null) {
                return 'skipped';
            }

            if (! $eligibility->isStaleProcessing($clawback)) {
                return 'skipped';
            }

            if ((new \App\Support\Commissions\CommissionClawbackDisputeState)->hasActiveDispute($clawback)) {
                return 'skipped';
            }

            $existingReversal = WalletTransaction::query()
                ->where('type', WalletTransactionType::CommissionReversal)
                ->where('status', WalletTransaction::STATUS_POSTED)
                ->where('idempotency_key', CommissionClawbackPolicy::reversalIdempotencyKey(
                    (int) $clawback->commission_id,
                    (int) $clawback->refund_wallet_transaction_id,
                ))
                ->first();

            if ($existingReversal !== null) {
                if ($dryRun) {
                    return 'quarantined';
                }

                // Orphaned posted reversal while obligation still processing — do not auto-link;
                // quarantine for admin review (M7.2.1 avoids ambiguous financial repair).
                $clawback->forceFill([
                    'status' => CommissionClawbackStatus::NeedsReview,
                    'failure_code' => 'orphaned_reversal',
                    'failure_message_safe' => 'A matching reversal exists while obligation was still processing.',
                    'needs_review_at' => $clawback->needs_review_at ?? now(),
                ])->save();

                Log::warning('commission.clawback.stale_orphaned_reversal', [
                    'clawback_id' => $clawback->id,
                    'reversal_id' => $existingReversal->id,
                ]);

                return 'quarantined';
            }

            if ($dryRun) {
                return 'recovered';
            }

            $clawback->forceFill([
                'status' => CommissionClawbackStatus::Pending,
                'failure_code' => null,
                'failure_message_safe' => null,
                'last_retry_at' => now(),
                'retry_count' => ((int) $clawback->retry_count) + 1,
            ])->save();

            $clawbackId = (int) $clawback->id;
            DB::afterCommit(static function () use ($clawbackId): void {
                ProcessCommissionClawbackJob::dispatch($clawbackId);
                AdminOpsBroadcaster::dispatch('clawback-stale-recovered');
            });

            return 'recovered';
        });
    }
}
