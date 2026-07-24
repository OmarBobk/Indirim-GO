<?php

declare(strict_types=1);

use App\Models\Category;
use Livewire\Component;

new class extends Component
{
    public array $categories = [];

    public function mount(): void
    {
        $placeholderImage = asset('images/icons/category-placeholder.svg');

        $this->categories = Category::query()
            ->select(['id', 'name', 'slug', 'image', 'order'])
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with([
                'packages' => fn ($query) => $query
                    ->select(['id', 'category_id', 'name', 'order'])
                    ->where('is_active', true)
                    ->orderBy('order')
                    ->orderBy('name')
                    ->limit(4),
            ])
            ->withCount(['packages' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->values()
            ->map(function (Category $category, int $index) use ($placeholderImage): array {
                $maxPills = $index === 0 ? 4 : 2;
                $packageNames = $category->packages->take($maxPills)->pluck('name')->values()->all();
                $overflowCount = max(0, (int) $category->packages_count - count($packageNames));

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'image' => filled($category->image) ? asset($category->image) : $placeholderImage,
                    'fallback_image' => $placeholderImage,
                    'packages_count' => $category->packages_count,
                    'package_names' => $packageNames,
                    'package_names_overflow_count' => $overflowCount,
                ];
            })
            ->all();
    }
};
?>

<div
    class="py-3 sm:py-4"
    x-data='@json([
        'activeCategoryId' => $categories[0]['id'] ?? null,
        'activeCategoryName' => $categories[0]['name'] ?? __('messages.categories'),
    ])'
>
    <div class="overflow-hidden rounded-[1.75rem] border border-zinc-200/80 bg-white/95 shadow-sm ring-1 ring-zinc-950/5 backdrop-blur dark:border-zinc-700 dark:bg-zinc-800/95 dark:ring-white/5">
        <div class="border-b border-zinc-200/80 bg-gradient-to-br from-accent/12 via-transparent to-transparent px-4 py-4 dark:border-zinc-700 sm:px-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="space-y-1.5">
                    <p class="text-[0.7rem] font-semibold tracking-[0.24em] text-accent-foreground/80 uppercase dark:text-accent">
                        {{ __('main.homepage_marquee') }}
                    </p>
                    <flux:heading size="lg" class="text-start text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.categories') }}
                    </flux:heading>
                    <flux:text class="max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('messages.categories') }} · {{ count($categories) }} {{ __('messages.category') }}
                    </flux:text>
                </div>

                <div class="inline-flex items-center gap-2 rounded-full border border-accent/25 bg-accent/10 px-3 py-1.5 text-xs font-medium text-zinc-700 dark:text-zinc-200">
                    <span class="inline-block size-2 rounded-full bg-accent"></span>
                    <span x-text="activeCategoryName">
                        {{ __('messages.categories') }}
                    </span>
                </div>
            </div>
        </div>

        <div class="p-4 sm:p-6">
            @if ($categories === [])
                <div class="rounded-2xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    {{ __('messages.create_first_category') }}
                </div>
            @else
                <div class="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-4" data-test="homepage-categories-grid">
                    @foreach ($categories as $category)
                        <a
                            href="{{ route('categories.show', ['category' => $category['slug']]) }}"
                            wire:key="homepage-category-{{ $category['id'] }}"
                            x-on:click="activeCategoryId = {{ $category['id'] }}; activeCategoryName = @js($category['name'])"
                            wire:navigate
                            @class([
                                'group relative overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-accent hover:shadow-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-accent',
                                'sm:col-span-2 lg:row-span-2' => $loop->first,
                            ])
                            aria-label="{{ $category['name'] }}"
                            data-test="homepage-category-card"
                        >
                            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-zinc-750 via-zinc-750/75 to-transparent"></div>
                            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-[48%] bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
                            <div class="pointer-events-none absolute inset-x-0 top-0 h-28 bg-gradient-to-b from-black/40 to-transparent transition duration-200 group-hover:from-black/50"></div>

                            <div class="aspect-[4/3] overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                                <img
                                    src="{{ $category['image'] }}"
                                    alt="{{ $category['name'] }}"
                                    class="h-full w-full object-cover transition duration-500 group-hover:scale-[1.04]"
                                    loading="lazy"
                                    decoding="async"
                                    onerror="this.onerror=null; this.src='{{ $category['fallback_image'] }}';"
                                />
                            </div>

                            <div class="absolute inset-x-0 bottom-0">
                                <div class="space-y-1 rounded-b-2xl border border-white/10 bg-zinc-950/70 px-2 py-1.5 shadow-lg ring-1 ring-black/40 backdrop-blur-md supports-[backdrop-filter]:bg-zinc-950/55 sm:space-y-1.5 sm:px-3.5 sm:pb-2 sm:pt-2">
                                    @if ($category['package_names'] !== [] || $category['package_names_overflow_count'] > 0)
                                        <div
                                            class="hidden min-w-0 flex-nowrap gap-1.5 overflow-hidden sm:flex"
                                            data-test="category-package-pills"
                                            aria-label="{{ __('messages.packages') }}"
                                        >
                                            @foreach ($category['package_names'] as $packageName)
                                                <span class="inline-flex max-w-[9rem] shrink truncate whitespace-nowrap rounded-full border border-white/25 bg-black/55 px-2 py-1 text-[11px] font-bold text-white shadow-sm backdrop-blur-sm sm:max-w-[10rem] sm:px-2.5 sm:text-xs">
                                                    {{ $packageName }}
                                                </span>
                                            @endforeach
                                            @if ($category['package_names_overflow_count'] > 0)
                                                <span
                                                    class="inline-flex shrink-0 rounded-full border border-white/30 bg-black/60 px-2 py-1 text-[11px] font-bold text-white/95 shadow-sm backdrop-blur-sm sm:px-2.5 sm:text-xs"
                                                    title="{{ __('messages.packages') }}"
                                                >
                                                    +{{ $category['package_names_overflow_count'] }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="line-clamp-2 text-sm font-semibold leading-snug text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.85),0_2px_12px_rgb(0_0_0/0.55)] sm:text-lg {{ $loop->first ? 'lg:text-2xl' : '' }}">
                                        {{ $category['name'] }}
                                    </div>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
