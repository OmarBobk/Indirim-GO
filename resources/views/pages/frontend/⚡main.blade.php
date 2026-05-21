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

<div class="flex flex-col">

    <!-- Start Circular Slider -->
    <section class="mx-auto w-full max-w-7xl" data-section="homepage-marquee" aria-labelledby="homepage-marquee-heading">
        <h2 id="homepage-marquee-heading" class="sr-only">{{ __('main.homepage_marquee') }}</h2>
        <livewire:main.circular-slider />
    </section>
    <!-- End Circular Slider -->

    <!-- Start Promotional Sliders -->
    <section class="mx-auto w-full max-w-7xl pb-4 pt-2 sm:pt-4" data-section="homepage-promos" aria-labelledby="homepage-promos-heading">
        <h2 id="homepage-promos-heading" class="sr-only">{{ __('main.homepage_promos') }}</h2>
        <div class="grid sm:grid md:flex lg:grid md:flex-col sm:gap-6 gap-4 sm:grid-cols-4">
            <div class="flex sm:flex-col md:flex-row lg:flex-col sm:gap-4 gap-2 justify-between">
                <a href="{{ $heroBanner['href'] }}"
                   class="group sm:w-full md:w-1/2 lg:w-full block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent)"
                   aria-label="{{ __('main.featured_promo') }}">
                    <div
                        class="sm:aspect-[16/9] aspect-[15/9] w-full overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900">
                        <img src="{{ $heroBanner['image'] }}" alt="{{ __('main.featured_promo_banner') }}"
                             class="h-full w-full object-cover" width="960" height="600" loading="eager" fetchpriority="high"
                             decoding="async" />
                    </div>
                </a>
                <a href="{{ $heroBanner2['href'] }}"
                   class="group sm:w-full md:w-1/2 lg:w-full block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent)"
                   aria-label="{{ __('main.featured_promo') }}">
                    <div
                        class="sm:aspect-[16/9] aspect-[15/9] w-full overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900">
                        <img src="{{ $heroBanner2['image'] }}" alt="{{ __('main.featured_promo_banner') }}"
                             class="h-full w-full object-cover" width="960" height="600" loading="eager" fetchpriority="high"
                             decoding="async" />
                    </div>
                </a>
            </div>

            <livewire:main.promotional-sliders />
        </div>
    </section>
    <!-- End Promotional Sliders -->

    <!-- Start Section of Categories -->
    <section class="mx-auto w-full max-w-7xl" data-section="homepage-section-of-categories" aria-labelledby="homepage-categories-heading">
        <h2 id="homepage-categories-heading" class="sr-only">{{ __('main.homepage_categories') }}</h2>
        <livewire:main.section-of-categories />
    </section>
    <!-- End Section of Categories -->

    <!-- Start Section of Packages -->
    <section id="homepage-section-of-packages" class="mx-auto w-full max-w-7xl" data-section="homepage-section-of-packages" aria-labelledby="homepage-packages-heading">
        <h2 id="homepage-packages-heading" class="sr-only">{{ __('messages.packages') }}</h2>
        <livewire:main.section-of-packages />
    </section>
    <!-- End Section of Packages -->

    <!-- Start Section of Products -->
{{--    <section class="mx-auto w-full max-w-7xl" data-section="homepage-section-of-products" aria-labelledby="homepage-products-heading">--}}
{{--        <h2 id="homepage-products-heading" class="sr-only">{{ __('main.homepage_products') }}</h2>--}}
{{--        <livewire:main.section-of-products />--}}
{{--    </section>--}}
    <!-- End Section of Products -->

</div>
