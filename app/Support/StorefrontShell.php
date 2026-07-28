<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Storefront shell: config-driven bottom nav, browse strip, account hub links.
 * Presentation / navigation ownership only — does not affect pricing or checkout.
 */
final class StorefrontShell
{
    public const BROWSE_CATEGORY_LIMIT = 12;

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
     *     badge_count: int,
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
            if (! Route::has($routeName)) {
                continue;
            }

            $activePatterns = $item['active'] ?? [$routeName];
            if (! is_array($activePatterns)) {
                $activePatterns = [$routeName];
            }

            $badgeKey = isset($item['badge']) && is_string($item['badge']) ? $item['badge'] : null;

            $items[] = [
                'key' => (string) $item['key'],
                'label' => __((string) ($item['label'] ?? $item['key'])),
                'route' => $routeName,
                'href' => route($routeName),
                'icon' => (string) ($item['icon'] ?? 'circle'),
                'active' => self::routeMatches($activePatterns),
                'event' => (string) ($item['event'] ?? 'bottom-nav-'.$item['key']),
                'badge' => $badgeKey,
                'badge_count' => self::resolveBadgeCount($badgeKey),
            ];
        }

        return $items;
    }

    /**
     * Desktop secondary strip: browse / categories (not app destinations).
     *
     * @return list<array{key: string, label: string, href: string, active: bool, icon: ?string}>
     */
    public static function browseNavItems(): array
    {
        $items = [
            [
                'key' => 'home',
                'label' => __('main.home'),
                'href' => route('home'),
                'active' => request()->routeIs('home'),
                'icon' => 'home',
            ],
        ];

        foreach (self::browseCategories() as $category) {
            $items[] = [
                'key' => 'category-'.$category->id,
                'label' => (string) $category->name,
                'href' => route('categories.show', $category),
                'active' => request()->routeIs('categories.show')
                    && (string) request()->route('category')?->getKey() === (string) $category->getKey(),
                'icon' => null,
            ];
        }

        return $items;
    }

    /**
     * @return Collection<int, Category>
     */
    public static function browseCategories(int $limit = self::BROWSE_CATEGORY_LIMIT): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'slug', 'order'])
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * Account Hub destinations (links only — language/theme/logout are page controls).
     *
     * @return list<array{key: string, section: string, label: string, href: string, icon: string, badge_count: int}>
     */
    public static function accountHubLinks(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $links = [
            [
                'key' => 'profile',
                'section' => 'account',
                'label' => __('main.profile'),
                'href' => route('profile'),
                'icon' => 'user',
                'badge_count' => 0,
            ],
            [
                'key' => 'activity',
                'section' => 'account',
                'label' => __('messages.activity_page_title'),
                'href' => Route::has('activity.index')
                    ? route('activity.index')
                    : route('notifications.index'),
                'icon' => 'bell',
                'badge_count' => self::unreadNotificationCount(),
            ],
            [
                'key' => 'wallet',
                'section' => 'shopping',
                'label' => __('main.wallet'),
                'href' => route('wallet'),
                'icon' => 'wallet',
                'badge_count' => 0,
            ],
            [
                'key' => 'orders',
                'section' => 'shopping',
                'label' => __('main.my_orders'),
                'href' => route('orders.index'),
                'icon' => 'shopping-bag',
                'badge_count' => 0,
            ],
        ];

        if ($user->loyaltyRole() !== null && Route::has('loyalty')) {
            $links[] = [
                'key' => 'loyalty',
                'section' => 'shopping',
                'label' => __('main.loyalty'),
                'href' => route('loyalty'),
                'icon' => 'sparkles',
                'badge_count' => 0,
            ];
        }

        if ($user->can('view_referrals') && Route::has('referral-link')) {
            $links[] = [
                'key' => 'referral',
                'section' => 'staff',
                'label' => __('main.referral_link'),
                'href' => route('referral-link'),
                'icon' => 'link',
                'badge_count' => 0,
            ];
        }

        if ($user->can('view_dashboard') && Route::has('dashboard')) {
            $links[] = [
                'key' => 'dashboard',
                'section' => 'staff',
                'label' => __('main.dashboard'),
                'href' => route('dashboard'),
                'icon' => 'home',
                'badge_count' => 0,
            ];
        }

        if ($user->can('view_referrals') && Route::has('salesperson.dashboard')) {
            $links[] = [
                'key' => 'sales',
                'section' => 'staff',
                'label' => __('messages.salesperson_dashboard'),
                'href' => route('salesperson.dashboard'),
                'icon' => 'chart-bar',
                'badge_count' => 0,
            ];
        }

        if (Route::has('contact')) {
            $links[] = [
                'key' => 'contact',
                'section' => 'account',
                'label' => __('main.contact_us'),
                'href' => route('contact'),
                'icon' => 'envelope',
                'badge_count' => 0,
            ];
        }

        return $links;
    }

    /**
     * Passive Account Hub grouping for section headers (presentation only).
     *
     * @return list<array{key: string, label: string, links: list<array{key: string, section: string, label: string, href: string, icon: string, badge_count: int}>}>
     */
    public static function accountHubSections(): array
    {
        $labels = [
            'account' => __('main.account'),
            'shopping' => __('main.account_section_shopping'),
            'staff' => __('main.account_section_staff'),
        ];

        /** @var array<string, list<array{key: string, section: string, label: string, href: string, icon: string, badge_count: int}>> $grouped */
        $grouped = [
            'account' => [],
            'shopping' => [],
            'staff' => [],
        ];

        foreach (self::accountHubLinks() as $link) {
            $section = $link['section'] ?? 'account';
            if (! array_key_exists($section, $grouped)) {
                $section = 'account';
            }
            $grouped[$section][] = $link;
        }

        $sections = [];
        foreach ($labels as $key => $label) {
            if ($grouped[$key] === []) {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'label' => $label,
                'links' => $grouped[$key],
            ];
        }

        return $sections;
    }

    public static function unreadNotificationCount(): int
    {
        $user = auth()->user();
        if ($user === null) {
            return 0;
        }

        return $user->unreadNotifications()->count();
    }

    public static function resolveBadgeCount(?string $badgeKey): int
    {
        return match ($badgeKey) {
            'notifications' => self::unreadNotificationCount(),
            default => 0,
        };
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
