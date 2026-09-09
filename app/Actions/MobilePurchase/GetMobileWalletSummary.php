<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Enums\TopupRequestStatus;
use App\Models\TopupRequest;
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
     *         available_to_spend: array{amount: string, currency: string, display: array{currency: string, formatted: string}},
     *         pending_topup_public_ref: string|null
     *     },
     *     meta: array{prices_visible: bool}
     * }
     */
    public function handle(User $user): array
    {
        $wallet = Wallet::forUser($user);
        $available = LedgerMoney::normalize($wallet->availableToSpend());
        $money = MobileMoneyFactory::forUser($user);
        $pendingRef = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->orderByDesc('id')
            ->value('public_ref');

        return [
            'data' => [
                'available_to_spend' => $money->fromUsdDecimal($available),
                'pending_topup_public_ref' => is_string($pendingRef) && $pendingRef !== '' ? $pendingRef : null,
            ],
            'meta' => [
                'prices_visible' => WebsiteSetting::getPricesVisible(),
            ],
        ];
    }
}
