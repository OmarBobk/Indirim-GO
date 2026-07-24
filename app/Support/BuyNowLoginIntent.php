<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Persists a guest buy-now / package-overlay intent across the login redirect.
 * Presentation-only: does not alter pricing or checkout rules.
 */
final class BuyNowLoginIntent
{
    public const SESSION_KEY = 'buy_now_login_intent';

    /**
     * @param  array{product_id?: int, package_id?: int, quantity?: int, requested_amount?: int}  $intent
     */
    public static function store(array $intent): void
    {
        $productId = isset($intent['product_id']) ? (int) $intent['product_id'] : 0;
        $packageId = isset($intent['package_id']) ? (int) $intent['package_id'] : 0;

        if ($productId < 1 && $packageId < 1) {
            return;
        }

        $payload = array_filter([
            'product_id' => $productId > 0 ? $productId : null,
            'package_id' => $packageId > 0 ? $packageId : null,
            'quantity' => isset($intent['quantity']) ? max(1, (int) $intent['quantity']) : null,
            'requested_amount' => isset($intent['requested_amount']) ? max(1, (int) $intent['requested_amount']) : null,
        ], fn ($value) => $value !== null);

        session()->put(self::SESSION_KEY, $payload);
        session()->put('url.intended', route('home'));
    }

    /**
     * @return array{product_id?: int, package_id?: int, quantity?: int, requested_amount?: int}|null
     */
    public static function pull(): ?array
    {
        $raw = session()->pull(self::SESSION_KEY);

        if (! is_array($raw)) {
            return null;
        }

        $productId = isset($raw['product_id']) ? (int) $raw['product_id'] : 0;
        $packageId = isset($raw['package_id']) ? (int) $raw['package_id'] : 0;

        if ($productId < 1 && $packageId < 1) {
            return null;
        }

        return array_filter([
            'product_id' => $productId > 0 ? $productId : null,
            'package_id' => $packageId > 0 ? $packageId : null,
            'quantity' => isset($raw['quantity']) ? max(1, (int) $raw['quantity']) : null,
            'requested_amount' => isset($raw['requested_amount']) ? max(1, (int) $raw['requested_amount']) : null,
        ], fn ($value) => $value !== null);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
