<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Exceptions\MobileApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\Api\V1\MobileCheckoutIdempotency;
use App\Support\Api\V1\MobilePurchaseReceiptFactory;

final class GetMobileCheckoutStatus
{
    public function __construct(
        private readonly MobileCheckoutIdempotency $idempotency,
        private readonly MobilePurchaseReceiptFactory $receiptFactory,
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

        $receiptBuilder = fn (Order $order, User $owner): array => $this->receiptFactory->fromOrder($order, $owner);
        $reconciled = $this->idempotency->reconcile($attempt, $user, $receiptBuilder);

        if ($reconciled['state'] === 'completed' && is_array($reconciled['receipt'])) {
            return [
                'data' => [
                    'state' => 'completed',
                    'replayed' => true,
                    'order' => $reconciled['receipt'],
                ],
                'status' => 200,
            ];
        }

        if ($reconciled['state'] === 'retry_required') {
            // Never invent a new Idempotency-Key for an unknown result — resubmit identically.
            throw new MobileApiException(
                'messages.mobile_api.checkout_retry_required',
                MobileCheckoutIdempotency::FAILURE_CHECKOUT_RETRY_REQUIRED,
                409,
                [
                    'action' => 'resubmit_identical_checkout',
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
