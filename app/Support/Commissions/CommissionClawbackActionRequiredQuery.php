<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackStatus;
use App\Models\CommissionClawback;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Bounded action-required clawback query helpers (M7.2.1).
 * Counts needs_review + stale processing + failed/retryable without double-counting.
 */
final class CommissionClawbackActionRequiredQuery
{
    /**
     * @return Builder<CommissionClawback>
     */
    public static function actionRequired(?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $minutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));
        $staleCutoff = Carbon::instance($now)->subMinutes($minutes);
        $retryable = CommissionClawbackFailurePresentation::RETRYABLE_CODES;

        return CommissionClawback::query()->where(function (Builder $builder) use ($staleCutoff, $retryable): void {
            $builder->where('status', CommissionClawbackStatus::NeedsReview)
                ->orWhere(function (Builder $failed) use ($retryable): void {
                    $failed->where('status', CommissionClawbackStatus::Failed)
                        ->whereIn('failure_code', $retryable);
                })
                ->orWhere(function (Builder $stale) use ($staleCutoff): void {
                    $stale->where('status', CommissionClawbackStatus::Processing)
                        ->whereNull('reversal_wallet_transaction_id')
                        ->where(function (Builder $attempted) use ($staleCutoff): void {
                            $attempted->whereNull('attempted_at')
                                ->orWhere('attempted_at', '<=', $staleCutoff);
                        });
                })
                ->orWhereHas('decisions', function (Builder $disputeQuery): void {
                    $disputeQuery
                        ->where('type', CommissionClawbackDecisionType::DisputeOpened)
                        ->where('status', CommissionClawbackDecisionStatus::Open)
                        ->whereDoesntHave('children', function (Builder $childQuery): void {
                            $childQuery->where('type', CommissionClawbackDecisionType::DisputeResolved);
                        });
                });
        });
    }

    public static function needsReviewCount(): int
    {
        return CommissionClawback::query()
            ->where('status', CommissionClawbackStatus::NeedsReview)
            ->count();
    }

    public static function retryableCount(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $minutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));
        $staleCutoff = Carbon::instance($now)->subMinutes($minutes);

        return CommissionClawback::query()
            ->where(function (Builder $builder) use ($staleCutoff): void {
                $builder->where(function (Builder $inner) use ($staleCutoff): void {
                    $inner->where('status', CommissionClawbackStatus::Processing)
                        ->whereNull('reversal_wallet_transaction_id')
                        ->where(function (Builder $attempted) use ($staleCutoff): void {
                            $attempted->whereNull('attempted_at')
                                ->orWhere('attempted_at', '<=', $staleCutoff);
                        });
                })->orWhere(function (Builder $inner): void {
                    $inner->whereIn('status', [
                        CommissionClawbackStatus::NeedsReview,
                        CommissionClawbackStatus::Failed,
                    ])->whereIn('failure_code', CommissionClawbackFailurePresentation::RETRYABLE_CODES);
                });
            })
            ->count();
    }

    public static function staleProcessingCount(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $minutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));
        $staleCutoff = Carbon::instance($now)->subMinutes($minutes);

        return CommissionClawback::query()
            ->where('status', CommissionClawbackStatus::Processing)
            ->whereNull('reversal_wallet_transaction_id')
            ->where(function (Builder $builder) use ($staleCutoff): void {
                $builder->whereNull('attempted_at')
                    ->orWhere('attempted_at', '<=', $staleCutoff);
            })
            ->count();
    }

    public static function isActionRequired(
        CommissionClawbackStatus|string $status,
        bool $isStale,
        ?string $failureCode = null,
        bool $hasActiveDispute = false,
    ): bool {
        if ($hasActiveDispute) {
            return true;
        }

        $statusValue = $status instanceof CommissionClawbackStatus ? $status : CommissionClawbackStatus::tryFrom((string) $status);

        if ($isStale) {
            return true;
        }

        if ($statusValue === CommissionClawbackStatus::NeedsReview) {
            return true;
        }

        if ($statusValue === CommissionClawbackStatus::Failed
            && CommissionClawbackFailurePresentation::isRetryableCode($failureCode)) {
            return true;
        }

        return false;
    }
}
