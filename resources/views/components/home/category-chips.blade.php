@php
    use App\Models\Category;

    $placeholderImage = asset('images/icons/category-placeholder.svg');

    $categories = Category::query()
        ->select(['id', 'name', 'slug', 'image', 'order'])
        ->whereNull('parent_id')
        ->where('is_active', true)
        ->orderBy('order')
        ->orderBy('name')
        ->limit(12)
        ->get()
        ->map(fn (Category $category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'image' => filled($category->image) ? asset($category->image) : $placeholderImage,
        ])
        ->all();
@endphp

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-category-chips"
    data-test="customer-home-category-chips"
    aria-labelledby="customer-home-categories-heading"
>
    <div class="space-y-3">
        <h2
            id="customer-home-categories-heading"
            class="text-base font-semibold text-zinc-900 dark:text-zinc-100"
        >
            {{ __('messages.categories') }}
        </h2>

        @if ($categories === [])
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.create_first_category') }}</p>
        @else
            <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                @foreach ($categories as $category)
                    <a
                        href="{{ route('categories.show', ['category' => $category['slug']]) }}"
                        wire:navigate
                        wire:key="home-cat-chip-{{ $category['id'] }}"
                        class="inline-flex shrink-0 items-center gap-2 rounded-full border border-zinc-200 bg-white py-1.5 ps-1.5 pe-3 text-xs font-medium text-zinc-800 shadow-sm transition hover:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                        data-test="customer-home-category-chip"
                        data-event="home-category-chip"
                    >
                        <img
                            src="{{ $category['image'] }}"
                            alt=""
                            class="size-7 rounded-full object-cover"
                            width="28"
                            height="28"
                            loading="lazy"
                            decoding="async"
                        />
                        <span class="max-w-[9rem] truncate">{{ $category['name'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</section>
