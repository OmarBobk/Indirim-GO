<?php

declare(strict_types=1);

use App\Models\Package;
use Livewire\Component;

new class extends Component
{
    public array $packages = [];

    public ?int $limit = null;

    public function mount(?int $limit = null): void
    {
        $this->limit = $limit;

        $placeholderImage = asset('images/icons/category-placeholder.svg');

        $query = Package::query()
            ->select(['id', 'name', 'image', 'order'])
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name');

        if ($this->limit !== null && $this->limit > 0) {
            $query->limit($this->limit);
        }

        $this->packages = $query
            ->get()
            ->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'image' => filled($package->image) ? asset($package->image) : $placeholderImage,
                'products_count' => $package->products_count,
            ])
            ->all();
    }
};
?>

<div class="py-3 sm:py-4" x-data>
    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
        <div class="flex flex-col gap-4 sm:gap-5">
            <flux:heading size="lg" class="text-start text-zinc-900 dark:text-zinc-100">
                {{ __('messages.packages') }}
            </flux:heading>

            @if ($packages === [])
                <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    {{ __('messages.no_packages_yet') }}
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 lg:grid-cols-4">
                    @foreach ($packages as $package)
                        <button
                            type="button"
                            x-on:click="$dispatch('open-package-overlay', { packageId: {{ $package['id'] }} })"
                            class="group flex cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start text-zinc-900 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-accent hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:border-accent"
                            aria-label="{{ $package['name'] }}"
                        >
                            <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                                <img
                                    src="{{ $package['image'] }}"
                                    alt="{{ $package['name'] }}"
                                    class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                    width="320"
                                    height="240"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <div class="flex flex-1 flex-col gap-1 px-3 pb-3 pt-2">
                                <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ $package['name'] }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $package['products_count'] }} {{ __('messages.products') }}
                                </div>
                            </div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
