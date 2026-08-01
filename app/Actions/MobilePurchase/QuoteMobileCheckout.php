<?php

declare(strict_types=1);

namespace App\Actions\MobilePurchase;

use App\Models\User;
use App\Support\Api\V1\MobileCheckoutQuoteBuilder;

final class QuoteMobileCheckout
{
    public function __construct(
        private readonly MobileCheckoutQuoteBuilder $quoteBuilder,
    ) {}

    /**
     * @param  array{
     *     product_id: int,
     *     package_id: int|null,
     *     quantity: int|null,
     *     requested_amount: int|null,
     *     requirements: array<string, mixed>
     * }  $item
     * @return array{data: array<string, mixed>}
     */
    public function handle(User $user, array $item): array
    {
        $quote = $this->quoteBuilder->build($user, $item);

        return [
            'data' => $this->quoteBuilder->publicQuote($quote),
        ];
    }
}
