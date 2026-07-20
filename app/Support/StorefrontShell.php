<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Storefront mobile shell: config-driven bottom nav and shared layout helpers.
 * Presentation only — does not affect pricing, wallet, or checkout rules.
 */
final class StorefrontShell
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     route: string,
     *     href: string,
     *     icon: string,
     *     active: bool,
     *     event: string,
     *     badge: ?string,
     * }>
     */
    public static function bottomNavItems(): array
    {
        $key = auth()->check() ? 'authenticated' : 'guest';
        /** @var list<array<string, mixed>> $raw */
        $raw = config("storefront.bottom_nav.{$key}", []);

        $items = [];

        foreach ($raw as $item) {
            if (! is_array($item) || empty($item['key']) || empty($item['route'])) {
                continue;
            }

            $authRule = $item['auth'] ?? null;
            if ($authRule === true && ! auth()->check()) {
                continue;
            }
            if ($authRule === false && auth()->check()) {
                continue;
            }

            $routeName = (string) $item['route'];
            if (! \Illuminate\Support\Facades\Route::has($routeName)) {
                continue;
            }

            $activePatterns = $item['active'] ?? [$routeName];
            if (! is_array($activePatterns)) {
                $activePatterns = [$routeName];
            }

            $items[] = [
                'key' => (string) $item['key'],
                'label' => __((string) ($item['label'] ?? $item['key'])),
                'route' => $routeName,
                'href' => route($routeName),
                'icon' => (string) ($item['icon'] ?? 'circle'),
                'active' => self::routeMatches($activePatterns),
                'event' => (string) ($item['event'] ?? 'bottom-nav-'.$item['key']),
                'badge' => isset($item['badge']) && is_string($item['badge']) ? $item['badge'] : null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<string>  $patterns
     */
    public static function routeMatches(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function shellMaxBreakpoint(): string
    {
        return (string) config('storefront.shell_max', 'lg');
    }
}
