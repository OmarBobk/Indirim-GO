<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\MobileCheckoutAttemptStatus;
use App\Enums\OrderStatus;
use App\Exceptions\MobileApiException;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-scoped durable checkout idempotency.
 * Stores only a hash of the raw Idempotency-Key — never the raw key.
 *
 * Lock order (purchase, status, reconcile, release):
 * 1) mobile_checkout_attempts row
 * 2) specifically targeted order row(s)
 * 3) wallet / remaining purchase work (via CheckoutFromPayload)
 */
final class MobileCheckoutIdempotency
{
    public const FAILURE_CHECKOUT_RETRY_REQUIRED = 'checkout_retry_required';

    public function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * @param  array<string, mixed>  $canonicalPayload
     */
    public function hashRequest(array $canonicalPayload): string
    {
        return hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function findForUser(User $user, string $keyHash): ?MobileCheckoutAttempt
    {
        return MobileCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('key_hash', $keyHash)
            ->first();
    }

    /**
     * Claim or resume an attempt immediately before the authoritative purchase.
     * Pre-purchase validation failures must not call this.
     *
     * @param  callable(Order, User): array<string, mixed>  $receiptBuilder
     * @return array{attempt: MobileCheckoutAttempt, replay: bool, receipt: array<string, mixed>|null}
     */
    public function claim(User $user, string $keyHash, string $requestHash, callable $receiptBuilder): array
    {
        try {
            return DB::transaction(function () use ($user, $keyHash, $requestHash, $receiptBuilder): array {
                /** @var MobileCheckoutAttempt|null $existing */
                $existing = MobileCheckoutAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $attempt = MobileCheckoutAttempt::query()->create([
                        'user_id' => $user->id,
                        'key_hash' => $keyHash,
                        'request_hash' => $requestHash,
                        'status' => MobileCheckoutAttemptStatus::Processing,
                        'processing_started_at' => now(),
                    ]);

                    return ['attempt' => $attempt, 'replay' => false, 'receipt' => null];
                }

                return $this->resolveExistingClaim($existing, $user, $requestHash, $receiptBuilder);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueUserKeyConstraint($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($user, $keyHash, $requestHash, $receiptBuilder): array {
                /** @var MobileCheckoutAttempt|null $existing */
                $existing = MobileCheckoutAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw new MobileApiException(
                        'messages.mobile_api.checkout_in_progress',
                        'checkout_in_progress',
                        202,
                    );
                }

                return $this->resolveExistingClaim($existing, $user, $requestHash, $receiptBuilder);
            });
        }
    }

    /**
     * Reconcile a durable attempt for status polling or post-exception recovery.
     *
     * @param  callable(Order, User): array<string, mixed>  $receiptBuilder
     * @return array{attempt: MobileCheckoutAttempt, receipt: array<string, mixed>|null, state: string}
     */
    public function reconcile(MobileCheckoutAttempt $attempt, User $user, callable $receiptBuilder): array
    {
        return DB::transaction(function () use ($attempt, $user, $receiptBuilder): array {
            /** @var MobileCheckoutAttempt|null $locked */
            $locked = MobileCheckoutAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return [
                    'attempt' => $attempt,
                    'receipt' => null,
                    'state' => 'missing',
                ];
            }

            if ($locked->status === MobileCheckoutAttemptStatus::Completed && is_array($locked->receipt)) {
                return [
                    'attempt' => $locked,
                    'receipt' => $locked->receipt,
                    'state' => 'completed',
                ];
            }

            $replay = $this->reconcileLinkedOrRecoverableOrder($locked, $user, $receiptBuilder);
            if ($replay !== null) {
                return [
                    'attempt' => $locked->refresh(),
                    'receipt' => $replay,
                    'state' => 'completed',
                ];
            }

            if ($locked->failure_code === self::FAILURE_CHECKOUT_RETRY_REQUIRED
                && $locked->order_id === null
                && $locked->status === MobileCheckoutAttemptStatus::Failed) {
                return [
                    'attempt' => $locked,
                    'receipt' => null,
                    'state' => 'retry_required',
                ];
            }

            if ($locked->status === MobileCheckoutAttemptStatus::Failed) {
                return [
                    'attempt' => $locked,
                    'receipt' => null,
                    'state' => 'failed',
                ];
            }

            if ($locked->status === MobileCheckoutAttemptStatus::Processing
                && $locked->order_id === null
                && $this->isStaleProcessing($locked)) {
                // Status-only stale orphan: no linked/meta paid order → explicit retryable state.
                $locked->fill([
                    'status' => MobileCheckoutAttemptStatus::Failed,
                    'failure_code' => self::FAILURE_CHECKOUT_RETRY_REQUIRED,
                    'processing_started_at' => null,
                    'completed_at' => null,
                    'receipt' => null,
                ])->save();

                return [
                    'attempt' => $locked->refresh(),
                    'receipt' => null,
                    'state' => 'retry_required',
                ];
            }

            return [
                'attempt' => $locked,
                'receipt' => null,
                'state' => 'processing',
            ];
        });
    }

    /**
     * Atomically mark an attempt completed and linked to a paid order.
     * Must run inside the same DB transaction as the purchase commit, on an
     * already-locked attempt row.
     *
     * @param  array<string, mixed>|null  $receipt
     */
    public function markCompleted(MobileCheckoutAttempt $attempt, int $orderId, ?array $receipt): void
    {
        $attempt->fill([
            'status' => MobileCheckoutAttemptStatus::Completed,
            'order_id' => $orderId,
            'receipt' => $receipt,
            'failure_code' => null,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Lock the attempt row inside the caller's authoritative transaction.
     */
    public function lockForUpdate(MobileCheckoutAttempt $attempt): ?MobileCheckoutAttempt
    {
        return MobileCheckoutAttempt::query()
            ->whereKey($attempt->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Release only when durable state proves the attempt never purchased.
     * Never clears order_id / completed linkage. Never deletes a linked paid order attempt.
     */
    public function releaseIfSafe(MobileCheckoutAttempt $attempt, User $user, callable $receiptBuilder): void
    {
        DB::transaction(function () use ($attempt, $user, $receiptBuilder): void {
            /** @var MobileCheckoutAttempt|null $locked */
            $locked = MobileCheckoutAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($locked->status === MobileCheckoutAttemptStatus::Completed) {
                return;
            }

            if ($this->reconcileLinkedOrRecoverableOrder($locked, $user, $receiptBuilder) !== null) {
                return;
            }

            if ($locked->order_id !== null) {
                Log::warning('Mobile checkout release skipped for linked attempt', [
                    'error_id' => 'mobile_checkout_release_skipped_linked',
                    'attempt_id' => $locked->id,
                    'order_id' => $locked->order_id,
                    'user_id' => $locked->user_id,
                ]);

                return;
            }

            // Provably pre-purchase orphan with no committed order/debit.
            $locked->delete();
        });
    }

    /**
     * @param  callable(Order, User): array<string, mixed>  $receiptBuilder
     * @return array{attempt: MobileCheckoutAttempt, replay: bool, receipt: array<string, mixed>|null}
     */
    private function resolveExistingClaim(
        MobileCheckoutAttempt $existing,
        User $user,
        string $requestHash,
        callable $receiptBuilder,
    ): array {
        if ($existing->request_hash !== $requestHash) {
            throw new MobileApiException(
                'messages.mobile_api.idempotency_conflict',
                'idempotency_conflict',
                409,
            );
        }

        if ($existing->status === MobileCheckoutAttemptStatus::Completed && is_array($existing->receipt)) {
            return ['attempt' => $existing, 'replay' => true, 'receipt' => $existing->receipt];
        }

        $recovered = $this->reconcileLinkedOrRecoverableOrder($existing, $user, $receiptBuilder);
        if ($recovered !== null) {
            return ['attempt' => $existing->refresh(), 'replay' => true, 'receipt' => $recovered];
        }

        if ($existing->order_id !== null) {
            // Linked to a non-replayable authoritative order — do not create a second purchase.
            throw new MobileApiException(
                'messages.mobile_api.checkout_in_progress',
                'checkout_in_progress',
                202,
            );
        }

        if ($existing->status === MobileCheckoutAttemptStatus::Processing) {
            if (! $this->isStaleProcessing($existing)) {
                throw new MobileApiException(
                    'messages.mobile_api.checkout_in_progress',
                    'checkout_in_progress',
                    202,
                );
            }
        }

        // Failed (including checkout_retry_required) or stale processing orphan with
        // same payload and no committed order → restart. Never clear order_id linkage.
        $existing->fill([
            'status' => MobileCheckoutAttemptStatus::Processing,
            'failure_code' => null,
            'processing_started_at' => now(),
            'completed_at' => null,
            'receipt' => null,
        ])->save();

        return ['attempt' => $existing->refresh(), 'replay' => false, 'receipt' => null];
    }

    /**
     * @param  callable(Order, User): array<string, mixed>  $receiptBuilder
     * @return array<string, mixed>|null
     */
    private function reconcileLinkedOrRecoverableOrder(
        MobileCheckoutAttempt $attempt,
        User $user,
        callable $receiptBuilder,
    ): ?array {
        $order = null;

        if ($attempt->order_id !== null) {
            $order = Order::query()
                ->whereKey($attempt->order_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
        }

        if ($order === null) {
            // Targeted lookup by indexed attempt identity — do not lock every paid order.
            $order = Order::query()
                ->where('user_id', $user->id)
                ->where(function ($query) use ($attempt): void {
                    $query->where('mobile_attempt_key_hash', $attempt->key_hash)
                        ->orWhere('meta->mobile_attempt_key_hash', $attempt->key_hash);
                })
                ->whereIn('status', [
                    OrderStatus::Paid->value,
                    OrderStatus::Processing->value,
                    OrderStatus::Fulfilled->value,
                ])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
        }

        if ($order === null || ! $this->isAuthoritativePaidState($order)) {
            return null;
        }

        $receipt = is_array($attempt->receipt) ? $attempt->receipt : null;
        if ($receipt === null) {
            $receipt = $receiptBuilder($order->loadMissing('items'), $user);
        }

        $this->markCompleted($attempt, (int) $order->id, $receipt);

        return $receipt;
    }

    private function isStaleProcessing(MobileCheckoutAttempt $attempt): bool
    {
        $staleSeconds = max(15, (int) config('mobile_api.checkout.processing_stale_seconds', 60));
        $started = $attempt->processing_started_at;

        return $started === null || $started->lt(now()->subSeconds($staleSeconds));
    }

    private function isAuthoritativePaidState(Order $order): bool
    {
        return in_array($order->status, [
            OrderStatus::Paid,
            OrderStatus::Processing,
            OrderStatus::Fulfilled,
        ], true);
    }

    private function isUniqueUserKeyConstraint(QueryException $exception): bool
    {
        return MobileCheckoutAttemptUniqueConstraint::matches($exception);
    }
}
