<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\MobileTopupAttemptStatus;
use App\Exceptions\MobileApiException;
use App\Models\MobileTopupAttempt;
use App\Models\TopupRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer-scoped durable top-up idempotency.
 * Stores only a hash of the raw Idempotency-Key — never the raw key or proof bytes.
 */
final class MobileTopupIdempotency
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

    public function findForUser(User $user, string $keyHash): ?MobileTopupAttempt
    {
        return MobileTopupAttempt::query()
            ->where('user_id', $user->id)
            ->where('key_hash', $keyHash)
            ->first();
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     * @return array{attempt: MobileTopupAttempt, replay: bool, detail: array<string, mixed>|null}
     */
    public function claim(User $user, string $keyHash, string $requestHash, callable $detailBuilder): array
    {
        try {
            return DB::transaction(function () use ($user, $keyHash, $requestHash, $detailBuilder): array {
                /** @var MobileTopupAttempt|null $existing */
                $existing = MobileTopupAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    $attempt = MobileTopupAttempt::query()->create([
                        'user_id' => $user->id,
                        'key_hash' => $keyHash,
                        'request_hash' => $requestHash,
                        'status' => MobileTopupAttemptStatus::Processing,
                        'processing_started_at' => now(),
                    ]);

                    return ['attempt' => $attempt, 'replay' => false, 'detail' => null];
                }

                return $this->resolveExistingClaim($existing, $user, $requestHash, $detailBuilder);
            });
        } catch (QueryException $exception) {
            if (! MobileTopupAttemptUniqueConstraint::matches($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use ($user, $keyHash, $requestHash, $detailBuilder): array {
                /** @var MobileTopupAttempt|null $existing */
                $existing = MobileTopupAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw new MobileApiException(
                        'messages.mobile_api.topup_in_progress',
                        'topup_in_progress',
                        202,
                    );
                }

                return $this->resolveExistingClaim($existing, $user, $requestHash, $detailBuilder);
            });
        }
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     * @return array{attempt: MobileTopupAttempt, detail: array<string, mixed>|null, state: string}
     */
    public function reconcile(MobileTopupAttempt $attempt, User $user, callable $detailBuilder): array
    {
        return DB::transaction(function () use ($attempt, $user, $detailBuilder): array {
            /** @var MobileTopupAttempt|null $locked */
            $locked = MobileTopupAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return [
                    'attempt' => $attempt,
                    'detail' => null,
                    'state' => 'missing',
                ];
            }

            if ($locked->status === MobileTopupAttemptStatus::Completed) {
                $fresh = $this->freshDetailForAttempt($locked, $user, $detailBuilder);
                if ($fresh !== null) {
                    return [
                        'attempt' => $locked,
                        'detail' => $fresh,
                        'state' => 'completed',
                    ];
                }
            }

            $replay = $this->reconcileLinkedRequest($locked, $user, $detailBuilder);
            if ($replay !== null) {
                return [
                    'attempt' => $locked->refresh(),
                    'detail' => $replay,
                    'state' => 'completed',
                ];
            }

            if ($locked->status === MobileTopupAttemptStatus::Failed) {
                return [
                    'attempt' => $locked,
                    'detail' => null,
                    'state' => 'failed',
                ];
            }

            if ($locked->status === MobileTopupAttemptStatus::Processing
                && $locked->topup_request_id === null
                && $this->isStaleProcessing($locked)) {
                $locked->fill([
                    'status' => MobileTopupAttemptStatus::Failed,
                    'failure_code' => 'topup_retry_required',
                    'processing_started_at' => null,
                    'completed_at' => null,
                ])->save();

                return [
                    'attempt' => $locked->refresh(),
                    'detail' => null,
                    'state' => 'retry_required',
                ];
            }

            return [
                'attempt' => $locked,
                'detail' => null,
                'state' => 'processing',
            ];
        });
    }

    public function markCompleted(MobileTopupAttempt $attempt, int $topupRequestId): void
    {
        $attempt->fill([
            'status' => MobileTopupAttemptStatus::Completed,
            'topup_request_id' => $topupRequestId,
            'failure_code' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function lockForUpdate(MobileTopupAttempt $attempt): ?MobileTopupAttempt
    {
        return MobileTopupAttempt::query()
            ->whereKey($attempt->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     */
    public function releaseIfSafe(MobileTopupAttempt $attempt, User $user, callable $detailBuilder): void
    {
        DB::transaction(function () use ($attempt, $user, $detailBuilder): void {
            /** @var MobileTopupAttempt|null $locked */
            $locked = MobileTopupAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            if ($locked->status === MobileTopupAttemptStatus::Completed) {
                return;
            }

            if ($this->reconcileLinkedRequest($locked, $user, $detailBuilder) !== null) {
                return;
            }

            if ($locked->topup_request_id !== null) {
                Log::warning('Mobile top-up release skipped for linked attempt', [
                    'error_id' => 'mobile_topup_release_skipped_linked',
                    'attempt_id' => $locked->id,
                    'topup_request_id' => $locked->topup_request_id,
                    'user_id' => $locked->user_id,
                ]);

                return;
            }

            $locked->delete();
        });
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     * @return array{attempt: MobileTopupAttempt, replay: bool, detail: array<string, mixed>|null}
     */
    private function resolveExistingClaim(
        MobileTopupAttempt $existing,
        User $user,
        string $requestHash,
        callable $detailBuilder,
    ): array {
        if ($existing->request_hash !== $requestHash) {
            throw new MobileApiException(
                'messages.mobile_api.idempotency_conflict',
                'idempotency_conflict',
                409,
            );
        }

        if ($existing->status === MobileTopupAttemptStatus::Completed) {
            $fresh = $this->freshDetailForAttempt($existing, $user, $detailBuilder);
            if ($fresh !== null) {
                return ['attempt' => $existing, 'replay' => true, 'detail' => $fresh];
            }
        }

        $recovered = $this->reconcileLinkedRequest($existing, $user, $detailBuilder);
        if ($recovered !== null) {
            return ['attempt' => $existing->refresh(), 'replay' => true, 'detail' => $recovered];
        }

        if ($existing->topup_request_id !== null) {
            throw new MobileApiException(
                'messages.mobile_api.topup_in_progress',
                'topup_in_progress',
                202,
            );
        }

        if ($existing->status === MobileTopupAttemptStatus::Processing) {
            if (! $this->isStaleProcessing($existing)) {
                throw new MobileApiException(
                    'messages.mobile_api.topup_in_progress',
                    'topup_in_progress',
                    202,
                );
            }
        }

        $existing->fill([
            'status' => MobileTopupAttemptStatus::Processing,
            'failure_code' => null,
            'processing_started_at' => now(),
            'completed_at' => null,
        ])->save();

        return ['attempt' => $existing->refresh(), 'replay' => false, 'detail' => null];
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     * @return array<string, mixed>|null
     */
    private function reconcileLinkedRequest(
        MobileTopupAttempt $attempt,
        User $user,
        callable $detailBuilder,
    ): ?array {
        if ($attempt->topup_request_id === null) {
            return null;
        }

        $request = TopupRequest::query()
            ->whereKey($attempt->topup_request_id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            return null;
        }

        $detail = $detailBuilder($request, $user);
        $this->markCompleted($attempt, (int) $request->id);

        return $detail;
    }

    /**
     * @param  callable(TopupRequest, User): array<string, mixed>  $detailBuilder
     * @return array<string, mixed>|null
     */
    private function freshDetailForAttempt(
        MobileTopupAttempt $attempt,
        User $user,
        callable $detailBuilder,
    ): ?array {
        if ($attempt->topup_request_id === null) {
            return null;
        }

        $request = TopupRequest::query()
            ->whereKey($attempt->topup_request_id)
            ->where('user_id', $user->id)
            ->first();

        if ($request === null) {
            return null;
        }

        return $detailBuilder($request, $user);
    }

    private function isStaleProcessing(MobileTopupAttempt $attempt): bool
    {
        $staleSeconds = max(15, (int) config('mobile_api.topup.processing_stale_seconds', 60));
        $started = $attempt->processing_started_at;

        return $started === null || $started->lt(now()->subSeconds($staleSeconds));
    }
}
