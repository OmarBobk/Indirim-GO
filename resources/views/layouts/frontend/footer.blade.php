<footer class="mt-10 border-t border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mx-auto w-full max-w-7xl px-3 py-10 sm:py-12">
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-col gap-1">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('main.footer_weekly_deals') }}
                    </flux:heading>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ __('main.footer_newsletter_hint') }}
                    </flux:text>
                </div>
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center lg:max-w-md" role="group" aria-label="{{ __('main.footer_newsletter_aria') }}">
                    <flux:input
                        type="email"
                        name="email"
                        autocomplete="email"
                        inputmode="email"
                        placeholder="{{ __('main.footer_email_placeholder') }}"
                        class="w-full"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        aria-label="{{ __('main.footer_email_aria') }}"
                    />
                    <flux:button variant="primary" class="shrink-0">
                        {{ __('main.footer_join') }}
                    </flux:button>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white px-3 py-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="mt-0.5 flex size-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="bolt" class="size-4 text-(--color-accent)" />
                </span>
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('main.footer_fast_delivery') }}</span>
                    <span class="text-xs text-zinc-600 dark:text-zinc-300">{{ __('main.footer_fast_delivery_hint') }}</span>
                </div>
            </div>
            <div class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white px-3 py-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="mt-0.5 flex size-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="shield-check" class="size-4 text-(--color-accent)" />
                </span>
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('main.footer_secure_payment') }}</span>
                    <span class="text-xs text-zinc-600 dark:text-zinc-300">{{ __('main.footer_secure_payment_hint') }}</span>
                </div>
            </div>
            <div class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white px-3 py-2 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <span class="mt-0.5 flex size-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <flux:icon icon="chat-bubble-left-right" class="size-4 text-(--color-accent)" />
                </span>
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ __('main.footer_support_24_7') }}</span>
                    <span class="text-xs text-zinc-600 dark:text-zinc-300">{{ __('main.footer_support_hint') }}</span>
                </div>
            </div>
        </div>

        <div class="mt-8 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex flex-col gap-5">
                <a
                    href="{{ route('home') }}"
                    class="inline-flex items-center gap-2 text-2xl font-bold leading-none text-zinc-900 transition hover:text-(--color-accent) focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-100"
                    aria-label="{{ __('main.footer_home_aria', ['name' => config('app.name')]) }}"
                >
                    <span class="text-(--color-accent)">{{ config('app.name') }}</span>
                </a>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ __('main.footer_tagline') }}
                </flux:text>
                <div class="mt-3">
                    <livewire:language-switcher />
                </div>
            </div>
            <div class="flex flex-col gap-3">
                <flux:heading class="text-zinc-900 dark:text-zinc-100">{{ __('main.footer_shopping') }}</flux:heading>
                <ul class="flex flex-col gap-2 text-sm">
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_discounts') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_new_products') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_popular_categories') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.gift_cards') }}</a>
                    </li>
                </ul>
            </div>
            <div class="flex flex-col gap-3">
                <flux:heading class="text-zinc-900 dark:text-zinc-100">{{ __('main.footer_corporate') }}</flux:heading>
                <ul class="flex flex-col gap-2 text-sm">
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_about') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_careers') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_partnership') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_press_kit') }}</a>
                    </li>
                </ul>
            </div>
            <div class="flex flex-col gap-3">
                <flux:heading class="text-zinc-900 dark:text-zinc-100">{{ __('main.footer_help') }}</flux:heading>
                <ul class="flex flex-col gap-2 text-sm">
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_faq') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_returns') }}</a>
                    </li>
                    <li>
                        <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_track_order') }}</a>
                    </li>
                    <li>
                        <a href="{{ route('contact') }}" wire:navigate class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_contact') }}</a>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-8 flex flex-col gap-4 border-t border-zinc-200 pt-6 text-sm text-zinc-600 dark:border-zinc-800 dark:text-zinc-300 sm:flex-row sm:items-center sm:justify-between">
            <p>{{ __('main.footer_copyright', ['year' => now()->year, 'name' => config('app.name')]) }}</p>
            <div class="flex flex-wrap items-center gap-4">
                <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_privacy') }}</a>
                <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_terms') }}</a>
                <a href="#" class="text-zinc-600 transition hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:text-zinc-300 dark:hover:text-white">{{ __('main.footer_cookies') }}</a>
            </div>
        </div>
    </div>
</footer>
