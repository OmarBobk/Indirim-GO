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
    public static function fromRelativePath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $normalized = trim($path);

        if ($normalized === ''
            || str_contains($normalized, "\0")
            || str_contains($normalized, '..')
            || Str::startsWith($normalized, ['//', '\\\\'])
            || parse_url($normalized, PHP_URL_SCHEME) !== null
            || Str::endsWith(Str::lower($normalized), '.svg')) {
            return null;
        }

        $assetUrl = asset(ltrim($normalized, '/'));
        $absoluteUrl = Str::startsWith($assetUrl, ['http://', 'https://'])
            ? $assetUrl
            : url($assetUrl);
        $scheme = parse_url($absoluteUrl, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $absoluteUrl : null;
    }
}
