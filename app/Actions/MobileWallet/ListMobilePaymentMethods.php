<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;

final class ListMobilePaymentMethods
{
    /**
     * @return array{data: list<array{id: int, name: string, instructions: string|null, image_url: string|null}>}
     */
    public function handle(User $user): array
    {
        $factory = MobileTopupFactory::forUser($user);

        $methods = PaymentMethod::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'account_text', 'image', 'is_active', 'sort_order']);

        return [
            'data' => $methods
                ->map(fn (PaymentMethod $method): array => $factory->paymentMethodSummary($method))
                ->values()
                ->all(),
        ];
    }
}
