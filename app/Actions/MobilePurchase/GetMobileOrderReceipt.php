<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Exceptions\MobileApiException;
use App\Models\Order;
use App\Models\User;
use App\Support\Api\V1\MobilePurchaseReceiptFactory;

final class GetMobileOrderReceipt
{
    public function __construct(
        private readonly MobilePurchaseReceiptFactory $receiptFactory,
    ) {}

    /**
     * @return array{data: array{replayed: bool, order: array<string, mixed>}}
     */
    public function handle(User $user, string $orderNumber): array
    {
        $order = Order::query()
            ->where('user_id', $user->id)
            ->where('order_number', $orderNumber)
            ->with('items')
            ->first();

        if ($order === null) {
            throw new MobileApiException(
                'messages.mobile_api.order_not_found',
                'order_not_found',
                404,
            );
        }

        return [
            'data' => [
                'replayed' => false,
                'order' => $this->receiptFactory->fromOrder($order, $user),
            ],
        ];
    }
}
