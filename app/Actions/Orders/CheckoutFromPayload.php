<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CheckoutFromPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $meta
     */
    public function handle(User $user, array $items, array $meta = [], bool $useTransaction = true): Order
    {
        if ($items === [] || array_is_list($items) === false) {
            throw ValidationException::withMessages([
                'items' => 'Cart payload is empty.',
            ]);
        }

        $wallet = Wallet::forUser($user);
        $cartHash = $this->cartHash($items);

        $operation = function () use ($user, $wallet, $items, $meta, $cartHash): Order {
            $lockedWallet = Wallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingOrder = Order::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [OrderStatus::PendingPayment, OrderStatus::Paid])
                ->latest('id')
                ->limit(5)
                ->get()
                ->first(fn (Order $order) => data_get($order->meta, 'cart_hash') === $cartHash);

            if ($existingOrder !== null) {
                if ($existingOrder->status === OrderStatus::Paid) {
                    return $existingOrder;
                }

                return app(PayOrderWithWallet::class)->handle($existingOrder, $lockedWallet, false);
            }

            $metaForCreate = array_merge($meta, ['cart_hash' => $cartHash]);
            $referralPayload = $this->referralPayloadFromCookie($user)
                ?? $this->referralPayloadFromReferredByUser($user);
            if ($referralPayload !== null) {
                $metaForCreate['referral'] = $referralPayload;
            }

            $order = app(CreateOrderFromCartPayload::class)->handle(
                $user,
                $items,
                $metaForCreate,
                false
            );

            return app(PayOrderWithWallet::class)->handle($order, $lockedWallet, false);
        };

        try {
            return $useTransaction
                ? DB::transaction($operation)
                : $operation();
        } catch (ValidationException $e) {
            if (Schema::hasTable('activity_log')) {
                activity()
                    ->inLog('orders')
                    ->event('payment.failed')
                    ->performedOn($user)
                    ->causedBy($user)
                    ->withProperties([
                        'user_id' => $user->id,
                        'reason' => $e->getMessage(),
                    ])
                    ->log('Payment failed');
            }
            $user->notify(PaymentFailedNotification::forUser($user, $e->getMessage(), null));
            throw $e;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    /**
     * Server-side referral from cookie (never trust client meta). Cookie wins when present.
     *
     * @return array{code: string, salesperson_id: int}|null
     */
    private function referralPayloadFromCookie(User $buyer): ?array
    {
        $cookieName = (string) config('referral.cookie_name', 'karman_ref');
        $raw = request()->cookie($cookieName);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $code = strtoupper(trim($raw));

        if ($code === '' || strlen($code) > 16) {
            return null;
        }

        $referrer = User::findByReferralCode($code);

        if ($referrer === null) {
            return null;
        }

        if ($referrer->id === $buyer->id) {
            return null;
        }

        return [
            'code' => $code,
            'salesperson_id' => $referrer->id,
        ];
    }

    /**
     * When the buyer has no referral cookie, attribute to their permanent referrer (e.g. salesperson-created account).
     * Only users who may earn referral commissions (`view_referrals`) are used.
     *
     * @return array{code: string, salesperson_id: int}|null
     */
    private function referralPayloadFromReferredByUser(User $buyer): ?array
    {
        $referrerId = $buyer->referred_by_user_id;
        if ($referrerId === null || (int) $referrerId <= 0) {
            return null;
        }

        $referrer = User::query()->select(['id', 'referral_code'])->find((int) $referrerId);
        if ($referrer === null || (int) $referrer->id === (int) $buyer->id) {
            return null;
        }

        if (! $referrer->can('view_referrals')) {
            return null;
        }

        $code = strtoupper(trim((string) $referrer->referral_code));
        if ($code === '' || strlen($code) > 16) {
            return null;
        }

        return [
            'code' => $code,
            'salesperson_id' => (int) $referrer->id,
        ];
    }

    private function cartHash(array $items): string
    {
        $normalized = collect($items)
            ->filter(fn (mixed $item) => is_array($item))
            ->map(function (array $item): array {
                return [
                    'product_id' => $item['product_id'] ?? $item['id'] ?? null,
                    'package_id' => $item['package_id'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'requested_amount' => $item['requested_amount'] ?? null,
                    'requirements' => $item['requirements'] ?? $item['requirements_payload'] ?? null,
                ];
            })
            ->sortBy(fn (array $item) => [$item['product_id'], $item['package_id']])
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized));
    }
}
