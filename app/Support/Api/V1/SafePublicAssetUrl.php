<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use Illuminate\Support\Str;

/**
 * Builds a safe absolute public asset URL for mobile catalog media.
 * Returns null for missing or hostile paths; never returns the SVG placeholder.
 */
final class SafePublicAssetUrl
{
    private const MAX_DECODE_PASSES = 3;

    public static function fromRelativePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $original = trim($path);

        if ($original === '' || self::containsNullByte($original)) {
            return null;
        }

        if (self::hasMalformedPercentEncoding($original)) {
            return null;
        }

        $decoded = self::boundedDecode($original);

        if ($decoded === null || self::containsNullByte($decoded)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $decoded);
        $normalized = trim($normalized);

        if ($normalized === ''
            || str_contains($normalized, '..')
            || Str::startsWith($normalized, ['//'])
            || self::hasEmbeddedScheme($original)
            || self::hasEmbeddedScheme($normalized)
            || self::looksLikeSvg($normalized)) {
            return null;
        }

        $relative = ltrim($normalized, '/');
        $assetUrl = asset($relative);
        $absoluteUrl = Str::startsWith($assetUrl, ['http://', 'https://'])
            ? $assetUrl
            : url($assetUrl);
        $scheme = parse_url($absoluteUrl, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $absoluteUrl : null;
    }

    private static function boundedDecode(string $value): ?string
    {
        $current = $value;

        for ($pass = 0; $pass < self::MAX_DECODE_PASSES; $pass++) {
            if (self::hasMalformedPercentEncoding($current)) {
                return null;
            }

            $next = rawurldecode($current);

            if ($next === $current) {
                return $current;
            }

            $current = $next;
        }

        if (self::hasMalformedPercentEncoding($current)) {
            return null;
        }

        return $current;
    }

    private static function hasMalformedPercentEncoding(string $value): bool
    {
        return (bool) preg_match('/%(?![0-9A-Fa-f]{2})/', $value);
    }

    private static function containsNullByte(string $value): bool
    {
        return str_contains($value, "\0") || str_contains(Str::lower($value), '%00');
    }

    private static function hasEmbeddedScheme(string $value): bool
    {
        return parse_url($value, PHP_URL_SCHEME) !== null;
    }

    private static function looksLikeSvg(string $value): bool
    {
        $lower = Str::lower($value);

        return Str::endsWith($lower, '.svg') || str_contains($lower, '.svg?') || str_contains($lower, '.svg#');
    }
}
