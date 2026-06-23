<?php

namespace App\Support;

final class BrandLogo
{
    /**
     * Logo variant suffix for public/{light|dark}_{en|ar}_logo.png assets.
     */
    public static function variant(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? 'ar' : 'en';
    }

    public static function lightPath(?string $locale = null): string
    {
        return 'light_'.self::variant($locale).'_logo.png';
    }

    public static function darkPath(?string $locale = null): string
    {
        return 'dark_'.self::variant($locale).'_logo.png';
    }

    public static function lightUrl(?string $locale = null): string
    {
        return asset(self::lightPath($locale));
    }

    public static function darkUrl(?string $locale = null): string
    {
        return asset(self::darkPath($locale));
    }
}
