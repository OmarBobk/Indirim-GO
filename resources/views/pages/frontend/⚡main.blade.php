<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component {

    public array $heroBanner, $heroBanner2;

    public function mount()
    {
        $this->heroBanner = [
            'image' => asset('images/sliders/min-promotional-1.jpg'),
            'href' => '#',
        ];

        $this->heroBanner2 = [
            'image' => asset('images/sliders/min-promotional-2.jpg'),
            'href' => '#',
        ];
    }

    public function render()
    {
        return $this->view()->title(__('main.homepage'));
    }
};
?>

<div class="flex flex-col" data-test="{{ auth()->check() ? 'customer-home' : 'guest-home' }}">

    @auth
        {{-- Authenticated operational home (Milestone 3) --}}
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 py-3 sm:gap-5 sm:py-4" data-section="customer-home">
            <x-home.wallet-strip />
            <x-home.frequently-ordered />
            <x-home.search />
            <x-home.recent-orders />
            <x-home.quick-actions />
            <x-home.category-chips />

            <section
                id="homepage-section-of-packages"
                class="w-full"
                data-section="customer-home-popular-packages"
                data-test="customer-home-popular-packages"
                aria-labelledby="customer-home-packages-heading"
            >
                <h2 id="customer-home-packages-heading" class="sr-only">{{ __('main.home_popular_packages') }}</h2>
                <livewire:main.section-of-packages :limit="8" />
            </section>
        </div>
    @else
        {{-- Guest marketing home --}}

        <section class="mx-auto w-full max-w-7xl" data-section="homepage-marquee" aria-labelledby="homepage-marquee-heading">
            <h2 id="homepage-marquee-heading" class="sr-only">{{ __('main.homepage_marquee') }}</h2>
            <livewire:main.circular-slider />
        </section>

        <section class="mx-auto w-full max-w-7xl pb-4 pt-2 sm:pt-4" data-section="homepage-promos" aria-labelledby="homepage-promos-heading">
            <h2 id="homepage-promos-heading" class="sr-only">{{ __('main.homepage_promos') }}</h2>
            <div class="grid sm:grid md:flex lg:grid md:flex-col sm:gap-6 gap-4 sm:grid-cols-4">
                {{-- Only render hero tiles that have real destinations (no placeholder #). --}}
                @php
                    $guestHeroBanners = collect([$heroBanner, $heroBanner2])
                        ->filter(fn (array $banner): bool => filled($banner['href'] ?? null) && ($banner['href'] ?? '#') !== '#')
                        ->values()
                        ->all();
                @endphp
                @if ($guestHeroBanners !== [])
                    <div class="flex sm:flex-col md:flex-row lg:flex-col sm:gap-4 gap-2 justify-between">
                        @foreach ($guestHeroBanners as $banner)
                            <a href="{{ $banner['href'] }}"
                               class="group sm:w-full md:w-1/2 lg:w-full block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent)"
                               aria-label="{{ __('main.featured_promo') }}">
                                <div
                                    class="sm:aspect-[16/9] aspect-[15/9] w-full overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900">
                                    <img src="{{ $banner['image'] }}" alt="{{ __('main.featured_promo_banner') }}"
                                         class="h-full w-full object-cover" width="960" height="600" loading="eager" fetchpriority="high"
                                         decoding="async" />
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif

                <livewire:main.promotional-sliders />
            </div>
        </section>

        <section class="mx-auto w-full max-w-7xl" data-section="homepage-section-of-categories" aria-labelledby="homepage-categories-heading">
            <h2 id="homepage-categories-heading" class="sr-only">{{ __('main.homepage_categories') }}</h2>
            <livewire:main.section-of-categories />
        </section>

        <section id="homepage-section-of-packages" class="mx-auto w-full max-w-7xl" data-section="homepage-section-of-packages" aria-labelledby="homepage-packages-heading">
            <h2 id="homepage-packages-heading" class="sr-only">{{ __('messages.packages') }}</h2>
            <livewire:main.section-of-packages />
        </section>
    @endauth

</div>
