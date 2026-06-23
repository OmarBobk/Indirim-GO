<?php

use Livewire\Component;

new class extends Component
{
    public array $slides = [
        [
            'badge' => 'Yeni Ürünler',
            'title' => 'Premium Kablolar',
            'subtitle' => 'Hızlı şarj ve dayanıklı yapı',
            'image' => 'images/sliders/promotional-1.png',
            'href' => '#',
        ],
        [
            'badge' => 'İndirim',
            'title' => 'Powerbank Koleksiyonu',
            'subtitle' => 'Yüksek kapasiteli şarj çözümleri',
            'image' => 'images/sliders/promotional-2.png',
            'href' => '#',
        ],
        [
            'badge' => 'Popüler',
            'title' => 'Kulaklık Modelleri',
            'subtitle' => 'Kablosuz ve kablolu seçenekler',
            'image' => 'images/sliders/promotional-3.png',
            'href' => '#',
        ],
    ];
};
?>

<div
    wire:ignore
    class="sm:col-span-3 relative w-full overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800"
    x-data="{
        currentIndex: 0,
        intervalId: null,
        isPaused: false,
        sliderSpeed: 2500,
        total: {{ count($slides) }},
        isRtl: false,

        init() {
            this.isRtl = this.getDirection() === 'rtl';
            this.start();
        },
        start() {
            this.stop();
            this.intervalId = setInterval(() => {
                if (!this.isPaused) this.advance();
            }, this.sliderSpeed);
        },
        stop() {
            if (this.intervalId) { clearInterval(this.intervalId); this.intervalId = null; }
        },
        getDirection() {
            const root = this.$root.closest('[dir]');
            return root?.getAttribute('dir') ?? document.documentElement.getAttribute('dir') ?? 'ltr';
        },
        advance() {
            if (this.isRtl) {
                this.previous();
                return;
            }

            this.next();
        },
        next() { this.currentIndex = (this.currentIndex + 1) % this.total; },
        previous() { this.currentIndex = (this.currentIndex - 1 + this.total) % this.total; },
        goTo(i) { this.currentIndex = i; },
        pause() { this.isPaused = true; },
        resume() { this.isPaused = false; },
        destroy() { this.stop(); },
    }"
    x-init="init()"
    x-on:alpine:destroy="destroy()"
    @mouseenter="pause()"
    @mouseleave="resume()"
>
    {{-- Slides --}}
    <div class="relative h-full min-h-[140px] md:min-h-[350px]">
        @foreach($slides as $index => $slide)
            <div
                x-show="currentIndex === {{ $index }}"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-500"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0"
            >
                <a href="{{ $slide['href'] }}" class="block h-full">
                    <div class="relative h-full">
                        <img
                            src="{{ asset($slide['image']) }}"
                            alt="{{ $slide['title'] }}"
                            class="h-full w-full object-cover"
                            loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                            width="600"
                            height="400"
                        >
                        <div class="absolute inset-0 bg-gradient-to-t from-zinc-900/80 via-zinc-900/40 to-transparent"></div>

{{--                        <div class="absolute bottom-0 left-0 right-0 p-6 md:p-8 text-white">--}}
{{--                            <flux:badge variant="filled" class="mb-3 !bg-accent !text-accent-foreground">--}}
{{--                                {{ $slide['badge'] }}--}}
{{--                            </flux:badge>--}}

{{--                            <h2 class="text-2xl md:text-3xl font-bold mb-2">--}}
{{--                                {{ $slide['title'] }}--}}
{{--                            </h2>--}}

{{--                            <p class="text-zinc-200 text-sm md:text-base">--}}
{{--                                {{ $slide['subtitle'] }}--}}
{{--                            </p>--}}
{{--                        </div>--}}
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    {{-- Navigation --}}
{{--    <button--}}
{{--        type="button"--}}
{{--        @click="previous()"--}}
{{--        class="absolute left-4 top-1/2 -translate-y-1/2 z-10 size-10 rounded-full bg-white/90 dark:bg-zinc-800/90 backdrop-blur-sm border border-zinc-200 dark:border-zinc-700 flex items-center justify-center hover:bg-white dark:hover:bg-zinc-800 transition shadow-lg"--}}
{{--        aria-label="Önceki"--}}
{{--    >--}}
{{--        <flux:icon icon="chevron-left" class="size-5 text-zinc-700 dark:text-zinc-300" />--}}
{{--    </button>--}}

{{--    <button--}}
{{--        type="button"--}}
{{--        @click="next()"--}}
{{--        class="absolute right-4 top-1/2 -translate-y-1/2 z-10 size-10 rounded-full bg-white/90 dark:bg-zinc-800/90 backdrop-blur-sm border border-zinc-200 dark:border-zinc-700 flex items-center justify-center hover:bg-white dark:hover:bg-zinc-800 transition shadow-lg"--}}
{{--        aria-label="Sonraki"--}}
{{--    >--}}
{{--        <flux:icon icon="chevron-right" class="size-5 text-zinc-700 dark:text-zinc-300" />--}}
{{--    </button>--}}

    {{-- Dots --}}
    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 z-10 flex gap-2">
        @foreach($slides as $index => $_)
            <button
                type="button"
                @click="goTo({{ $index }})"
                class="size-2 rounded-full transition-all"
                x-bind:class="currentIndex === {{ $index }}
                    ? 'bg-accent w-6'
                    : 'bg-white/50 hover:bg-white/75'"
                aria-label="{{ __('main.slide_number', ['number' => $index + 1]) }}"
            ></button>
        @endforeach
    </div>
</div>
