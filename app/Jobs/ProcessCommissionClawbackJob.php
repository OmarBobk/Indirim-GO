<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Commissions\ProcessCommissionClawback;
use App\Enums\CommissionClawbackStatus;
use App\Models\CommissionClawback;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCommissionClawbackJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 300;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(public int $clawbackId) {}

    public function uniqueId(): string
    {
        return 'commission-clawback:'.$this->clawbackId;
    }

    public function handle(ProcessCommissionClawback $action): void
    {
        $action->handle($this->clawbackId);
    }

    public function failed(?\Throwable $exception): void
    {
        $clawback = CommissionClawback::query()->find($this->clawbackId);

        if ($clawback === null) {
            return;
        }

        if (in_array($clawback->status, [
            CommissionClawbackStatus::Posted,
            CommissionClawbackStatus::NeedsReview,
        ], true)) {
            return;
        }

        $clawback->forceFill([
            'status' => CommissionClawbackStatus::NeedsReview,
            'failure_code' => 'job_exhausted',
            'failure_message_safe' => 'Automatic clawback processing failed after retries.',
            'needs_review_at' => $clawback->needs_review_at ?? now(),
        ])->save();

        Log::error('commission.clawback.job_failed', [
            'clawback_id' => $this->clawbackId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
