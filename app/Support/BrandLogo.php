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

    /**
     * Tailwind height/width classes for storefront logo placements.
     *
     * English assets are a tall stacked mark; Arabic assets are a wide wordmark with
     * extra canvas padding — each needs a different display size to read well.
     */
    public static function imageClasses(string $placement = 'header', ?string $locale = null): string
    {
        return match (self::variant($locale)) {
            'ar' => match ($placement) {
                'footer' => 'h-16 w-auto min-w-32 shrink-0 sm:h-[4.5rem] sm:min-w-36',
                default => 'h-10 w-auto min-w-24 shrink-0 sm:h-11 sm:min-w-28 md:h-12 md:min-w-32',
            },
            default => match ($placement) {
                'footer' => 'h-11 w-auto shrink-0 sm:h-12',
                default => 'h-10 w-auto shrink-0 sm:h-11',
            },
        };
    }

    public static function headerImageClasses(?string $locale = null): string
    {
        return self::imageClasses('header', $locale);
    }
}
