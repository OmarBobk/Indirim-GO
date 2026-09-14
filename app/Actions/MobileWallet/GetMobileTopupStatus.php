<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Exceptions\MobileApiException;
use App\Models\TopupRequest;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;
use App\Support\Api\V1\MobileTopupIdempotency;

final class GetMobileTopupStatus
{
    public function __construct(
        private readonly MobileTopupIdempotency $idempotency,
    ) {}

    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function handle(User $user, string $rawIdempotencyKey): array
    {
        $keyHash = $this->idempotency->hashKey($rawIdempotencyKey);
        $attempt = $this->idempotency->findForUser($user, $keyHash);

        if ($attempt === null) {
            throw new MobileApiException(
                'messages.mobile_api.topup_attempt_not_found',
                'topup_attempt_not_found',
                404,
            );
        }

        $detailBuilder = fn (TopupRequest $request, User $owner): array => MobileTopupFactory::forUser($owner)
            ->fromOwnedRequest($request, $owner);
        $reconciled = $this->idempotency->reconcile($attempt, $user, $detailBuilder);

        if ($reconciled['state'] === 'completed' && is_array($reconciled['detail'])) {
            return [
                'data' => [
                    'state' => 'completed',
                    'replayed' => true,
                    'pending_until_admin_approval' => (bool) ($reconciled['detail']['pending_until_admin_approval'] ?? false),
                    'topup' => $reconciled['detail'],
                ],
                'status' => 200,
            ];
        }

        if ($reconciled['state'] === 'retry_required') {
            throw new MobileApiException(
                'messages.mobile_api.topup_retry_required',
                'topup_retry_required',
                409,
                [
                    'action' => 'resubmit_identical_topup',
                    'idempotency_key_policy' => 'reuse_same_key',
                ],
            );
        }

        if ($reconciled['state'] === 'processing') {
            return [
                'data' => [
                    'state' => 'processing',
                    'retry_after_seconds' => 2,
                ],
                'status' => 202,
            ];
        }

        return [
            'data' => [
                'state' => 'failed',
                'code' => $reconciled['attempt']->failure_code,
            ],
            'status' => 200,
        ];
    }
}
