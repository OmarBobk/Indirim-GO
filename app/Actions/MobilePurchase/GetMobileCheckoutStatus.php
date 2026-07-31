<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Enums\MobileCheckoutAttemptStatus;
use App\Exceptions\MobileApiException;
use App\Models\User;
use App\Support\Api\V1\MobileCheckoutIdempotency;

final class GetMobileCheckoutStatus
{
    public function __construct(
        private readonly MobileCheckoutIdempotency $idempotency,
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
                'messages.mobile_api.checkout_attempt_not_found',
                'checkout_attempt_not_found',
                404,
            );
        }

        if ($attempt->status === MobileCheckoutAttemptStatus::Completed && is_array($attempt->receipt)) {
            return [
                'data' => [
                    'state' => 'completed',
                    'replayed' => true,
                    'order' => $attempt->receipt,
                ],
                'status' => 200,
            ];
        }

        if ($attempt->status === MobileCheckoutAttemptStatus::Processing) {
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
                'code' => $attempt->failure_code,
            ],
            'status' => 200,
        ];
    }
}
