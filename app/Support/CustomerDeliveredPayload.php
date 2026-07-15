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

    /**
     * @return array<int, array{key: string, label: string, value: string}>
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

            return [[
                'key' => 'value',
                'label' => self::labelForKey('value'),
                'value' => $value,
            ]];
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

            $entries[] = [
                'key' => $keyString,
                'label' => self::labelForKey($keyString),
                'value' => self::stringify($value),
            ];
        }

        return $entries;
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
