<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\HistoricalCommissionExposureOutcome;
use App\Enums\HistoricalCommissionExposureReason;
use App\Models\HistoricalCommissionExposureReview;
use App\Models\User;
use App\Support\AdminOpsBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Non-financial review marker for historical commission exposure (M7.2.4).
 * Never touches wallets, commissions, refunds, or clawbacks.
 *
 * @return array{
 *     outcome: 'reviewed'|'denied'|'replayed',
 *     review: ?HistoricalCommissionExposureReview,
 *     message_key: string,
 *     was_replayed: bool
 * }
 */
final class ReviewHistoricalCommissionExposure
{
    public function __construct(
        private readonly GetHistoricalCommissionExposure $reader = new GetHistoricalCommissionExposure,
    ) {}

    /**
     * @return array{
     *     outcome: 'reviewed'|'denied'|'replayed',
     *     review: ?HistoricalCommissionExposureReview,
     *     message_key: string,
     *     was_replayed: bool
     * }
     */
    public function handle(
        User $actor,
        int $commissionId,
        int $refundWalletTransactionId,
        string $outcomeCode,
        string $reasonCode,
        ?string $adminNote = null,
    ): array {
        if (! $actor->can('view_historical_commission_exposure')) {
            throw new AuthorizationException(__('messages.historical_exposure_unauthorized'));
        }

        $outcome = HistoricalCommissionExposureOutcome::tryFrom(trim($outcomeCode));
        $reason = HistoricalCommissionExposureReason::tryFrom(trim($reasonCode));
        if ($outcome === null || $reason === null) {
            return $this->result('denied', null, 'messages.historical_exposure_invalid_outcome');
        }

        $note = $this->sanitizeNote($adminNote);

        return DB::transaction(function () use (
            $actor,
            $commissionId,
            $refundWalletTransactionId,
            $outcome,
            $reason,
            $note,
        ): array {
            $validated = $this->reader->revalidatePair($commissionId, $refundWalletTransactionId);
            if (! $validated['eligible']) {
                return $this->result('denied', null, 'messages.historical_exposure_not_eligible');
            }

            /** @var HistoricalCommissionExposureReview|null $existing */
            $existing = HistoricalCommissionExposureReview::query()
                ->where('commission_id', $commissionId)
                ->where('refund_wallet_transaction_id', $refundWalletTransactionId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null
                && $existing->outcome === $outcome
                && (string) $existing->reason_code === $reason->value
            ) {
                return $this->result('replayed', $existing, 'messages.historical_exposure_reviewed', true);
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'outcome' => $outcome,
                    'reason_code' => $reason->value,
                    'admin_note' => $note,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ])->save();
                $review = $existing->refresh();
            } else {
                $review = HistoricalCommissionExposureReview::query()->create([
                    'commission_id' => $commissionId,
                    'refund_wallet_transaction_id' => $refundWalletTransactionId,
                    'outcome' => $outcome,
                    'reason_code' => $reason->value,
                    'admin_note' => $note,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ]);
            }

            $this->audit($actor, $review);
            DB::afterCommit(static function (): void {
                try {
                    AdminOpsBroadcaster::dispatch('historical-exposure-reviewed');
                } catch (\Throwable) {
                    // best-effort
                }
            });

            return $this->result('reviewed', $review, 'messages.historical_exposure_reviewed');
        });
    }

    /**
     * @return array{outcome: string, review: ?HistoricalCommissionExposureReview, message_key: string, was_replayed: bool}
     */
    private function result(
        string $outcome,
        ?HistoricalCommissionExposureReview $review,
        string $messageKey,
        bool $replayed = false,
    ): array {
        return [
            'outcome' => $outcome,
            'review' => $review,
            'message_key' => $messageKey,
            'was_replayed' => $replayed,
        ];
    }

    private function audit(User $actor, HistoricalCommissionExposureReview $review): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        try {
            activity()
                ->inLog('payments')
                ->event('commission.historical_exposure.reviewed')
                ->performedOn($review)
                ->causedBy($actor)
                ->withProperties([
                    'commission_id' => $review->commission_id,
                    'refund_wallet_transaction_id' => $review->refund_wallet_transaction_id,
                    'outcome' => $review->outcome instanceof HistoricalCommissionExposureOutcome
                        ? $review->outcome->value
                        : (string) $review->outcome,
                    'reason_code' => (string) $review->reason_code,
                    'admin_note' => $review->admin_note,
                ])
                ->log('Historical commission exposure reviewed (non-financial)');
        } catch (\Throwable $exception) {
            Log::warning('historical_exposure.audit_failed', [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitizeNote(?string $adminNote): ?string
    {
        if ($adminNote === null) {
            return null;
        }

        $trimmed = trim(strip_tags($adminNote));

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 500);
    }
}
