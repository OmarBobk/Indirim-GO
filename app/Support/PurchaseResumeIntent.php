<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Persists an interrupted purchase across wallet top-up so the customer can resume.
 * Presentation / continuity only — does not alter pricing or checkout rules.
 */
final class PurchaseResumeIntent
{
    public const SESSION_KEY = 'purchase_resume_intent';

    public const TTL_SECONDS = 86_400;

    public const SOURCE_BUY_NOW = 'buy_now';

    public const SOURCE_CART = 'cart';

    /**
     * @param  array{
     *     source: string,
     *     product_id?: int,
     *     package_id?: int,
     *     quantity?: int,
     *     requested_amount?: int,
     *     requirements?: array<string, string>
     * }  $intent
     */
    public static function store(array $intent): void
    {
        $source = (string) ($intent['source'] ?? '');
        if (! in_array($source, [self::SOURCE_BUY_NOW, self::SOURCE_CART], true)) {
            return;
        }

        if ($source === self::SOURCE_CART) {
            session()->put(self::SESSION_KEY, [
                'source' => self::SOURCE_CART,
                'stored_at' => now()->timestamp,
            ]);

            return;
        }

        $productId = isset($intent['product_id']) ? (int) $intent['product_id'] : 0;
        $packageId = isset($intent['package_id']) ? (int) $intent['package_id'] : 0;

        if ($productId < 1 && $packageId < 1) {
            return;
        }

        $requirements = [];
        if (isset($intent['requirements']) && is_array($intent['requirements'])) {
            foreach ($intent['requirements'] as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                $requirements[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        $payload = array_filter([
            'source' => self::SOURCE_BUY_NOW,
            'stored_at' => now()->timestamp,
            'product_id' => $productId > 0 ? $productId : null,
            'package_id' => $packageId > 0 ? $packageId : null,
            'quantity' => isset($intent['quantity']) ? max(1, (int) $intent['quantity']) : null,
            'requested_amount' => isset($intent['requested_amount']) ? max(1, (int) $intent['requested_amount']) : null,
            'requirements' => $requirements !== [] ? $requirements : null,
        ], fn ($value) => $value !== null);

        session()->put(self::SESSION_KEY, $payload);
    }

    /**
     * @return array{
     *     source: string,
     *     product_id?: int,
     *     package_id?: int,
     *     quantity?: int,
     *     requested_amount?: int,
     *     requirements?: array<string, string>,
     *     stored_at?: int
     * }|null
     */
    public static function peek(): ?array
    {
        $raw = session()->get(self::SESSION_KEY);

        return self::normalize($raw);
    }

    /**
     * @return array{
     *     source: string,
     *     product_id?: int,
     *     package_id?: int,
     *     quantity?: int,
     *     requested_amount?: int,
     *     requirements?: array<string, string>,
     *     stored_at?: int
     * }|null
     */
    public static function pull(): ?array
    {
        $intent = self::peek();
        self::forget();

        return $intent;
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public static function resumeUrl(?array $intent = null): ?string
    {
        $intent ??= self::peek();
        if ($intent === null) {
            return null;
        }

        return match ($intent['source']) {
            self::SOURCE_CART => route('cart'),
            self::SOURCE_BUY_NOW => route('home'),
            default => null,
        };
    }

    /**
     * @return array{
     *     source: string,
     *     product_id?: int,
     *     package_id?: int,
     *     quantity?: int,
     *     requested_amount?: int,
     *     requirements?: array<string, string>,
     *     stored_at?: int
     * }|null
     */
    private static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $source = (string) ($raw['source'] ?? '');
        if (! in_array($source, [self::SOURCE_BUY_NOW, self::SOURCE_CART], true)) {
            self::forget();

            return null;
        }

        $storedAt = isset($raw['stored_at']) ? (int) $raw['stored_at'] : 0;
        if ($storedAt > 0 && (now()->timestamp - $storedAt) > self::TTL_SECONDS) {
            self::forget();

            return null;
        }

        if ($source === self::SOURCE_CART) {
            return [
                'source' => self::SOURCE_CART,
                'stored_at' => $storedAt > 0 ? $storedAt : now()->timestamp,
            ];
        }

        $productId = isset($raw['product_id']) ? (int) $raw['product_id'] : 0;
        $packageId = isset($raw['package_id']) ? (int) $raw['package_id'] : 0;

        if ($productId < 1 && $packageId < 1) {
            self::forget();

            return null;
        }

        $requirements = [];
        if (isset($raw['requirements']) && is_array($raw['requirements'])) {
            foreach ($raw['requirements'] as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }
                $requirements[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return array_filter([
            'source' => self::SOURCE_BUY_NOW,
            'stored_at' => $storedAt > 0 ? $storedAt : now()->timestamp,
            'product_id' => $productId > 0 ? $productId : null,
            'package_id' => $packageId > 0 ? $packageId : null,
            'quantity' => isset($raw['quantity']) ? max(1, (int) $raw['quantity']) : null,
            'requested_amount' => isset($raw['requested_amount']) ? max(1, (int) $raw['requested_amount']) : null,
            'requirements' => $requirements !== [] ? $requirements : null,
        ], fn ($value) => $value !== null);
    }
}
