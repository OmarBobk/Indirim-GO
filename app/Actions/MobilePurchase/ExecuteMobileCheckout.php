<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Actions\Orders\CheckoutFromPayload;
use App\Enums\OrderStatus;
use App\Enums\WalletSpendFailureReason;
use App\Exceptions\MobileApiException;
use App\Exceptions\WalletSpendDeniedException;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\User;
use App\Support\Api\V1\MobileCheckoutIdempotency;
use App\Support\Api\V1\MobileCheckoutQuoteBuilder;
use App\Support\Api\V1\MobilePurchaseReceiptFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $receiptBuilder = fn (Order $order, User $owner): array => $this->receiptFactory->fromOrder($order, $owner);

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

            $reconciled = $this->idempotency->reconcile($existing, $user, $receiptBuilder);
            if ($reconciled['state'] === 'completed' && is_array($reconciled['receipt'])) {
                return [
                    'data' => [
                        'replayed' => true,
                        'order' => $reconciled['receipt'],
                    ],
                    'status' => 200,
                ];
            }
        }

        $claim = $this->idempotency->claim($user, $keyHash, $requestHash, $receiptBuilder);
        if ($claim['replay'] && is_array($claim['receipt'])) {
            return [
                'data' => [
                    'replayed' => true,
                    'order' => $claim['receipt'],
                ],
                'status' => 200,
            ];
        }

        $attempt = $claim['attempt'];

        try {
            /**
             * Authoritative commit boundary:
             * paid order + wallet debit + fulfillments + attempt.order_id/completed
             * commit together, or none commit.
             *
             * @var array{replayed: bool, order_id: int, receipt: array<string, mixed>|null} $committed
             */
            $committed = DB::transaction(function () use (
                $user,
                $normalizedItem,
                $keyHash,
                $attempt,
                $receiptBuilder,
            ): array {
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
                        'mobile_attempt_key_hash' => $keyHash,
                        'ip' => request()->ip(),
                        'user_agent' => request()->userAgent(),
                    ],
                    false,
                );

                $order = $checkout->order->fresh(['items']);

                if ($order === null || ! $this->isAuthoritativePaidState($order)) {
                    throw new MobileApiException(
                        'messages.mobile_api.checkout_failed',
                        'checkout_failed',
                        500,
                    );
                }

                $receipt = null;
                try {
                    $receipt = $receiptBuilder($order, $user);
                } catch (\Throwable $exception) {
                    // Snapshot is optional; linkage + paid order remain authoritative.
                    Log::warning('Mobile checkout receipt snapshot failed before commit', [
                        'error_id' => 'mobile_checkout_receipt_snapshot_failed',
                        'attempt_id' => $attempt->id,
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'exception_class' => $exception::class,
                    ]);
                }

                $this->idempotency->markCompleted($attempt, (int) $order->id, $receipt);

                return [
                    'replayed' => (bool) $checkout->reusedExistingOrder,
                    'order_id' => (int) $order->id,
                    'receipt' => $receipt,
                ];
            });

            $receipt = $committed['receipt'];
            if (! is_array($receipt)) {
                $order = Order::query()->with('items')->findOrFail($committed['order_id']);
                $receipt = $receiptBuilder($order, $user);
                $this->idempotency->markCompleted($attempt->refresh(), (int) $order->id, $receipt);
            }

            return [
                'data' => [
                    'replayed' => $committed['replayed'],
                    'order' => $receipt,
                ],
                'status' => 200,
            ];
        } catch (MobileApiException $exception) {
            if ($exception->status() === 202) {
                throw $exception;
            }

            return $this->recoverAfterException($attempt, $user, $receiptBuilder, $exception);
        } catch (ValidationException $exception) {
            $this->idempotency->releaseIfSafe($attempt, $user, $receiptBuilder);
            $this->mapValidationException($exception, $freshQuote);
        } catch (WalletSpendDeniedException $exception) {
            $this->idempotency->releaseIfSafe($attempt, $user, $receiptBuilder);
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
            return $this->recoverAfterException($attempt, $user, $receiptBuilder, $exception);
        }
    }

    /**
     * @param  callable(Order, User): array<string, mixed>  $receiptBuilder
     * @return array{data: array{replayed: bool, order: array<string, mixed>}, status: int}
     */
    private function recoverAfterException(
        MobileCheckoutAttempt $attempt,
        User $user,
        callable $receiptBuilder,
        \Throwable $exception,
    ): array {
        $this->idempotency->releaseIfSafe($attempt, $user, $receiptBuilder);

        $freshAttempt = MobileCheckoutAttempt::query()->find($attempt->id);
        if ($freshAttempt !== null) {
            $reconciled = $this->idempotency->reconcile($freshAttempt, $user, $receiptBuilder);
            if ($reconciled['state'] === 'completed' && is_array($reconciled['receipt'])) {
                return [
                    'data' => [
                        'replayed' => true,
                        'order' => $reconciled['receipt'],
                    ],
                    'status' => 200,
                ];
            }
        }

        throw $exception;
    }

    private function isAuthoritativePaidState(Order $order): bool
    {
        return in_array($order->status, [
            OrderStatus::Paid,
            OrderStatus::Processing,
            OrderStatus::Fulfilled,
        ], true);
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
