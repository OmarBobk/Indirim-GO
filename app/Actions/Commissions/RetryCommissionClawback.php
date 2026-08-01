<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackStatus;
use App\Jobs\ProcessCommissionClawbackJob;
use App\Models\CommissionClawback;
use App\Models\User;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackRetryEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-authorized redispatch of an existing ProcessCommissionClawbackJob (M7.2.1).
 * Does not post money — WalletLedger remains inside ProcessCommissionClawback only.
 *
 * @return array{outcome: string, clawback: CommissionClawback, message_key: string}
 */
final class RetryCommissionClawback
{
    public function __construct(
        private readonly CommissionClawbackRetryEligibility $eligibility = new CommissionClawbackRetryEligibility,
    ) {}

    /**
     * @return array{outcome: 'queued'|'denied'|'already_posted'|'already_processing', clawback: CommissionClawback, message_key: string}
     */
    public function handle(User $actor, CommissionClawback|string $clawbackOrPublicRef): array
    {
        if (! $actor->can('process_commission_clawbacks')) {
            throw new AuthorizationException(__('messages.clawback_retry_unauthorized'));
        }

        $clawback = $this->resolve($clawbackOrPublicRef);

        return DB::transaction(function () use ($actor, $clawback): array {
            /** @var CommissionClawback $locked */
            $locked = CommissionClawback::query()
                ->whereKey($clawback->id)
                ->lockForUpdate()
                ->firstOrFail();

            $decision = $this->eligibility->decide($locked);

            if ($locked->status === CommissionClawbackStatus::Posted || $locked->reversal_wallet_transaction_id !== null) {
                $this->recordAudit($actor, $locked, $locked->status, $locked->status, 'already_posted', denied: true);

                return [
                    'outcome' => 'already_posted',
                    'clawback' => $locked->refresh(),
                    'message_key' => 'messages.clawback_retry_already_posted',
                ];
            }

            if (! $decision->allowed) {
                if ($decision->reasonCode === 'still_processing') {
                    return [
                        'outcome' => 'already_processing',
                        'clawback' => $locked->refresh(),
                        'message_key' => 'messages.clawback_retry_still_processing',
                    ];
                }

                $this->recordAudit($actor, $locked, $locked->status, $locked->status, $decision->reasonCode, denied: true);

                return [
                    'outcome' => 'denied',
                    'clawback' => $locked->refresh(),
                    'message_key' => $decision->safeExplanationKey,
                ];
            }

            $previousStatus = $locked->status;
            $wasStale = $decision->isStale;

            $locked->forceFill([
                'status' => CommissionClawbackStatus::Pending,
                'failure_code' => null,
                'failure_message_safe' => null,
                'last_retry_at' => now(),
                'retry_count' => ((int) $locked->retry_count) + 1,
            ])->save();

            $this->recordAudit(
                $actor,
                $locked,
                $previousStatus,
                CommissionClawbackStatus::Pending,
                $decision->reasonCode,
                denied: false,
                stale: $wasStale,
            );

            $clawbackId = (int) $locked->id;
            DB::afterCommit(static function () use ($clawbackId): void {
                ProcessCommissionClawbackJob::dispatch($clawbackId);
                AdminOpsBroadcaster::dispatch('clawback-retry-queued');
            });

            return [
                'outcome' => 'queued',
                'clawback' => $locked->refresh(),
                'message_key' => 'messages.clawback_retry_queued',
            ];
        });
    }

    private function resolve(CommissionClawback|string $clawbackOrPublicRef): CommissionClawback
    {
        if ($clawbackOrPublicRef instanceof CommissionClawback) {
            return $clawbackOrPublicRef;
        }

        $ref = CommissionClawbackPublicRef::normalize($clawbackOrPublicRef);
        abort_unless(CommissionClawbackPublicRef::isValidFormat($ref), 404);

        return CommissionClawback::query()->where('public_ref', $ref)->firstOrFail();
    }

    private function recordAudit(
        User $actor,
        CommissionClawback $clawback,
        CommissionClawbackStatus|string $previous,
        CommissionClawbackStatus|string $next,
        string $reasonCode,
        bool $denied,
        bool $stale = false,
    ): void {
        $previousValue = $previous instanceof CommissionClawbackStatus ? $previous->value : $previous;
        $nextValue = $next instanceof CommissionClawbackStatus ? $next->value : $next;

        $event = $denied
            ? 'commission.clawback.retry_denied'
            : ($stale ? 'commission.clawback.stale_recovered' : 'commission.clawback.retry_requested');

        try {
            app(\App\Services\SystemEventService::class)->record(
                $event,
                $clawback,
                $actor,
                [
                    'clawback_public_ref' => $clawback->public_ref,
                    'previous_status' => $previousValue,
                    'new_status' => $nextValue,
                    'reason_code' => $reasonCode,
                    'denied' => $denied,
                ],
                $denied ? 'warning' : 'info',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('commission.clawback.retry_audit_failed', [
                'clawback_id' => $clawback->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (! $denied && Schema::hasTable('activity_log')) {
            try {
                activity()
                    ->inLog('payments')
                    ->event($event)
                    ->performedOn($clawback)
                    ->causedBy($actor)
                    ->withProperties([
                        'clawback_public_ref' => $clawback->public_ref,
                        'previous_status' => $previousValue,
                        'new_status' => $nextValue,
                        'reason_code' => $reasonCode,
                    ])
                    ->log($denied ? 'Commission clawback retry denied' : 'Commission clawback retry requested');
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.retry_activity_failed', [
                    'clawback_id' => $clawback->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
