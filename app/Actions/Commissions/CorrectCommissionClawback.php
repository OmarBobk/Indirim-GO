<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackCorrectionReason;
use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\CommissionReversalCorrectionPostedNotification;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackDecisionPublicRef;
use App\Support\Commissions\CommissionClawbackCorrectionEligibility;
use App\Support\Commissions\CommissionClawbackWaiverArithmetic;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\Financial\ReceiptSnapshot;
use App\Support\LedgerMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Posts an immutable commission_reversal_correction credit (M7.2.3).
 *
 * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
 */
final class CorrectCommissionClawback
{
    public function __construct(
        private readonly WalletLedger $ledger = new WalletLedger,
        private readonly CommissionClawbackCorrectionEligibility $eligibility = new CommissionClawbackCorrectionEligibility,
        private readonly CommissionClawbackWaiverArithmetic $arithmetic = new CommissionClawbackWaiverArithmetic,
    ) {}

    /**
     * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
     */
    public function handle(
        User $actor,
        CommissionClawback $clawback,
        string $reasonCode,
        ?string $requestedAmount = null,
        ?string $adminNote = null,
        ?string $idempotencyToken = null,
        ?int $parentDecisionId = null,
    ): array {
        if (! $actor->can('correct_commission_clawbacks')) {
            throw new AuthorizationException(__('messages.clawback_correction_unauthorized'));
        }

        $reason = CommissionClawbackCorrectionReason::tryFrom(trim($reasonCode));
        if ($reason === null) {
            return $this->result($clawback, 'denied', null, 'messages.clawback_correction_invalid_reason');
        }

        $note = $this->sanitizeNote($adminNote);
        $token = $this->normalizeToken($idempotencyToken);

        try {
            return DB::transaction(function () use (
                $actor,
                $clawback,
                $reason,
                $requestedAmount,
                $note,
                $token,
                $parentDecisionId,
            ): array {
                /** @var CommissionClawback $locked */
                $locked = CommissionClawback::query()->whereKey($clawback->id)->lockForUpdate()->firstOrFail();

                $existing = CommissionClawbackDecision::query()
                    ->where('idempotency_key', $token)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->commission_clawback_id !== (int) $locked->id
                        || $existing->type !== CommissionClawbackDecisionType::Correction
                    ) {
                        return $this->result($locked, 'denied', null, 'messages.clawback_correction_idempotency_conflict');
                    }

                    return $this->result($locked->refresh(), 'replayed', $existing, 'messages.clawback_correction_approved', true);
                }

                CommissionClawbackDecision::query()
                    ->where('commission_clawback_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $eligibility = $this->eligibility->decide($locked);
                if (! $eligibility->allowed) {
                    return $this->result(
                        $locked,
                        'denied',
                        null,
                        $eligibility->safeDenialKey !== '' ? $eligibility->safeDenialKey : 'messages.clawback_correction_unavailable',
                    );
                }

                $reversal = WalletTransaction::query()
                    ->whereKey($locked->reversal_wallet_transaction_id)
                    ->lockForUpdate()
                    ->first();

                if ($reversal === null
                    || $reversal->type !== WalletTransactionType::CommissionReversal
                    || $reversal->status !== WalletTransaction::STATUS_POSTED
                ) {
                    return $this->result($locked, 'denied', null, 'messages.clawback_correction_missing_reversal');
                }

                $remaining = $this->arithmetic->remainingCorrectable($locked);
                $amount = $this->resolveAmount($requestedAmount, $remaining, $eligibility->maximumAmount);
                if ($amount === null) {
                    return $this->result($locked, 'denied', null, 'messages.clawback_correction_invalid_amount');
                }

                $wallet = Wallet::query()->whereKey($reversal->wallet_id)->lockForUpdate()->firstOrFail();
                if ((int) $wallet->user_id !== (int) $locked->salesperson_id) {
                    return $this->result($locked, 'denied', null, 'messages.clawback_correction_wrong_wallet');
                }

                $parentId = null;
                if ($parentDecisionId !== null) {
                    $parent = CommissionClawbackDecision::query()->whereKey($parentDecisionId)->lockForUpdate()->first();
                    if ($parent === null || (int) $parent->commission_clawback_id !== (int) $locked->id) {
                        return $this->result($locked, 'denied', null, 'messages.clawback_correction_parent_conflict');
                    }
                    $parentId = (int) $parent->id;
                }

                /** @var CommissionClawbackDecision $decision */
                $decision = CommissionClawbackDecisionPublicRef::withUniqueRetry(
                    function (string $publicRef) use ($locked, $reason, $note, $actor, $token, $amount, $parentId): CommissionClawbackDecision {
                        return CommissionClawbackDecision::query()->create([
                            'public_ref' => $publicRef,
                            'commission_clawback_id' => $locked->id,
                            'parent_decision_id' => $parentId,
                            'type' => CommissionClawbackDecisionType::Correction,
                            'status' => CommissionClawbackDecisionStatus::Posted,
                            'amount' => $amount,
                            'reason_code' => $reason->value,
                            'admin_note' => $note,
                            'safe_resolution_summary' => null,
                            'actor_id' => $actor->id,
                            'related_wallet_transaction_id' => null,
                            'idempotency_key' => $token,
                            'decided_at' => now(),
                        ]);
                    }
                );

                $creditMeta = array_merge([
                    'commission_id' => $locked->commission_id,
                    'clawback_public_ref' => $locked->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                    'reversal_wallet_transaction_id' => $reversal->id,
                    'reversal_public_ref' => $reversal->public_ref,
                    'original_commission_credit_transaction_id' => $locked->original_commission_credit_transaction_id,
                    'policy_version' => $locked->policy_version,
                    'reason_code' => $reason->value,
                ], ReceiptSnapshot::wrap([
                    'currency' => strtoupper((string) ($locked->currency ?: 'USD')),
                    'source_title' => 'Commission reversal correction',
                    'customer_safe_reason' => 'The platform corrected part or all of a previous commission reversal.',
                    'clawback_public_ref' => $locked->public_ref,
                    'reversal_public_ref' => $reversal->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                ]));

                $result = $this->ledger->postCredit(
                    wallet: $wallet,
                    type: WalletTransactionType::CommissionReversalCorrection,
                    amount: $amount,
                    idempotencyKey: CommissionClawbackWaiverArithmetic::correctionWalletIdempotencyKey((int) $decision->id),
                    meta: $creditMeta,
                    referenceType: CommissionClawbackDecision::class,
                    referenceId: (int) $decision->id,
                );

                $credit = $result->transaction;
                $decision->forceFill([
                    'related_wallet_transaction_id' => $credit->id,
                    'status' => CommissionClawbackDecisionStatus::Posted,
                ])->save();

                // Corrections never flip obligation status to waived.
                if ($locked->status !== CommissionClawbackStatus::Posted) {
                    $locked->forceFill(['status' => CommissionClawbackStatus::Posted])->save();
                }

                $this->recordAudit($actor, $locked, $decision, $credit);
                $this->afterCommit($locked, $decision, $credit);

                return $this->result(
                    $locked->refresh(),
                    'corrected',
                    $decision->refresh(),
                    'messages.clawback_correction_approved',
                    $result->wasReplayed,
                );
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueIdempotencyConflict($exception)) {
                $existing = CommissionClawbackDecision::query()->where('idempotency_key', $token)->first();
                if ($existing !== null && (int) $existing->commission_clawback_id === (int) $clawback->id) {
                    return $this->result($clawback->fresh() ?? $clawback, 'replayed', $existing, 'messages.clawback_correction_approved', true);
                }
            }

            throw $exception;
        }
    }

    private function resolveAmount(?string $requestedAmount, string $remaining, string $maximum): ?string
    {
        $cap = LedgerMoney::compare($remaining, $maximum) === -1 ? $remaining : $maximum;

        if ($requestedAmount === null || trim($requestedAmount) === '') {
            return $cap;
        }

        try {
            $amount = LedgerMoney::normalizePositive($requestedAmount);
        } catch (\Throwable) {
            return null;
        }

        if (LedgerMoney::compare($amount, $cap) === 1) {
            return null;
        }

        return $amount;
    }

    /**
     * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
     */
    private function result(
        CommissionClawback $clawback,
        string $outcome,
        ?CommissionClawbackDecision $decision,
        string $messageKey,
        bool $replayed = false,
    ): array {
        return [
            'outcome' => $outcome,
            'clawback' => $clawback,
            'decision' => $decision,
            'message_key' => $messageKey,
            'was_replayed' => $replayed,
        ];
    }

    private function afterCommit(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        WalletTransaction $credit,
    ): void {
        $salespersonId = (int) $clawback->salesperson_id;
        $decisionId = (int) $decision->id;
        $clawbackId = (int) $clawback->id;
        $creditId = (int) $credit->id;

        DB::afterCommit(function () use ($salespersonId, $decisionId, $clawbackId, $creditId): void {
            try {
                $clawback = CommissionClawback::query()->find($clawbackId);
                $decision = CommissionClawbackDecision::query()->find($decisionId);
                $credit = WalletTransaction::query()->find($creditId);
                if ($clawback === null || $decision === null || $credit === null) {
                    return;
                }

                $salesperson = User::query()->find($salespersonId);
                if ($salesperson !== null) {
                    $salesperson->notify(CommissionReversalCorrectionPostedNotification::fromDecision(
                        $clawback,
                        $decision,
                        $credit,
                    ));
                }

                CustomerFinancialBroadcaster::dispatch($salespersonId, [
                    CustomerFinancialInvalidationReason::TransactionPosted,
                    CustomerFinancialInvalidationReason::CommissionStateChanged,
                ]);
                AdminOpsBroadcaster::dispatch('clawback-correction-posted');
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.correction_side_effects_failed', [
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
        WalletTransaction $credit,
    ): void {
        try {
            app(SystemEventService::class)->record(
                'commission.clawback.correction_posted',
                $clawback,
                $actor,
                [
                    'clawback_public_ref' => $clawback->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                    'amount' => (string) $decision->amount,
                    'reason_code' => (string) $decision->reason_code,
                    'related_wallet_transaction_public_ref' => $credit->public_ref,
                ],
                'info',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('commission.clawback.correction_audit_failed', [
                'clawback_id' => $clawback->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (Schema::hasTable('activity_log')) {
            try {
                activity()
                    ->inLog('payments')
                    ->event('commission.clawback.correction_posted')
                    ->performedOn($clawback)
                    ->causedBy($actor)
                    ->withProperties([
                        'clawback_public_ref' => $clawback->public_ref,
                        'decision_public_ref' => $decision->public_ref,
                        'amount' => (string) $decision->amount,
                        'reason_code' => (string) $decision->reason_code,
                        'admin_note' => $decision->admin_note,
                        'related_wallet_transaction_public_ref' => $credit->public_ref,
                    ])
                    ->log('Commission reversal correction posted');
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

    private function normalizeToken(?string $idempotencyToken): string
    {
        $token = is_string($idempotencyToken) ? trim($idempotencyToken) : '';
        if ($token === '' || mb_strlen($token) < 16 || mb_strlen($token) > 128) {
            return 'correction:'.Str::uuid()->toString();
        }

        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $token)) {
            return 'correction:'.Str::uuid()->toString();
        }

        return 'correction:'.$token;
    }

    private function isUniqueIdempotencyConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
