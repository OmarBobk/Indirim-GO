<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\MobileCheckoutAttemptStatus;
use App\Exceptions\MobileApiException;
use App\Models\MobileCheckoutAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer-scoped durable checkout idempotency.
 * Stores only a hash of the raw Idempotency-Key — never the raw key.
 */
final class MobileCheckoutIdempotency
{
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
     * @return array{attempt: MobileCheckoutAttempt, replay: bool}
     */
    public function claim(User $user, string $keyHash, string $requestHash): array
    {
        return DB::transaction(function () use ($user, $keyHash, $requestHash): array {
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

                return ['attempt' => $attempt, 'replay' => false];
            }

            if ($existing->request_hash !== $requestHash) {
                throw new MobileApiException(
                    'messages.mobile_api.idempotency_conflict',
                    'idempotency_conflict',
                    409,
                );
            }

            if ($existing->status === MobileCheckoutAttemptStatus::Completed && is_array($existing->receipt)) {
                return ['attempt' => $existing, 'replay' => true];
            }

            if ($existing->status === MobileCheckoutAttemptStatus::Processing) {
                $staleSeconds = max(15, (int) config('mobile_api.checkout.processing_stale_seconds', 60));
                $started = $existing->processing_started_at;
                $isStale = $started === null || $started->lt(now()->subSeconds($staleSeconds));

                if (! $isStale) {
                    // Concurrent in-flight request with same payload.
                    throw new MobileApiException(
                        'messages.mobile_api.checkout_in_progress',
                        'checkout_in_progress',
                        202,
                    );
                }
            }

            // Failed or stale processing with same payload → take over.
            $existing->fill([
                'status' => MobileCheckoutAttemptStatus::Processing,
                'failure_code' => null,
                'processing_started_at' => now(),
                'completed_at' => null,
                'receipt' => null,
                'order_id' => null,
            ])->save();

            return ['attempt' => $existing->refresh(), 'replay' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $receipt
     */
    public function markCompleted(MobileCheckoutAttempt $attempt, int $orderId, array $receipt): void
    {
        $attempt->fill([
            'status' => MobileCheckoutAttemptStatus::Completed,
            'order_id' => $orderId,
            'receipt' => $receipt,
            'failure_code' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function release(MobileCheckoutAttempt $attempt, ?string $failureCode = null): void
    {
        // Delete so the same key can be retried after pre-debit authoritative failure.
        // If a failure_code is provided, keep a failed row for diagnostics with same-payload retry allowed.
        if ($failureCode === null) {
            $attempt->delete();

            return;
        }

        $attempt->fill([
            'status' => MobileCheckoutAttemptStatus::Failed,
            'failure_code' => $failureCode,
            'completed_at' => now(),
            'receipt' => null,
            'order_id' => null,
        ])->save();
    }
}
