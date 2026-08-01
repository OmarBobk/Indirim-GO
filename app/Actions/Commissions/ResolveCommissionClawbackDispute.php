<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackDisputeResolution;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Jobs\ProcessCommissionClawbackJob;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\User;
use App\Notifications\CommissionClawbackDisputeResolvedNotification;
use App\Services\SystemEventService;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackDecisionPublicRef;
use App\Support\Commissions\CommissionClawbackDisputeEligibility;
use App\Support\Commissions\CommissionClawbackDisputeState;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Resolve an active clawback dispute (M7.2.3).
 * Accepted paths delegate to WaiveCommissionClawback / CorrectCommissionClawback after recording resolution.
 *
 * @return array{
 *     outcome: string,
 *     clawback: CommissionClawback,
 *     decision: ?CommissionClawbackDecision,
 *     financial_decision: ?CommissionClawbackDecision,
 *     message_key: string,
 *     was_replayed: bool
 * }
 */
final class ResolveCommissionClawbackDispute
{
    public function __construct(
        private readonly CommissionClawbackDisputeEligibility $eligibility = new CommissionClawbackDisputeEligibility,
        private readonly CommissionClawbackDisputeState $disputeState = new CommissionClawbackDisputeState,
        private readonly WaiveCommissionClawback $waive = new WaiveCommissionClawback,
        private readonly CorrectCommissionClawback $correct = new CorrectCommissionClawback,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     clawback: CommissionClawback,
     *     decision: ?CommissionClawbackDecision,
     *     financial_decision: ?CommissionClawbackDecision,
     *     message_key: string,
     *     was_replayed: bool
     * }
     */
    public function handle(
        User $actor,
        CommissionClawback $clawback,
        string $resolutionCode,
        ?string $adminNote = null,
        ?string $safeResolutionSummary = null,
        ?string $financialReasonCode = null,
        ?string $financialAmount = null,
        ?string $idempotencyToken = null,
    ): array {
        if (! $actor->can('manage_commission_clawback_disputes')) {
            throw new AuthorizationException(__('messages.clawback_dispute_unauthorized'));
        }

        $resolution = CommissionClawbackDisputeResolution::tryFrom(trim($resolutionCode));
        if ($resolution === null) {
            return $this->result($clawback, 'denied', null, null, 'messages.clawback_dispute_invalid_resolution');
        }

        if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsWaiver
            && ! $actor->can('waive_commission_clawbacks')
        ) {
            throw new AuthorizationException(__('messages.clawback_waiver_unauthorized'));
        }

        if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsCorrection
            && ! $actor->can('correct_commission_clawbacks')
        ) {
            throw new AuthorizationException(__('messages.clawback_correction_unauthorized'));
        }

        $note = $this->sanitizeNote($adminNote);
        $summary = $this->sanitizeSummary($safeResolutionSummary);
        $token = $this->normalizeToken($idempotencyToken, 'dispute-resolve');

        try {
            return DB::transaction(function () use (
                $actor,
                $clawback,
                $resolution,
                $note,
                $summary,
                $financialReasonCode,
                $financialAmount,
                $token,
            ): array {
                /** @var CommissionClawback $locked */
                $locked = CommissionClawback::query()->whereKey($clawback->id)->lockForUpdate()->firstOrFail();

                $existing = CommissionClawbackDecision::query()
                    ->where('idempotency_key', $token)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->commission_clawback_id !== (int) $locked->id
                        || $existing->type !== CommissionClawbackDecisionType::DisputeResolved
                    ) {
                        return $this->result($locked, 'denied', null, null, 'messages.clawback_dispute_idempotency_conflict');
                    }

                    return $this->result(
                        $locked->refresh(),
                        'replayed',
                        $existing,
                        null,
                        'messages.clawback_dispute_resolved',
                        true,
                    );
                }

                CommissionClawbackDecision::query()
                    ->where('commission_clawback_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $eligibility = $this->eligibility->decideResolve($locked);
                if (! $eligibility->allowed || $eligibility->activeDisputeId === null) {
                    return $this->result(
                        $locked,
                        'denied',
                        null,
                        null,
                        $eligibility->safeDenialKey !== '' ? $eligibility->safeDenialKey : 'messages.clawback_dispute_none_open',
                    );
                }

                $open = CommissionClawbackDecision::query()
                    ->whereKey($eligibility->activeDisputeId)
                    ->lockForUpdate()
                    ->first();

                if ($open === null
                    || $open->type !== CommissionClawbackDecisionType::DisputeOpened
                    || $open->status !== CommissionClawbackDecisionStatus::Open
                ) {
                    return $this->result($locked, 'denied', null, null, 'messages.clawback_dispute_none_open');
                }

                if ($this->disputeState->activeOpenDispute($locked)?->id !== $open->id) {
                    return $this->result($locked, 'denied', null, null, 'messages.clawback_dispute_none_open');
                }

                $financialDecision = null;
                $redispatch = false;

                if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsWaiver) {
                    if ($financialReasonCode === null || trim($financialReasonCode) === '') {
                        return $this->result($locked, 'denied', null, null, 'messages.clawback_waiver_invalid_reason');
                    }

                    $waiveResult = $this->waive->handle(
                        $actor,
                        $locked,
                        $financialReasonCode,
                        $financialAmount,
                        $note,
                        'from-dispute-'.$token,
                        allowWhileDisputed: true,
                    );

                    if (! in_array($waiveResult['outcome'], ['waived', 'replayed', 'already_waived'], true)) {
                        return $this->result(
                            $locked->refresh(),
                            'denied',
                            null,
                            null,
                            $waiveResult['message_key'],
                        );
                    }

                    $financialDecision = $waiveResult['decision'];
                    $locked = $waiveResult['clawback'];
                }

                if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsCorrection) {
                    if ($financialReasonCode === null || trim($financialReasonCode) === '') {
                        return $this->result($locked, 'denied', null, null, 'messages.clawback_correction_invalid_reason');
                    }

                    $correctResult = $this->correct->handle(
                        $actor,
                        $locked,
                        $financialReasonCode,
                        $financialAmount,
                        $note,
                        'from-dispute-'.$token,
                        null,
                    );

                    if (! in_array($correctResult['outcome'], ['corrected', 'replayed'], true)) {
                        return $this->result(
                            $locked->refresh(),
                            'denied',
                            null,
                            null,
                            $correctResult['message_key'],
                        );
                    }

                    $financialDecision = $correctResult['decision'];
                    $locked = $correctResult['clawback'];
                }

                if (in_array($resolution, [
                    CommissionClawbackDisputeResolution::Rejected,
                    CommissionClawbackDisputeResolution::Withdrawn,
                ], true)) {
                    $isUnposted = $locked->reversal_wallet_transaction_id === null
                        && $locked->status !== CommissionClawbackStatus::Posted
                        && $locked->status !== CommissionClawbackStatus::Waived;

                    if ($isUnposted
                        && in_array($locked->status, [
                            CommissionClawbackStatus::Pending,
                            CommissionClawbackStatus::Processing,
                            CommissionClawbackStatus::Failed,
                            CommissionClawbackStatus::NeedsReview,
                        ], true)
                    ) {
                        // Resume only when operationally safe: leave needs_review/failed as-is for explicit retry;
                        // pending/processing → pending for optional redispatch.
                        if (in_array($locked->status, [
                            CommissionClawbackStatus::Pending,
                            CommissionClawbackStatus::Processing,
                        ], true)) {
                            $locked->forceFill([
                                'status' => CommissionClawbackStatus::Pending,
                                'failure_code' => null,
                                'failure_message_safe' => null,
                            ])->save();
                            $redispatch = true;
                        }
                    }
                }

                /** @var CommissionClawbackDecision $resolutionDecision */
                $resolutionDecision = CommissionClawbackDecisionPublicRef::withUniqueRetry(
                    function (string $publicRef) use (
                        $locked,
                        $open,
                        $resolution,
                        $note,
                        $summary,
                        $actor,
                        $token,
                        $financialDecision,
                    ): CommissionClawbackDecision {
                        return CommissionClawbackDecision::query()->create([
                            'public_ref' => $publicRef,
                            'commission_clawback_id' => $locked->id,
                            'parent_decision_id' => $open->id,
                            'type' => CommissionClawbackDecisionType::DisputeResolved,
                            'status' => CommissionClawbackDecisionStatus::Recorded,
                            'amount' => $financialDecision?->amount,
                            'reason_code' => $resolution->value,
                            'admin_note' => $note,
                            'safe_resolution_summary' => $summary,
                            'actor_id' => $actor->id,
                            // WTX uniqueness belongs to the financial decision row — do not duplicate here.
                            'related_wallet_transaction_id' => null,
                            'idempotency_key' => $token,
                            'decided_at' => now(),
                        ]);
                    }
                );

                if ($financialDecision !== null && $financialDecision->parent_decision_id === null) {
                    $financialDecision->forceFill([
                        'parent_decision_id' => $resolutionDecision->id,
                    ])->save();
                }

                $this->recordAudit($actor, $locked, $resolutionDecision, $resolution);
                $this->afterCommit($locked, $resolutionDecision, $redispatch);

                return $this->result(
                    $locked->refresh(),
                    'resolved',
                    $resolutionDecision,
                    $financialDecision?->refresh(),
                    'messages.clawback_dispute_resolved',
                );
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueIdempotencyConflict($exception)) {
                $existing = CommissionClawbackDecision::query()->where('idempotency_key', $token)->first();
                if ($existing !== null && (int) $existing->commission_clawback_id === (int) $clawback->id) {
                    return $this->result(
                        $clawback->fresh() ?? $clawback,
                        'replayed',
                        $existing,
                        null,
                        'messages.clawback_dispute_resolved',
                        true,
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *     outcome: string,
     *     clawback: CommissionClawback,
     *     decision: ?CommissionClawbackDecision,
     *     financial_decision: ?CommissionClawbackDecision,
     *     message_key: string,
     *     was_replayed: bool
     * }
     */
    private function result(
        CommissionClawback $clawback,
        string $outcome,
        ?CommissionClawbackDecision $decision,
        ?CommissionClawbackDecision $financialDecision,
        string $messageKey,
        bool $replayed = false,
    ): array {
        return [
            'outcome' => $outcome,
            'clawback' => $clawback,
            'decision' => $decision,
            'financial_decision' => $financialDecision,
            'message_key' => $messageKey,
            'was_replayed' => $replayed,
        ];
    }

    private function afterCommit(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        bool $redispatch,
    ): void {
        $salespersonId = (int) $clawback->salesperson_id;
        $decisionId = (int) $decision->id;
        $clawbackId = (int) $clawback->id;

        DB::afterCommit(function () use ($salespersonId, $decisionId, $clawbackId, $redispatch): void {
            try {
                $clawback = CommissionClawback::query()->find($clawbackId);
                $decision = CommissionClawbackDecision::query()->find($decisionId);
                if ($clawback === null || $decision === null) {
                    return;
                }

                $salesperson = User::query()->find($salespersonId);
                if ($salesperson !== null) {
                    $salesperson->notify(CommissionClawbackDisputeResolvedNotification::fromDecision($clawback, $decision));
                }

                CustomerFinancialBroadcaster::dispatch(
                    $salespersonId,
                    [CustomerFinancialInvalidationReason::CommissionStateChanged],
                );
                AdminOpsBroadcaster::dispatch('clawback-dispute-resolved');

                if ($redispatch && $clawback->status === CommissionClawbackStatus::Pending) {
                    ProcessCommissionClawbackJob::dispatch((int) $clawback->id);
                }
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.dispute_resolve_side_effects_failed', [
                    'clawback_id' => $clawbackId,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function recordAudit(
        User $actor,
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        CommissionClawbackDisputeResolution $resolution,
    ): void {
        try {
            app(SystemEventService::class)->record(
                'commission.clawback.dispute_resolved',
                $clawback,
                $actor,
                [
                    'clawback_public_ref' => $clawback->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                    'resolution' => $resolution->value,
                    'related_wallet_transaction_public_ref' => $decision->relatedWalletTransaction?->public_ref,
                ],
                'info',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('commission.clawback.dispute_resolve_audit_failed', [
                'clawback_id' => $clawback->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (Schema::hasTable('activity_log')) {
            try {
                activity()
                    ->inLog('payments')
                    ->event('commission.clawback.dispute_resolved')
                    ->performedOn($clawback)
                    ->causedBy($actor)
                    ->withProperties([
                        'clawback_public_ref' => $clawback->public_ref,
                        'decision_public_ref' => $decision->public_ref,
                        'resolution' => $resolution->value,
                        'admin_note' => $decision->admin_note,
                        'safe_resolution_summary' => $decision->safe_resolution_summary,
                    ])
                    ->log('Commission clawback dispute resolved');
            } catch (\Throwable) {
                // best-effort
            }
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

    private function sanitizeSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $trimmed = trim(strip_tags($summary));

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 300);
    }

    private function normalizeToken(?string $idempotencyToken, string $prefix): string
    {
        $token = is_string($idempotencyToken) ? trim($idempotencyToken) : '';
        if ($token === '' || mb_strlen($token) < 16 || mb_strlen($token) > 128) {
            return $prefix.':'.Str::uuid()->toString();
        }

        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $token)) {
            return $prefix.':'.Str::uuid()->toString();
        }

        return $prefix.':'.$token;
    }

    private function isUniqueIdempotencyConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
