<?php

declare(strict_types=1);

namespace App\Support\Financial;

/**
 * Customer-safe receipt snapshot helpers (M6.5).
 * Workflow Actions stamp these; WalletLedger does not query source models.
 *
 * @phpstan-type ReceiptSnapshotArray array{
 *     version: int,
 *     source_title?: string|null,
 *     source_description?: string|null,
 *     order_number?: string|null,
 *     topup_public_ref?: string|null,
 *     refund_public_ref?: string|null,
 *     payment_method?: string|null,
 *     product_label?: string|null,
 *     currency?: string|null,
 *     customer_safe_reason?: string|null
 * }
 */
final class ReceiptSnapshot
{
    public const VERSION = 1;

    public const META_KEY = 'receipt';

    /**
     * @param  array{
     *     source_title?: string|null,
     *     source_description?: string|null,
     *     order_number?: string|null,
     *     topup_public_ref?: string|null,
     *     refund_public_ref?: string|null,
     *     payment_method?: string|null,
     *     product_label?: string|null,
     *     currency?: string|null,
     *     customer_safe_reason?: string|null
     * }  $fields
     * @return array<string, mixed>
     */
    public static function wrap(array $fields): array
    {
        $snapshot = ['version' => self::VERSION];

        foreach ($fields as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            if (mb_strlen($trimmed) > 200) {
                $trimmed = mb_substr($trimmed, 0, 197).'…';
            }

            $snapshot[$key] = $trimmed;
        }

        return [self::META_KEY => $snapshot];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    public static function fromMeta(array $meta): ?array
    {
        $receipt = $meta[self::META_KEY] ?? null;

        return is_array($receipt) ? $receipt : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function string(array $meta, string $key): ?string
    {
        $receipt = self::fromMeta($meta);
        if ($receipt === null) {
            return null;
        }

        $value = $receipt[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function version(array $meta): int
    {
        $receipt = self::fromMeta($meta);
        $version = $receipt['version'] ?? null;

        return is_numeric($version) ? (int) $version : self::VERSION;
    }
}
