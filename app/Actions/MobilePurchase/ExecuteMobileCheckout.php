<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Actions\Orders\CheckoutFromPayload;
use App\Enums\MobileCheckoutAttemptStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletSpendFailureReason;
use App\Exceptions\MobileApiException;
use App\Exceptions\WalletSpendDeniedException;
use App\Models\User;
use App\Support\Api\V1\MobileCheckoutIdempotency;
use App\Support\Api\V1\MobileCheckoutQuoteBuilder;
use App\Support\Api\V1\MobilePurchaseReceiptFactory;
use Illuminate\Validation\ValidationException;

final class ExecuteMobileCheckout
{
    public function __construct(
        private readonly MobileCheckoutQuoteBuilder $quoteBuilder,
        private readonly MobileCheckoutIdempotency $idempotency,
        private readonly MobilePurchaseReceiptFactory $receiptFactory,
        private readonly CheckoutFromPayload $checkoutFromPayload,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     package_id: int|null,
     *     quantity: int|null,
     *     requested_amount: int|null,
     *     requirements: array<string, mixed>
     * }  $item
     * @return array{data: array{replayed: bool, order: array<string, mixed>}, status: int}
     */
    public function handle(
        User $user,
        array $item,
        string $quoteFingerprint,
        string $rawIdempotencyKey,
    ): array {
        // 1) Pre-purchase validation — must not claim the idempotency key.
        $freshQuote = $this->quoteBuilder->build($user, $item);
        $this->quoteBuilder->assertFingerprintMatches($quoteFingerprint, $user, $freshQuote);

        if (! $freshQuote['wallet']['can_afford']) {
            throw new MobileApiException(
                'messages.mobile_api.insufficient_wallet_balance',
                'insufficient_wallet_balance',
                422,
                [
                    'available_to_spend' => $freshQuote['wallet']['available_to_spend'],
                    'total' => $freshQuote['total'],
                ],
            );
        }

        $normalizedItem = $freshQuote['_normalized_item'];
        // Fingerprint is an optimistic concurrency guard, not part of the durable
        // idempotency identity — otherwise a refreshed quote would poison the key.
        $canonicalRequest = [
            'item' => [
                'product_id' => $normalizedItem['product_id'],
                'package_id' => $normalizedItem['package_id'],
                'quantity' => $normalizedItem['quantity'],
                'requested_amount' => $normalizedItem['requested_amount'],
                'requirements' => $normalizedItem['requirements'],
            ],
        ];

        $keyHash = $this->idempotency->hashKey($rawIdempotencyKey);
        $requestHash = $this->idempotency->hashRequest($canonicalRequest);

        // Early replay/conflict without claiming.
        $existing = $this->idempotency->findForUser($user, $keyHash);
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new MobileApiException(
                    'messages.mobile_api.idempotency_conflict',
                    'idempotency_conflict',
                    409,
                );
            }

            if ($existing->status === MobileCheckoutAttemptStatus::Completed && is_array($existing->receipt)) {
                return [
                    'data' => [
                        'replayed' => true,
                        'order' => $existing->receipt,
                    ],
                    'status' => 200,
                ];
            }
        }

        $claim = $this->idempotency->claim($user, $keyHash, $requestHash);
        if ($claim['replay']) {
            return [
                'data' => [
                    'replayed' => true,
                    'order' => $claim['attempt']->receipt,
                ],
                'status' => 200,
            ];
        }

        $attempt = $claim['attempt'];

        try {
            $checkout = $this->checkoutFromPayload->handle(
                $user,
                [[
                    'product_id' => $normalizedItem['product_id'],
                    'package_id' => $normalizedItem['package_id'],
                    'quantity' => $normalizedItem['quantity'],
                    'requested_amount' => $normalizedItem['requested_amount'],
                    'requirements' => $normalizedItem['requirements'],
                ]],
                [
                    'source' => 'mobile_api',
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            );

            $order = $checkout->order->fresh(['items']);

            if ($order === null || $order->status !== OrderStatus::Paid) {
                $this->idempotency->release($attempt);
                throw new MobileApiException(
                    'messages.mobile_api.checkout_failed',
                    'checkout_failed',
                    500,
                );
            }

            $receipt = $this->receiptFactory->fromOrder($order, $user);
            $this->idempotency->markCompleted($attempt, (int) $order->id, $receipt);

            return [
                'data' => [
                    'replayed' => (bool) $checkout->reusedExistingOrder,
                    'order' => $receipt,
                ],
                'status' => 200,
            ];
        } catch (MobileApiException $exception) {
            if ($exception->status() === 202) {
                throw $exception;
            }
            $this->idempotency->release($attempt);
            throw $exception;
        } catch (ValidationException $exception) {
            $this->idempotency->release($attempt);
            $this->mapValidationException($exception, $freshQuote);
        } catch (WalletSpendDeniedException $exception) {
            $this->idempotency->release($attempt);
            throw new MobileApiException(
                'messages.mobile_api.insufficient_wallet_balance',
                'insufficient_wallet_balance',
                422,
                [
                    'available_to_spend' => $freshQuote['wallet']['available_to_spend'],
                    'total' => $freshQuote['total'],
                    'reason' => $exception->reason()?->value ?? WalletSpendFailureReason::InsufficientFunds->value,
                ],
            );
        } catch (\Throwable $exception) {
            $this->idempotency->release($attempt);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $freshQuote
     */
    private function mapValidationException(ValidationException $exception, array $freshQuote): never
    {
        $errors = $exception->errors();

        if (array_key_exists('wallet', $errors)) {
            throw new MobileApiException(
                'messages.mobile_api.insufficient_wallet_balance',
                'insufficient_wallet_balance',
                422,
                [
                    'available_to_spend' => $freshQuote['wallet']['available_to_spend'],
                    'total' => $freshQuote['total'],
                ],
            );
        }

        throw $exception;
    }
}
