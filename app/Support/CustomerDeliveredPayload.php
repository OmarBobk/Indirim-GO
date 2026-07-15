<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

final class CustomerDeliveredPayload
{
    /**
     * Internal automation / ops keys that must never appear on the customer order page.
     *
     * @var list<string>
     */
    private const HIDDEN_KEYS = [
        'phase',
        'automation',
        'checkpoint',
        'product_api',
        'product_url',
        'screenshots',
        'reconcile_tab',
        'automation_run_uuid',
        'supplier_processing_time',
        'url',
        'unit_price',
        'line_total',
        'custom_quantity',
        'supplier_total',
        'product_amount_mode',
        'supplier_entry_price',
        'entry_price',
        'margin_insufficient',
        'scanned_price',
        'requirements_payload',
        'error_code',
        'error_message',
        'log_excerpt',
    ];

    private const IMAGE_URL_PATTERN = '/https?:\/\/[^\s<>"\']+\.(?:jpe?g|png|gif|webp|bmp|svg)(?:\?[^\s<>"\']*)?/iu';

    /**
     * @return array<int, array{key: string, label: string, value: string, image_urls: list<string>}>
     */
    public static function entries(mixed $payload): array
    {
        $payload = self::normalize($payload);

        if ($payload === null) {
            return [];
        }

        if (! is_array($payload)) {
            $value = self::stringify($payload);

            if ($value === '' || $value === 'null') {
                return [];
            }

            return [self::makeEntry('value', $value)];
        }

        $entries = [];

        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;

            if (self::isHiddenKey($keyString)) {
                continue;
            }

            if (self::isBlankValue($value)) {
                continue;
            }

            $entries[] = self::makeEntry($keyString, self::stringify($value));
        }

        return $entries;
    }

    /**
     * @return array{key: string, label: string, value: string, image_urls: list<string>}
     */
    private static function makeEntry(string $key, string $rawValue): array
    {
        $imageUrls = self::extractImageUrls($rawValue);

        return [
            'key' => $key,
            'label' => self::labelForKey($key),
            'value' => self::displayTextWithoutImages($rawValue, $imageUrls),
            'image_urls' => $imageUrls,
        ];
    }

    /**
     * @return list<string>
     */
    public static function extractImageUrls(string $value): array
    {
        preg_match_all(self::IMAGE_URL_PATTERN, $value, $matches);

        $urls = [];

        foreach ($matches[0] ?? [] as $url) {
            $normalized = rtrim((string) $url, '.,);]');

            if ($normalized === '' || in_array($normalized, $urls, true)) {
                continue;
            }

            $urls[] = $normalized;
        }

        return $urls;
    }

    /**
     * @param  list<string>  $imageUrls
     */
    private static function displayTextWithoutImages(string $value, array $imageUrls): string
    {
        $text = $value;

        foreach ($imageUrls as $url) {
            $text = str_replace($url, '', $text);
        }

        $text = preg_replace('/\s*\/\s*$/u', '', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function isHiddenKey(string $key): bool
    {
        $normalized = Str::lower(trim($key));

        if (in_array($normalized, self::HIDDEN_KEYS, true)) {
            return true;
        }

        return str_starts_with($normalized, 'automation_')
            || str_starts_with($normalized, 'supplier_entry_')
            || str_starts_with($normalized, 'margin_');
    }

    public static function labelForKey(string $key): string
    {
        $normalized = Str::lower(trim($key));
        $translationKey = 'messages.delivery_payload_key_'.$normalized;

        if (Lang::has($translationKey)) {
            return __($translationKey);
        }

        return match ($normalized) {
            'value' => __('messages.delivery_payload'),
            'code' => __('messages.delivery_payload_key_code'),
            'pin' => __('messages.delivery_payload_key_pin'),
            'serial' => __('messages.delivery_payload_key_serial'),
            'server' => __('messages.delivery_payload_key_server'),
            'supplier_order_id' => __('messages.delivery_payload_key_supplier_order_id'),
            'supplier_status' => __('messages.delivery_payload_key_supplier_status'),
            'supplier_description' => __('messages.delivery_payload_key_supplier_description'),
            default => $key,
        };
    }

    private static function normalize(mixed $payload): mixed
    {
        if (! is_string($payload)) {
            return $payload;
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $payload;
    }

    private static function isBlankValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }
}
