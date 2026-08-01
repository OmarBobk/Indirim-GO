<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Support\Api\V1\MobileMoneyFactory;
use App\Support\LedgerMoney;

final class GetMobileWalletSummary
{
    /**
     * @return array{
     *     data: array{
     *         available_to_spend: array{amount: string, currency: string, display: array{currency: string, formatted: string}}
     *     },
     *     meta: array{prices_visible: bool}
     * }
     */
    public function handle(User $user): array
    {
        $wallet = Wallet::forUser($user);
        $available = LedgerMoney::normalize($wallet->availableToSpend());
        $money = MobileMoneyFactory::forUser($user);

        return [
            'data' => [
                'available_to_spend' => $money->fromUsdAmount((float) $available),
            ],
            'meta' => [
                'prices_visible' => WebsiteSetting::getPricesVisible(),
            ],
        ];
    }
}
