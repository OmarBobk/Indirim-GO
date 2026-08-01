<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackDisputeReason;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\User;
use App\Notifications\CommissionClawbackDisputeOpenedNotification;
use App\Services\SystemEventService;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackDecisionPublicRef;
use App\Support\Commissions\CommissionClawbackDisputeEligibility;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin opens a clawback dispute on behalf of a salesperson (M7.2.3). No money movement.
 *
 * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
 */
final class OpenCommissionClawbackDispute
{
    public function __construct(
        private readonly CommissionClawbackDisputeEligibility $eligibility = new CommissionClawbackDisputeEligibility,
    ) {}

    /**
     * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
     */
    public function handle(
        User $actor,
        CommissionClawback $clawback,
        string $reasonCode,
        ?string $adminNote = null,
        ?string $idempotencyToken = null,
    ): array {
        if (! $actor->can('manage_commission_clawback_disputes')) {
            throw new AuthorizationException(__('messages.clawback_dispute_unauthorized'));
        }

        $reason = CommissionClawbackDisputeReason::tryFrom(trim($reasonCode));
        if ($reason === null) {
            return $this->result($clawback, 'denied', null, 'messages.clawback_dispute_invalid_reason');
        }

        $note = $this->sanitizeNote($adminNote);
        $token = $this->normalizeToken($idempotencyToken, 'dispute-open');

        try {
            return DB::transaction(function () use ($actor, $clawback, $reason, $note, $token): array {
                /** @var CommissionClawback $locked */
                $locked = CommissionClawback::query()->whereKey($clawback->id)->lockForUpdate()->firstOrFail();

                $existing = CommissionClawbackDecision::query()
                    ->where('idempotency_key', $token)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->commission_clawback_id !== (int) $locked->id
                        || $existing->type !== CommissionClawbackDecisionType::DisputeOpened
                    ) {
                        return $this->result($locked, 'denied', null, 'messages.clawback_dispute_idempotency_conflict');
                    }

                    return $this->result($locked->refresh(), 'replayed', $existing, 'messages.clawback_dispute_opened', true);
                }

                CommissionClawbackDecision::query()
                    ->where('commission_clawback_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $eligibility = $this->eligibility->decideOpen($locked);
                if (! $eligibility->allowed) {
                    return $this->result(
                        $locked,
                        'denied',
                        null,
                        $eligibility->safeDenialKey !== '' ? $eligibility->safeDenialKey : 'messages.clawback_dispute_unavailable',
                    );
                }

                /** @var CommissionClawbackDecision $decision */
                $decision = CommissionClawbackDecisionPublicRef::withUniqueRetry(
                    function (string $publicRef) use ($locked, $reason, $note, $actor, $token): CommissionClawbackDecision {
                        return CommissionClawbackDecision::query()->create([
                            'public_ref' => $publicRef,
                            'commission_clawback_id' => $locked->id,
                            'parent_decision_id' => null,
                            'type' => CommissionClawbackDecisionType::DisputeOpened,
                            'status' => CommissionClawbackDecisionStatus::Open,
                            'amount' => null,
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

                $this->recordAudit($actor, $locked, $decision, 'commission.clawback.dispute_opened');
                $this->afterCommit($locked, $decision);

                return $this->result($locked->refresh(), 'opened', $decision, 'messages.clawback_dispute_opened');
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueIdempotencyConflict($exception)) {
                $existing = CommissionClawbackDecision::query()->where('idempotency_key', $token)->first();
                if ($existing !== null && (int) $existing->commission_clawback_id === (int) $clawback->id) {
                    return $this->result($clawback->fresh() ?? $clawback, 'replayed', $existing, 'messages.clawback_dispute_opened', true);
                }
            }

            throw $exception;
        }
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

    private function afterCommit(CommissionClawback $clawback, CommissionClawbackDecision $decision): void
    {
        $salespersonId = (int) $clawback->salesperson_id;
        $decisionId = (int) $decision->id;
        $clawbackId = (int) $clawback->id;

        DB::afterCommit(function () use ($salespersonId, $decisionId, $clawbackId): void {
            try {
                $clawback = CommissionClawback::query()->find($clawbackId);
                $decision = CommissionClawbackDecision::query()->find($decisionId);
                if ($clawback === null || $decision === null) {
                    return;
                }

                $salesperson = User::query()->find($salespersonId);
                if ($salesperson !== null) {
                    $salesperson->notify(CommissionClawbackDisputeOpenedNotification::fromDecision($clawback, $decision));
                }

                CustomerFinancialBroadcaster::dispatch(
                    $salespersonId,
                    [CustomerFinancialInvalidationReason::CommissionStateChanged],
                );
                AdminOpsBroadcaster::dispatch('clawback-dispute-opened');
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.dispute_open_side_effects_failed', [
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
        string $event,
    ): void {
        try {
            app(SystemEventService::class)->record(
                $event,
                $clawback,
                $actor,
                [
                    'clawback_public_ref' => $clawback->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                    'reason_code' => (string) $decision->reason_code,
                ],
                'info',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('commission.clawback.dispute_audit_failed', [
                'clawback_id' => $clawback->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (Schema::hasTable('activity_log')) {
            try {
                activity()
                    ->inLog('payments')
                    ->event($event)
                    ->performedOn($clawback)
                    ->causedBy($actor)
                    ->withProperties([
                        'clawback_public_ref' => $clawback->public_ref,
                        'decision_public_ref' => $decision->public_ref,
                        'reason_code' => (string) $decision->reason_code,
                        'admin_note' => $decision->admin_note,
                    ])
                    ->log('Commission clawback dispute opened');
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
